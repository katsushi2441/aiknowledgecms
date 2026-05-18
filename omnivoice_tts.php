<?php
/**
 * omnivoice_tts.php
 * 共有PHPサーバー側 — OmniVoice TTS呼び出しエンドポイント
 * 
 * GET  ?action=submit&text=...&job_id=...   → ジョブ投入
 * GET  ?action=status&job_id=...             → ステータス確認
 * GET  ?action=audio&job_id=...              → WAVプロキシダウンロード
 * GET  ?action=generate&text=...             → submit→poll→audio を一括（同期、最大60s）
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);

// getkeyword と同じ port 8003 に相乗り。
// 既存の他API呼び出し（execphp.php 等）で使っているベースURLに合わせること。
define('BRIDGE_URL', 'http://exbridge.ddns.net:8003');
define('POLL_MAX',   30);   // 最大ポーリング回数
define('POLL_SLEEP', 2);    // ポーリング間隔（秒）
define('CACHE_DIR',  __DIR__ . '/data/omnivoice/');

if (!file_exists(CACHE_DIR)) {
    mkdir(CACHE_DIR, 0755, true);
}

// デバッグログ（問題解決後に削除）
$log_line = date('Y-m-d H:i:s')
    . ' GET=' . json_encode($_GET, JSON_UNESCAPED_UNICODE)
    . ' POST=' . json_encode($_POST, JSON_UNESCAPED_UNICODE)
    . "\n";
file_put_contents(__DIR__ . '/data/omnivoice/debug.log', $log_line, FILE_APPEND);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// GET・POST両対応
function _param($key, $default = '') {
    if (isset($_GET[$key]) && $_GET[$key] !== '') return trim($_GET[$key]);
    if (isset($_POST[$key]) && $_POST[$key] !== '') return trim($_POST[$key]);
    return $default;
}

$action    = _param('action', 'submit');
$text      = _param('text');
$job_id    = _param('job_id');
$ref_audio = _param('ref_audio');

// ----------------------------------------
// action=audio: WAVをプロキシ返却
// ----------------------------------------
if ($action === 'audio') {
    if (!$job_id) {
        echo json_encode(array('error' => 'job_id required'));
        exit;
    }
    // キャッシュ確認
    $cache_wav = CACHE_DIR . $job_id . '.wav';
    if (file_exists($cache_wav)) {
        header('Content-Type: audio/wav');
        header('Content-Disposition: inline; filename="' . $job_id . '.wav"');
        header('Content-Length: ' . filesize($cache_wav));
        readfile($cache_wav);
        exit;
    }
    // ブリッジから取得
    $wav_data = _curl_get(BRIDGE_URL . '/tts/' . urlencode($job_id) . '/audio', true);
    if ($wav_data === false) {
        echo json_encode(array('error' => 'bridge fetch failed'));
        exit;
    }
    file_put_contents($cache_wav, $wav_data);
    header('Content-Type: audio/wav');
    header('Content-Disposition: inline; filename="' . $job_id . '.wav"');
    header('Content-Length: ' . strlen($wav_data));
    echo $wav_data;
    exit;
}

// ----------------------------------------
// action=generate: submit→poll→返却（同期）
// ----------------------------------------
if ($action === 'generate') {
    if (!$text) {
        echo json_encode(array('error' => 'text required'));
        exit;
    }
    // キャッシュ確認（md5でjob_id一致）
    $job_id = 'ov_' . substr(md5($text), 0, 12);
    $cache_wav = CACHE_DIR . $job_id . '.wav';
    if (file_exists($cache_wav)) {
        $url = 'omnivoice_tts.php?action=audio&job_id=' . urlencode($job_id);
        echo json_encode(array('status' => 'done', 'job_id' => $job_id, 'audio_url' => $url, 'cached' => true));
        exit;
    }

    // submit
    $submit_result = _submit($text, $job_id, $ref_audio);
    if (!$submit_result || isset($submit_result['error'])) {
        echo json_encode(array('error' => 'submit failed', 'detail' => $submit_result));
        exit;
    }

    // poll
    $final = _poll($job_id);
    if ($final['status'] !== 'done') {
        echo json_encode(array('error' => 'tts failed or timeout', 'detail' => $final));
        exit;
    }

    // WAVをキャッシュ
    $wav_data = _curl_get(BRIDGE_URL . '/tts/' . urlencode($job_id) . '/audio', true);
    if ($wav_data) {
        file_put_contents($cache_wav, $wav_data);
    }

    $audio_url = 'omnivoice_tts.php?action=audio&job_id=' . urlencode($job_id);
    echo json_encode(array('status' => 'done', 'job_id' => $job_id, 'audio_url' => $audio_url, 'cached' => false));
    exit;
}

// ----------------------------------------
// action=submit
// ----------------------------------------
if ($action === 'submit') {
    if (!$text) {
        echo json_encode(array('error' => 'text required'));
        exit;
    }
    if (!$job_id) {
        $job_id = 'ov_' . substr(md5($text), 0, 12);
    }
    $result = _submit($text, $job_id, $ref_audio);
    echo json_encode($result);
    exit;
}

// ----------------------------------------
// action=status
// ----------------------------------------
if ($action === 'status') {
    if (!$job_id) {
        echo json_encode(array('error' => 'job_id required'));
        exit;
    }
    $result = _curl_json(BRIDGE_URL . '/tts/' . urlencode($job_id) . '/status');
    echo json_encode($result);
    exit;
}

echo json_encode(array('error' => 'unknown action'));
exit;

// ----------------------------------------
// 内部関数
// ----------------------------------------
function _submit($text, $job_id, $ref_audio) {
    $payload = json_encode(array(
        'text'      => $text,
        'job_id'    => $job_id,
        'ref_audio' => $ref_audio ? $ref_audio : null,
    ));
    $cmd = 'curl -s -X POST'
         . ' -H "Content-Type: application/json"'
         . ' -d ' . escapeshellarg($payload)
         . ' ' . escapeshellarg(BRIDGE_URL . '/tts')
         . ' 2>&1';
    $out = shell_exec($cmd);
    if (!$out) {
        return array('error' => 'no response from bridge');
    }
    $decoded = json_decode($out, true);
    if (!$decoded) {
        return array('error' => 'invalid json', 'raw' => substr($out, 0, 200));
    }
    return $decoded;
}

function _poll($job_id) {
    for ($i = 0; $i < POLL_MAX; $i++) {
        sleep(POLL_SLEEP);
        $status = _curl_json(BRIDGE_URL . '/tts/' . urlencode($job_id) . '/status');
        if (!$status || isset($status['error'])) {
            continue;
        }
        if ($status['status'] === 'done' || $status['status'] === 'error') {
            return $status;
        }
    }
    return array('status' => 'timeout', 'job_id' => $job_id);
}

function _curl_json($url) {
    $cmd = 'curl -s ' . escapeshellarg($url) . ' 2>&1';
    $out = shell_exec($cmd);
    if (!$out) {
        return null;
    }
    return json_decode($out, true);
}

function _curl_get($url, $binary = false) {
    $cmd = 'curl -s ' . escapeshellarg($url) . ' 2>&1';
    return shell_exec($cmd);
}
