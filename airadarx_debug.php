<?php
header('Content-Type: text/plain; charset=UTF-8');

echo "=== AIRadarX デバッグ ===\n\n";

// 1. セッション
session_start();
echo "1. セッション: OK\n";
echo "   access_token: " . (isset($_SESSION['access_token']) ? "あり(" . substr($_SESSION['access_token'],0,10) . "...)" : "なし") . "\n\n";

// 2. APIキー
$keys_file = __DIR__ . '/x_api_keys.sh';
echo "2. x_api_keys.sh: " . (file_exists($keys_file) ? "あり" : "なし") . "\n";
if (file_exists($keys_file)) {
    $keys = array();
    $lines = file($keys_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (preg_match('/(?:export\s+)?(\w+)=["\']?([^"\'#\r\n]*)["\']?/', $line, $m)) {
            $keys[trim($m[1])] = trim($m[2]);
        }
    }
    echo "   X_API_KEY: "    . (isset($keys['X_API_KEY'])    ? substr($keys['X_API_KEY'],0,8)."..." : "なし") . "\n";
    echo "   X_API_SECRET: " . (isset($keys['X_API_SECRET']) ? substr($keys['X_API_SECRET'],0,8)."..." : "なし") . "\n";
}
echo "\n";

// 3. dataディレクトリ
$data_dir = __DIR__ . '/data';
echo "3. data/ディレクトリ: " . (is_dir($data_dir) ? "あり" : "なし") . "\n";
if (is_dir($data_dir)) {
    $files = glob($data_dir . '/*.json');
    echo "   JSONファイル数: " . count($files) . "\n";
    foreach ($files as $f) {
        echo "   - " . basename($f) . "\n";
    }
}
echo "\n";

// 4. Ollama疎通
echo "4. Ollama (localhost): ";
$opts = array(
    'http' => array(
        'method'        => 'GET',
        'timeout'       => 5,
        'ignore_errors' => true,
    )
);
$ctx = stream_context_create($opts);
$res = @file_get_contents('https://exbridge.ddns.net/api/tags', false, $ctx);
if ($res) {
    echo "OK\n";
    $data = json_decode($res, true);
    if (isset($data['models'])) {
        foreach ($data['models'] as $m) {
            echo "   モデル: " . $m['name'] . "\n";
        }
    }
} else {
    echo "接続失敗\n";
}
echo "\n";

// 5. X API疎通（トークンあれば）
if (isset($_SESSION['access_token'])) {
    echo "5. X API /users/me: ";
    $token = $_SESSION['access_token'];
    $opts2 = array(
        'http' => array(
            'method'        => 'GET',
            'header'        => "Authorization: Bearer $token\r\nUser-Agent: AIRadarX/1.0\r\n",
            'timeout'       => 10,
            'ignore_errors' => true,
        )
    );
    $ctx2 = stream_context_create($opts2);
    $res2 = @file_get_contents('https://api.twitter.com/2/users/me?user.fields=description', false, $ctx2);
    if ($res2) {
        $d = json_decode($res2, true);
        if (isset($d['data'])) {
            echo "OK (@" . $d['data']['username'] . ")\n";
        } else {
            echo "失敗\n";
            echo "   レスポンス: " . $res2 . "\n";
        }
    } else {
        echo "接続失敗\n";
    }
} else {
    echo "5. X API: トークンなし（先にログインしてください）\n";
}
