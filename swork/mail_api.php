<?php
date_default_timezone_set('Asia/Tokyo');

function swork_json($status, $data) {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function swork_clean_line($value) {
    return trim(str_replace(array("\r", "\n"), ' ', (string)$value));
}

function swork_random_hex($length = 16) {
    if (function_exists('random_bytes')) return bin2hex(random_bytes($length));
    if (function_exists('openssl_random_pseudo_bytes')) return bin2hex(openssl_random_pseudo_bytes($length));
    $bytes = '';
    for ($i = 0; $i < $length; $i++) $bytes .= chr(mt_rand(0, 255));
    return bin2hex($bytes);
}

function swork_env_file() {
    $candidates = array(
        __DIR__ . '/.env',
        dirname(__DIR__) . '/work/swork.env',
        dirname(__DIR__) . '/.env',
    );
    foreach ($candidates as $file) {
        if (is_file($file)) return $file;
    }
    return '';
}

function swork_load_env() {
    $env = array();
    $file = swork_env_file();
    if ($file === '') return $env;
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $env[trim($key)] = trim($value, " \t\n\r\0\x0B\"'");
    }
    return $env;
}

function swork_request_json() {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    if (is_array($data)) return $data;
    return $_POST;
}

function swork_header_token() {
    $headers = function_exists('getallheaders') ? getallheaders() : array();
    foreach ($headers as $key => $value) {
        if (strtolower($key) === 'x-swork-token') return trim($value);
        if (strtolower($key) === 'authorization' && preg_match('/Bearer\s+(.+)/i', $value, $m)) return trim($m[1]);
    }
    return isset($_GET['token']) ? trim((string)$_GET['token']) : '';
}

function swork_send_mail($to, $subject, $body, $from, $reply_to) {
    $headers = implode("\r\n", array(
        'From: ' . $from,
        'Reply-To: ' . $reply_to,
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        'X-Mailer: SWork Mail API',
    ));
    $encoded_subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encoded_body = chunk_split(base64_encode($body));
    return mail($to, $encoded_subject, $encoded_body, $headers);
}

function swork_log_dir() {
    return dirname(__DIR__) . '/work/swork_mail';
}

function swork_save_log($record) {
    $dir = swork_log_dir();
    if (!is_dir($dir)) mkdir($dir, 0755, true);
    $id = date('YmdHis') . '-' . swork_random_hex(4);
    $record['id'] = $id;
    $record['created_at'] = date('Y-m-d H:i:s');
    file_put_contents($dir . '/mail_' . $id . '.json', json_encode($record, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX);
    return $id;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') swork_json(405, array('ok' => false, 'error' => 'POST only'));

$env = swork_load_env();
$expected = isset($env['SWORK_API_TOKEN']) ? $env['SWORK_API_TOKEN'] : '';
if ($expected === '') swork_json(500, array('ok' => false, 'error' => 'SWORK_API_TOKEN is not configured'));

$token = swork_header_token();
if ($token === '' || !hash_equals($expected, $token)) swork_json(403, array('ok' => false, 'error' => 'invalid token'));

$data = swork_request_json();
$to = swork_clean_line(isset($data['to']) ? $data['to'] : '');
$subject = swork_clean_line(isset($data['subject']) ? $data['subject'] : '');
$body = isset($data['body']) ? trim((string)$data['body']) : '';
$from = swork_clean_line(isset($data['from']) ? $data['from'] : (isset($env['SWORK_MAIL_ADDRESS']) ? $env['SWORK_MAIL_ADDRESS'] : 'sales@exbridge.jp'));
$reply_to = swork_clean_line(isset($data['reply_to']) ? $data['reply_to'] : $from);

if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) swork_json(400, array('ok' => false, 'error' => 'invalid to'));
if ($subject === '') swork_json(400, array('ok' => false, 'error' => 'subject required'));
if ($body === '') swork_json(400, array('ok' => false, 'error' => 'body required'));
if (!filter_var($from, FILTER_VALIDATE_EMAIL)) swork_json(400, array('ok' => false, 'error' => 'invalid from'));
if (!filter_var($reply_to, FILTER_VALIDATE_EMAIL)) swork_json(400, array('ok' => false, 'error' => 'invalid reply_to'));

$dry_run = !empty($data['dry_run']);
$record = array(
    'to' => $to,
    'subject' => $subject,
    'body' => $body,
    'from' => $from,
    'reply_to' => $reply_to,
    'dry_run' => $dry_run,
    'ip' => isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '',
    'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '',
);

if ($dry_run) {
    $id = swork_save_log($record);
    swork_json(200, array('ok' => true, 'dry_run' => true, 'id' => $id));
}

$ok = swork_send_mail($to, $subject, $body, $from, $reply_to);
$record['sent'] = $ok ? true : false;
$id = swork_save_log($record);
if (!$ok) swork_json(500, array('ok' => false, 'error' => 'mail failed', 'id' => $id));
swork_json(200, array('ok' => true, 'id' => $id));
