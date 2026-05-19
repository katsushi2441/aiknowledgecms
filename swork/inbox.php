<?php
require_once dirname(__DIR__) . '/auth_common.php';
date_default_timezone_set('Asia/Tokyo');

url2ai_auth_handle_login_flow('/swork/inbox.php');
$auth = url2ai_auth_bootstrap();
$logged_in = $auth['logged_in'];
$username = $auth['session_user'];
$is_admin = $auth['is_admin'];

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function swork_env_file() {
    $candidates = array(__DIR__ . '/.env', dirname(__DIR__) . '/work/swork.env');
    foreach ($candidates as $file) if (is_file($file)) return $file;
    return '';
}

function swork_load_env() {
    $env = array();
    $file = swork_env_file();
    if ($file === '') return $env;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        list($k, $v) = explode('=', $line, 2);
        $env[trim($k)] = trim($v, " \t\n\r\0\x0B\"'");
    }
    return $env;
}

function inbox_dir() {
    return dirname(__DIR__) . '/work/swork_inbox';
}

function ensure_inbox_dir() {
    $dir = inbox_dir();
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return $dir;
}

function decode_header_text($value) {
    if (function_exists('mb_decode_mimeheader')) return mb_decode_mimeheader($value);
    return $value;
}

function parse_headers_text($headers) {
    $out = array();
    $current = '';
    foreach (preg_split("/\r?\n/", $headers) as $line) {
        if (preg_match('/^\s+/', $line) && $current !== '') {
            $out[$current] .= ' ' . trim($line);
            continue;
        }
        if (strpos($line, ':') !== false) {
            list($key, $value) = explode(':', $line, 2);
            $current = strtolower(trim($key));
            $out[$current] = trim($value);
        }
    }
    return $out;
}

function pop3_read_line($fp) {
    $line = fgets($fp, 8192);
    if ($line === false) throw new Exception('POP3 read failed');
    return rtrim($line, "\r\n");
}

function pop3_cmd($fp, $cmd) {
    fwrite($fp, $cmd . "\r\n");
    $line = pop3_read_line($fp);
    if (strpos($line, '+OK') !== 0) throw new Exception('POP3 error: ' . $line);
    return $line;
}

function pop3_multiline($fp, $cmd) {
    pop3_cmd($fp, $cmd);
    $lines = array();
    while (!feof($fp)) {
        $line = pop3_read_line($fp);
        if ($line === '.') break;
        if (isset($line[0]) && $line[0] === '.') $line = substr($line, 1);
        $lines[] = $line;
    }
    return implode("\r\n", $lines);
}

function message_id_hash($headers, $fallback) {
    $id = isset($headers['message-id']) ? $headers['message-id'] : $fallback;
    return sha1($id);
}

