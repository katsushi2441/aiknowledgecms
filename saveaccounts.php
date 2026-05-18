<?php
// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
// saveaccounts.php
// Python(zenn_collect.py)からPOSTを受け取り
// Ollamaでキーワード生成 → keyword_*.json保存
// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq

$data_dir     = __DIR__ . '/data/';
$ollama_url   = 'https://exbridge.ddns.net/api/generate';
$ollama_model = 'gemma3:12b';
define('SAVE_API_TOKEN', 'AIKNOWLEDGE_SAVE_TOKEN_HERE');  // zenn_collect.pyと合わせる

header('Content-Type: application/json; charset=UTF-8');

if (!is_dir($data_dir)) { mkdir($data_dir, 0755, true); }

// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
// 認証チェック
// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
$token = isset($_SERVER['HTTP_X_SAVE_TOKEN']) ? $_SERVER['HTTP_X_SAVE_TOKEN'] : '';
if ($token !== SAVE_API_TOKEN) {
    http_response_code(401);
    echo json_encode(array('ok' => false, 'reason' => 'unauthorized'));
    exit;
}

// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
// リクエスト読み込み
// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(array('ok' => false, 'reason' => 'method_not_allowed'));
    exit;
}

$raw = file_get_contents('php://input');
$req = json_decode($raw, true);
if (!$req) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'reason' => 'invalid_json'));
    exit;
}

$twitter     = isset($req['twitter'])     ? trim($req['twitter'])     : '';
$bio         = isset($req['bio'])         ? trim($req['bio'])         : '';
$name        = isset($req['name'])        ? trim($req['name'])        : '';
$tags        = isset($req['tags'])        ? $req['tags']              : array();
$zenn_source = isset($req['zenn_source']) ? $req['zenn_source']       : array();

if (!$twitter || !preg_match('/^[a-zA-Z0-9_]{1,50}$/', $twitter)) {
    http_response_code(400);
    echo json_encode(array('ok' => false, 'reason' => 'invalid_twitter'));
    exit;
}

// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
// ユーティリティ
// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
function ollama_generate($prompt, $ollama_url, $model) {
    $payload = json_encode(array('model' => $model, 'prompt' => $prompt, 'stream' => false));
    $opts = array('http' => array(
        'method'        => 'POST',
        'header'        => "Content-Type: application/json\r\n",
        'content'       => $payload,
        'timeout'       => 60,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents($ollama_url, false, stream_context_create($opts));
    if (!$res) { return ''; }
    $data = json_decode($res, true);
    return isset($data['response']) ? $data['response'] : '';
}

function parse_json_from_text($text) {
    $text = preg_replace('/```json|```/', '', $text);
    $s = strpos($text, '{');
    $e = strrpos($text, '}');
    if ($s === false || $e === false) { return null; }
    return json_decode(substr($text, $s, $e - $s + 1), true);
}

function load_keyword_json($data_dir, $x_username) {
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $x_username);
    $path = $data_dir . 'keyword_' . $safe . '.json';
    if (!file_exists($path)) { return null; }
    return json_decode(file_get_contents($path), true);
}

function save_keyword_json($data_dir, $x_username, $fdata) {
    $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $x_username);
    $path = $data_dir . 'keyword_' . $safe . '.json';
    file_put_contents($path, json_encode($fdata, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
// Ollama: キーワード＋name＋description生成
// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
$tags_hint = is_array($tags) ? implode(', ', $tags) : '';
$prompt =
    "Xアカウント @{$twitter} のZennユーザー情報: bio=\"{$bio}\" タグ=\"{$tags_hint}\"\n" .
    "以下のJSON形式のみ返してください。説明不要。\n\n" .
    "{\n" .
    "  \"name\": \"表示名\",\n" .
    "  \"description\": \"bio文（100字以内、日本語）\",\n" .
    "  \"keywords\": [\"キーワード1\",\"キーワード2\",\"キーワード3\",\"キーワード4\",\"キーワード5\"]\n" .
    "}";

$raw_ollama = ollama_generate($prompt, $ollama_url, $ollama_model);
$parsed     = parse_json_from_text($raw_ollama);

$keywords = (isset($parsed['keywords']) && is_array($parsed['keywords']) && count($parsed['keywords']) > 0)
    ? $parsed['keywords']
    : $tags;
$gen_name = (isset($parsed['name']) && $parsed['name'] !== '')
    ? $parsed['name']
    : ($name !== '' ? $name : $twitter);
$gen_desc = (isset($parsed['description']) && $parsed['description'] !== '')
    ? $parsed['description']
    : $bio;

// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
// 既存チェック → 新規 or 更新
// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
$existing = load_keyword_json($data_dir, $twitter);

if ($existing) {
    // 更新
    if (!isset($existing['sources']) || !is_array($existing['sources'])) {
        $existing['sources'] = array();
    }
    $existing['sources']['zenn']         = $zenn_source;
    $existing['keywords']                = $keywords;
    $existing['user']['name']            = $gen_name;
    $existing['user']['description']     = $gen_desc;
    $existing['updated']                 = date('Y-m-d');
    save_keyword_json($data_dir, $twitter, $existing);
    echo json_encode(array(
        'ok'       => true,
        'action'   => 'updated',
        'account'  => $twitter,
        'keywords' => $keywords,
    ), JSON_UNESCAPED_UNICODE);
} else {
    // 新規
    $fdata = array(
        'account'  => $twitter,
        'keywords' => $keywords,
        'user'     => array(
            'username'       => $twitter,
            'name'           => $gen_name,
            'description'    => $gen_desc,
            'public_metrics' => array(
                'followers_count' => 0,
                'following_count' => 0,
                'tweet_count'     => 0,
            ),
        ),
        'sources'  => array(
            'zenn' => $zenn_source,
        ),
        'updated'  => date('Y-m-d'),
    );
    save_keyword_json($data_dir, $twitter, $fdata);
    echo json_encode(array(
        'ok'       => true,
        'action'   => 'created',
        'account'  => $twitter,
        'keywords' => $keywords,
    ), JSON_UNESCAPED_UNICODE);
}
exit;
