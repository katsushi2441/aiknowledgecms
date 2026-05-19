<?php
require_once dirname(__DIR__) . '/auth_common.php';
date_default_timezone_set('Asia/Tokyo');

url2ai_auth_handle_login_flow('/swork/index.php');
$auth = url2ai_auth_bootstrap();
$logged_in = $auth['logged_in'];
$username = $auth['session_user'];
$is_admin = $auth['is_admin'];

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function swork_data_dir() {
    $dir = dirname(__DIR__) . '/work/swork';
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    return $dir;
}

function swork_json_file($name) {
    return swork_data_dir() . '/' . $name . '.json';
}

function swork_read_json($name) {
    $file = swork_json_file($name);
    if (!is_file($file)) return array();
    $data = json_decode(file_get_contents($file), true);
    return is_array($data) ? $data : array();
}

function swork_write_json($name, $data) {
    file_put_contents(swork_json_file($name), json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
}

function swork_csrf_token() {
    if (empty($_SESSION['swork_csrf'])) $_SESSION['swork_csrf'] = swork_random_hex(16);
    return $_SESSION['swork_csrf'];
}

function swork_random_hex($length) {
    if (function_exists('random_bytes')) return bin2hex(random_bytes($length));
    if (function_exists('openssl_random_pseudo_bytes')) return bin2hex(openssl_random_pseudo_bytes($length));
    $bytes = '';
    for ($i = 0; $i < $length; $i++) $bytes .= chr(mt_rand(0, 255));
    return bin2hex($bytes);
}

function swork_check_csrf() {
    return isset($_POST['csrf'], $_SESSION['swork_csrf']) && hash_equals($_SESSION['swork_csrf'], $_POST['csrf']);
}

function swork_leads_file() {
    $candidates = array(__DIR__ . '/leads.csv', dirname(__DIR__) . '/work/swork_leads.csv');
    foreach ($candidates as $file) if (is_file($file)) return $file;
    return '';
}

function swork_load_csv_leads() {
    $file = swork_leads_file();
    if ($file === '') return array();
    $fp = fopen($file, 'r');
    if (!$fp) return array();
    $header = fgetcsv($fp);
    if (!$header) return array();
    $items = array();
    while (($row = fgetcsv($fp)) !== false) {
        $item = array();
        foreach ($header as $i => $key) $item[$key] = isset($row[$i]) ? $row[$i] : '';
        if (!empty($item['id'])) $items[] = $item;
    }
    fclose($fp);
    return $items;
}

function swork_merge_overrides($leads) {
    $overrides = swork_read_json('lead_overrides');
    foreach ($leads as &$lead) {
        $id = isset($lead['id']) ? $lead['id'] : '';
        if ($id !== '' && isset($overrides[$id]) && is_array($overrides[$id])) {
            $lead = array_merge($lead, $overrides[$id]);
        }
    }
    unset($lead);
    return $leads;
}

function swork_find_lead($leads, $id) {
    foreach ($leads as $lead) if (isset($lead['id']) && $lead['id'] === $id) return $lead;
    return count($leads) ? $leads[0] : array();
}

function swork_clean_url($url) {
    $url = trim((string)$url);
    if ($url === '') return '';
    if (!preg_match('#^https?://#i', $url)) $url = 'https://' . $url;
    return filter_var($url, FILTER_VALIDATE_URL) ? $url : '';
}

function swork_filter_leads($leads, $q, $status, $form_filter) {
    $out = array();
    foreach ($leads as $lead) {
        $has_form = !empty($lead['contact_form_url']);
        if ($form_filter === 'has_form' && !$has_form) continue;
        if ($form_filter === 'no_form' && $has_form) continue;
        if ($status !== '' && (isset($lead['status']) ? $lead['status'] : '') !== $status) continue;
        if ($q !== '') {
            $haystack = implode(' ', $lead);
            if (mb_stripos($haystack, $q, 0, 'UTF-8') === false) continue;
        }
        $out[] = $lead;
    }
    return $out;
}

function swork_subject($lead) {
    return 'バイブコーディングによる手入力削減・システム内製化のご提案';
}

function swork_body($lead) {
    $company = isset($lead['company_name']) ? $lead['company_name'] : '';
    $hypothesis = isset($lead['hypothesis']) ? $lead['hypothesis'] : '';
    return $company . "\nご担当者様\n\n突然のご連絡失礼いたします。株式会社エクスブリッジです。\n\n弊社では、バイブコーディングを活用して、FAX発注書、見積依頼、型番入力、商品マスタ照合など、パソコン上の手入力作業を減らすシステム開発を支援しています。\n\n" . $hypothesis . "\n\n既存SaaSを契約し続ける形ではなく、業務に合わせた仕組みを自社資産として持ち、社内で育てていけることを重視しています。\n\nバイブコーディングセミナーとVWorkの導入支援により、外部委託に頼りきらない業務改善・システム内製化も支援できます。\n\nもしご関心がありましたら、短時間で現状業務を伺い、どこを自動化できるか整理いたします。\n\n今後のご案内が不要な場合は、その旨ご返信ください。\n\n株式会社エクスブリッジ\nsales@exbridge.jp\nhttps://exbridge.jp/";
}

function swork_save_lead_update($id) {
    $overrides = swork_read_json('lead_overrides');
    $current = isset($overrides[$id]) && is_array($overrides[$id]) ? $overrides[$id] : array();
    $fields = array('contact_form_url', 'email', 'status', 'next_action', 'notes');
    foreach ($fields as $field) {
        $value = isset($_POST[$field]) ? trim((string)$_POST[$field]) : '';
        if ($field === 'contact_form_url') $value = swork_clean_url($value);
        $current[$field] = $value;
    }
    $current['updated_at'] = date('Y-m-d H:i:s');
    $overrides[$id] = $current;
    swork_write_json('lead_overrides', $overrides);
}

function swork_add_activity($lead, $kind) {
    $log = swork_read_json('activities');
    $log[] = array(
        'id' => date('YmdHis') . '-' . substr(sha1(uniqid('', true)), 0, 8),
        'lead_id' => $lead['id'],
        'company_name' => isset($lead['company_name']) ? $lead['company_name'] : '',
        'kind' => $kind,
        'result' => isset($_POST['result']) ? trim((string)$_POST['result']) : '',
        'memo' => isset($_POST['activity_memo']) ? trim((string)$_POST['activity_memo']) : '',
        'created_at' => date('Y-m-d H:i:s'),
        'created_by' => isset($_SESSION['session_username']) ? $_SESSION['session_username'] : '',
    );
    swork_write_json('activities', $log);

    $overrides = swork_read_json('lead_overrides');
    $id = $lead['id'];
    $current = isset($overrides[$id]) && is_array($overrides[$id]) ? $overrides[$id] : array();
    $current['last_contacted_at'] = date('Y-m-d H:i:s');
    if ($kind === 'form_sent') $current['status'] = 'sent';
    if ($kind === 'form_ready') $current['status'] = 'form_ready';
    $overrides[$id] = $current;
    swork_write_json('lead_overrides', $overrides);
}

$notice = '';
$error = '';
$all_leads = swork_merge_overrides(swork_load_csv_leads());

if ($logged_in && $is_admin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!swork_check_csrf()) {
        $error = 'Invalid session token';
    } else {
        $post_id = isset($_POST['lead_id']) ? (string)$_POST['lead_id'] : '';
        $post_lead = swork_find_lead($all_leads, $post_id);
        if (!$post_lead) {
            $error = 'Lead not found';
        } elseif (isset($_POST['action']) && $_POST['action'] === 'save_lead') {
            swork_save_lead_update($post_id);
            $notice = '保存しました';
        } elseif (isset($_POST['action']) && in_array($_POST['action'], array('form_ready', 'form_sent'), true)) {
            swork_add_activity($post_lead, $_POST['action']);
            $notice = '記録しました';
        }
        $all_leads = swork_merge_overrides(swork_load_csv_leads());
    }
}