function save_message_record($record) {
    $dir = ensure_inbox_dir();
    $file = $dir . '/mail_' . $record['id'] . '.json';
    if (file_exists($file)) return false;
    file_put_contents($file, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    return true;
}

function fetch_pop_messages($limit = 30) {
    $env = swork_load_env();
    $host = isset($env['SWORK_POP_HOST']) ? $env['SWORK_POP_HOST'] : 'pop.hetemail.jp';
    $port = isset($env['SWORK_POP_PORT']) ? (int)$env['SWORK_POP_PORT'] : 995;
    $user = isset($env['SWORK_POP_USER']) ? $env['SWORK_POP_USER'] : (isset($env['SWORK_MAIL_ADDRESS']) ? $env['SWORK_MAIL_ADDRESS'] : 'sales@exbridge.jp');
    $pass = isset($env['SWORK_POP_PASSWORD']) ? $env['SWORK_POP_PASSWORD'] : (isset($env['SWORK_MAIL_PASSWORD']) ? $env['SWORK_MAIL_PASSWORD'] : '');
    if ($pass === '') throw new Exception('POP password is not configured');

    $fp = stream_socket_client('ssl://' . $host . ':' . $port, $errno, $errstr, 30);
    if (!$fp) throw new Exception('POP connect failed: ' . $errstr);
    pop3_read_line($fp);
    pop3_cmd($fp, 'USER ' . $user);
    pop3_cmd($fp, 'PASS ' . $pass);
    $stat = pop3_cmd($fp, 'STAT');
    preg_match('/\+OK\s+(\d+)/', $stat, $m);
    $count = isset($m[1]) ? (int)$m[1] : 0;
    $start = max(1, $count - $limit + 1);
    $saved = 0;
    for ($i = $count; $i >= $start; $i--) {
        $headers_text = pop3_multiline($fp, 'TOP ' . $i . ' 0');
        $headers = parse_headers_text($headers_text);
        $id = message_id_hash($headers, $user . ':' . $i . ':' . (isset($headers['date']) ? $headers['date'] : ''));
        $raw = pop3_multiline($fp, 'RETR ' . $i);
        $body = preg_replace('/^.*?\r?\n\r?\n/s', '', $raw);
        $record = array(
            'id' => $id,
            'pop_index' => $i,
            'from' => decode_header_text(isset($headers['from']) ? $headers['from'] : ''),
            'to' => decode_header_text(isset($headers['to']) ? $headers['to'] : ''),
            'subject' => decode_header_text(isset($headers['subject']) ? $headers['subject'] : ''),
            'date' => isset($headers['date']) ? $headers['date'] : '',
            'message_id' => isset($headers['message-id']) ? $headers['message-id'] : '',
            'body_preview' => mb_substr(trim(strip_tags($body)), 0, 1200, 'UTF-8'),
            'fetched_at' => date('Y-m-d H:i:s'),
        );
        if (save_message_record($record)) $saved++;
    }
    pop3_cmd($fp, 'QUIT');
    fclose($fp);
    return array('count' => $count, 'saved' => $saved);
}

function load_messages() {
    $dir = ensure_inbox_dir();
    $files = glob($dir . '/mail_*.json');
    if (!$files) return array();
    rsort($files);
    $items = array();
    foreach ($files as $file) {
        $json = json_decode(file_get_contents($file), true);
        if (is_array($json)) $items[] = $json;
    }
    return $items;
}

$notice = '';
$error = '';
if ($logged_in && $is_admin && isset($_POST['fetch'])) {
    try {
        $res = fetch_pop_messages(50);
        $notice = '受信確認完了: mailbox=' . $res['count'] . ' / new=' . $res['saved'];
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}
$messages = ($logged_in && $is_admin) ? load_messages() : array();
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SWork Inbox</title>
<style>
body{margin:0;background:#f5f6f6;color:#111;font-family:-apple-system,BlinkMacSystemFont,"Hiragino Sans",Meiryo,sans-serif;line-height:1.7}.wrap{max-width:1100px;margin:0 auto;padding:24px 16px}.top{background:#fff;border-bottom:1px solid #ddd}.bar{display:flex;align-items:center;justify-content:space-between;gap:12px}.brand{font-weight:800;text-decoration:none;color:#111}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:36px;padding:7px 12px;border:1px solid #ccc;background:#fff;border-radius:4px;color:#111;text-decoration:none;cursor:pointer}.primary{background:#0f766e;color:#fff;border-color:#0f766e}.panel{background:#fff;border:1px solid #ddd;border-radius:6px;padding:18px;margin-top:18px}.msg{border-top:1px solid #eee;padding:14px 0}.msg:first-child{border-top:0}.subject{font-weight:800}.meta{font-size:12px;color:#666}.preview{white-space:pre-wrap;font-size:13px;background:#fafafa;border:1px solid #eee;border-radius:4px;padding:10px;margin-top:8px}.ok{background:#ecfdf5;border:1px solid #a7f3d0;padding:10px}.err{background:#fff1f2;border:1px solid #fecdd3;padding:10px}
</style>
</head>
<body>
<header class="top"><div class="wrap bar"><a class="brand" href="inbox.php">SWork Inbox</a><div><?php if($logged_in): ?>@<?php echo h($username); ?> <a class="btn" href="<?php echo h($auth['logout_url']); ?>">logout</a><?php else: ?><a class="btn primary" href="<?php echo h($auth['login_url']); ?>">Xでログイン</a><?php endif; ?></div></div></header>
<main class="wrap">
<h1>sales@exbridge.jp 受信箱</h1>
<?php if(!$logged_in): ?>
<div class="panel">X認証でログインしてください。</div>
<?php elseif(!$is_admin): ?>
<div class="panel">管理者のみ閲覧できます。</div>
<?php else: ?>
<?php if($notice): ?><div class="ok"><?php echo h($notice); ?></div><?php endif; ?>
<?php if($error): ?><div class="err"><?php echo h($error); ?></div><?php endif; ?>
<form method="post"><button class="btn primary" name="fetch" value="1">POP受信する</button></form>
<section class="panel">
<h2>受信メール</h2>
<?php if(!$messages): ?><p>まだ保存されたメールはありません。</p><?php endif; ?>
<?php foreach($messages as $m): ?>
<article class="msg">
  <div class="subject"><?php echo h(isset($m['subject']) ? $m['subject'] : '(no subject)'); ?></div>
  <div class="meta">From: <?php echo h(isset($m['from']) ? $m['from'] : ''); ?> / Date: <?php echo h(isset($m['date']) ? $m['date'] : ''); ?> / fetched: <?php echo h(isset($m['fetched_at']) ? $m['fetched_at'] : ''); ?></div>
  <div class="preview"><?php echo h(isset($m['body_preview']) ? $m['body_preview'] : ''); ?></div>
</article>
<?php endforeach; ?>
</section>
<?php endif; ?>
</main>
</body>
</html>