$query = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$status_filter = isset($_GET['status']) ? trim((string)$_GET['status']) : '';
$form_filter = isset($_GET['form']) ? trim((string)$_GET['form']) : 'has_form';
$leads = swork_filter_leads($all_leads, $query, $status_filter, $form_filter);
$selected_id = isset($_GET['id']) ? (string)$_GET['id'] : (isset($_POST['lead_id']) ? (string)$_POST['lead_id'] : '');
$selected = $selected_id !== '' ? swork_find_lead($all_leads, $selected_id) : (count($leads) ? $leads[0] : array());
$form_url = isset($selected['contact_form_url']) && $selected['contact_form_url'] !== '' ? $selected['contact_form_url'] : '';
$site_url = isset($selected['website_url']) ? $selected['website_url'] : '';
$subject = $selected ? swork_subject($selected) : '';
$body = $selected ? swork_body($selected) : '';
$statuses = array('new', 'researched', 'drafted', 'form_ready', 'sent', 'replied', 'meeting', 'not_interested', 'invalid');
$form_ready_count = 0;
foreach ($all_leads as $lead) if (!empty($lead['contact_form_url'])) $form_ready_count++;
$copy_payload = array(
    '会社名' => isset($selected['company_name']) ? $selected['company_name'] : '',
    '名前' => 'ご担当者様',
    'メール' => 'sales@exbridge.jp',
    '件名' => $subject,
    '本文' => $body,
);
$assistant_url = $selected && !empty($selected['id']) ? 'http://127.0.0.1:8765/fill?lead_id=' . rawurlencode($selected['id']) : '';
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SWork</title>
<style>
body{margin:0;background:#f3f5f4;color:#111;font-family:-apple-system,BlinkMacSystemFont,"Hiragino Sans",Meiryo,sans-serif;line-height:1.55}.top{background:#fff;border-bottom:1px solid #dce2df}.wrap{max-width:1360px;margin:0 auto;padding:16px}.bar{display:flex;align-items:center;justify-content:space-between;gap:12px}.brand{font-weight:800;color:#111;text-decoration:none}.nav,.tools,.row{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:34px;padding:6px 10px;border:1px solid #c7d0cc;background:#fff;border-radius:4px;color:#111;text-decoration:none;cursor:pointer;font-size:13px;white-space:nowrap}.primary{background:#0f766e;color:#fff;border-color:#0f766e}.danger{background:#fff1f2;border-color:#fecdd3}.grid{display:grid;grid-template-columns:minmax(430px,.95fr) minmax(520px,1.15fr);gap:14px}.panel{background:#fff;border:1px solid #d7dfdb;border-radius:6px;overflow:hidden}.panel-h{padding:11px 13px;border-bottom:1px solid #e8eeeb;display:flex;align-items:center;justify-content:space-between;gap:10px}.panel-b{padding:13px}.search{display:grid;grid-template-columns:1fr 130px 130px auto;gap:8px}.input,select,textarea{box-sizing:border-box;border:1px solid #c7d0cc;border-radius:4px;background:#fff;color:#111;font:inherit;font-size:13px}.input,select{min-height:34px;padding:6px 8px}textarea{width:100%;padding:9px;line-height:1.6}.table-wrap{overflow:auto;max-height:74vh}table{width:100%;border-collapse:collapse;font-size:13px}th,td{padding:8px 9px;border-bottom:1px solid #edf1ef;text-align:left;vertical-align:top}th{position:sticky;top:0;background:#fbfcfc;z-index:1}.company{font-weight:800}.muted{color:#66706b;font-size:12px}.tag{display:inline-flex;padding:2px 7px;border:1px solid #d7dfdb;border-radius:999px;font-size:11px;color:#44504a;background:#f8faf9}.selected{background:#ecfdf5}.detail{display:grid;gap:12px}.kv{display:grid;grid-template-columns:110px 1fr;gap:8px;font-size:13px}.copybox{min-height:150px}.subject{width:100%}.empty{padding:18px;color:#66706b}.ok{background:#ecfdf5;border:1px solid #a7f3d0;padding:9px;border-radius:4px}.err{background:#fff1f2;border:1px solid #fecdd3;padding:9px;border-radius:4px}.frame{width:100%;height:48vh;border:1px solid #c7d0cc;border-radius:4px;background:#fff}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}.full{grid-column:1/-1}.field-copy{display:grid;grid-template-columns:90px 1fr auto;gap:8px;align-items:center}.mini{font-size:12px}@media(max-width:960px){.grid,.search,.form-grid{grid-template-columns:1fr}.table-wrap{max-height:none}.frame{height:58vh}.field-copy{grid-template-columns:1fr}}
</style>
</head>
<body>
<header class="top"><div class="wrap bar"><a class="brand" href="index.php">SWork</a><div class="nav"><a class="btn" href="inbox.php">Inbox</a><?php if($logged_in): ?>@<?php echo h($username); ?> <a class="btn" href="<?php echo h($auth['logout_url']); ?>">logout</a><?php else: ?><a class="btn primary" href="<?php echo h($auth['login_url']); ?>">Xでログイン</a><?php endif; ?></div></div></header>
<main class="wrap">
<?php if(!$logged_in): ?>
<div class="panel"><div class="empty">X認証でログインしてください。</div></div>
<?php elseif(!$is_admin): ?>
<div class="panel"><div class="empty">管理者のみ閲覧できます。</div></div>
<?php else: ?>
<?php if($notice): ?><div class="ok"><?php echo h($notice); ?></div><?php endif; ?>
<?php if($error): ?><div class="err"><?php echo h($error); ?></div><?php endif; ?>
<div class="grid">
<section class="panel">
  <div class="panel-h"><strong>ターゲット顧客リスト</strong><span class="muted">フォームあり <?php echo h($form_ready_count); ?>件 / 表示 <?php echo h(count($leads)); ?>件</span></div>
  <div class="panel-b">
    <form class="search" method="get">
      <input class="input" name="q" value="<?php echo h($query); ?>" placeholder="会社名・住所・業種で検索">
      <select name="status"><option value="">全ステータス</option><?php foreach($statuses as $st): ?><option value="<?php echo h($st); ?>"<?php echo $status_filter === $st ? ' selected' : ''; ?>><?php echo h($st); ?></option><?php endforeach; ?></select>
      <select name="form"><option value="has_form"<?php echo $form_filter === 'has_form' ? ' selected' : ''; ?>>フォームあり</option><option value=""<?php echo $form_filter === '' ? ' selected' : ''; ?>>すべて</option><option value="no_form"<?php echo $form_filter === 'no_form' ? ' selected' : ''; ?>>フォームなし</option></select>
      <button class="btn primary">検索</button>
    </form>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>会社</th><th>連絡先</th><th>状態</th><th></th></tr></thead>
      <tbody>
      <?php foreach($leads as $lead): $is_sel = isset($selected['id'], $lead['id']) && $selected['id'] === $lead['id']; ?>
      <tr class="<?php echo $is_sel ? 'selected' : ''; ?>">
        <td><div class="company"><?php echo h($lead['company_name']); ?></div><div class="muted"><?php echo h($lead['branch']); ?> / <?php echo h($lead['address']); ?></div></td>
        <td><div><?php echo h(isset($lead['phone']) ? $lead['phone'] : ''); ?></div><div class="muted"><?php echo h(isset($lead['website_url']) ? $lead['website_url'] : ''); ?></div></td>
        <td><span class="tag"><?php echo h(isset($lead['status']) ? $lead['status'] : 'new'); ?></span><div class="muted"><?php echo h(isset($lead['last_contacted_at']) ? $lead['last_contacted_at'] : ''); ?></div></td>
        <td><a class="btn" href="?id=<?php echo urlencode($lead['id']); ?><?php echo $query !== '' ? '&q=' . urlencode($query) : ''; ?><?php echo $status_filter !== '' ? '&status=' . urlencode($status_filter) : ''; ?><?php echo $form_filter !== '' ? '&form=' . urlencode($form_filter) : ''; ?>">選択</a></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="panel">
  <div class="panel-h">
    <strong><?php echo h(isset($selected['company_name']) ? $selected['company_name'] : '詳細'); ?></strong>
    <div class="tools">
      <?php if($site_url): ?><a class="btn" href="<?php echo h($site_url); ?>" target="_blank" rel="noopener">公式サイト</a><?php endif; ?>
      <?php if($form_url): ?><a class="btn primary" href="<?php echo h($form_url); ?>" target="_blank" rel="noopener">フォームを別タブで開く</a><?php endif; ?>
      <?php if($form_url): ?><a class="btn primary" href="<?php echo h($assistant_url); ?>" target="_blank" rel="noopener">フォーム自動入力</a><?php endif; ?>
    </div>
  </div>
  <div class="panel-b detail">
    <?php if(!$selected): ?>
    <div class="empty">リードCSVがありません。</div>
    <?php else: ?>
    <form method="post" class="detail">
      <input type="hidden" name="csrf" value="<?php echo h(swork_csrf_token()); ?>">
      <input type="hidden" name="lead_id" value="<?php echo h($selected['id']); ?>">
      <input type="hidden" name="action" value="save_lead">
      <div class="form-grid">
        <label>問い合わせフォームURL<input class="input full" name="contact_form_url" value="<?php echo h($form_url); ?>"></label>
        <label>メール<input class="input" name="email" value="<?php echo h(isset($selected['email']) ? $selected['email'] : ''); ?>"></label>
        <label>ステータス<select name="status"><?php foreach($statuses as $st): ?><option value="<?php echo h($st); ?>"<?php echo (isset($selected['status']) && $selected['status'] === $st) ? ' selected' : ''; ?>><?php echo h($st); ?></option><?php endforeach; ?></select></label>
        <label>次アクション<input class="input" name="next_action" value="<?php echo h(isset($selected['next_action']) ? $selected['next_action'] : ''); ?>"></label>
        <label class="full">メモ<textarea name="notes" rows="3"><?php echo h(isset($selected['notes']) ? $selected['notes'] : ''); ?></textarea></label>
      </div>
      <div class="row"><button class="btn primary">保存</button><span class="muted">CSVは直接書き換えず、上書き情報として保存します。</span></div>
    </form>

    <div class="kv"><div class="muted">課題仮説</div><div><?php echo h(isset($selected['hypothesis']) ? $selected['hypothesis'] : ''); ?></div></div>

    <div class="detail">
      <strong>フォーム入力用データ</strong>
      <?php foreach($copy_payload as $label => $value): ?>
      <div class="field-copy"><span class="muted"><?php echo h($label); ?></span><input class="input" value="<?php echo h($value); ?>" readonly><button class="btn mini" type="button" onclick="copyText(this)">コピー</button></div>
      <?php endforeach; ?>
      <textarea class="copybox" id="body"><?php echo h($body); ?></textarea>
      <div class="tools"><button class="btn" type="button" onclick="copyMainBody()">本文コピー</button><button class="btn" type="button" onclick="copyAllFields()">全項目コピー</button></div>
    </div>

    <form method="post" class="row">
      <input type="hidden" name="csrf" value="<?php echo h(swork_csrf_token()); ?>">
      <input type="hidden" name="lead_id" value="<?php echo h($selected['id']); ?>">
      <input type="hidden" name="result" value="フォーム入力準備">
      <input class="input" name="activity_memo" placeholder="記録メモ">
      <button class="btn" name="action" value="form_ready">入力準備を記録</button>
      <button class="btn primary" name="action" value="form_sent">送信済みとして記録</button>
    </form>

    <?php if($form_url): ?>
    <div class="ok">フォーム自動入力を使う前に、ローカルで <code>python3 scripts/form_assistant_server.py</code> を起動してください。</div>
    <iframe class="frame" src="<?php echo h($form_url); ?>"></iframe>
    <div class="muted">iframe表示できないサイトは「フォームを別タブで開く」を使ってください。</div>
    <?php else: ?>
    <div class="empty">問い合わせフォームURLを登録してください。公式サイトを開いてフォームURLを確認できます。</div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</section>
</div>
<?php endif; ?>
</main>
<script>
function copyValue(value){
  if (navigator.clipboard) return navigator.clipboard.writeText(value);
  const t = document.createElement('textarea');
  t.value = value;
  document.body.appendChild(t);
  t.select();
  document.execCommand('copy');
  document.body.removeChild(t);
}
function copyText(btn){
  const input = btn.parentNode.querySelector('input');
  if (input) copyValue(input.value);
}
function copyMainBody(){
  const body = document.getElementById('body');
  if (body) copyValue(body.value);
}
function copyAllFields(){
  const rows = Array.from(document.querySelectorAll('.field-copy')).map(row => {
    const label = row.querySelector('span').textContent;
    const input = row.querySelector('input').value;
    return label + ': ' + input;
  });
  copyValue(rows.join("\n"));
}
</script>
</body>
</html>
