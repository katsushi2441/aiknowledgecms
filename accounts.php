<?php
$data_dir     = __DIR__ . '/data/';
$ollama_url   = 'https://exbridge.ddns.net/api/generate';
$ollama_model = 'gemma3:12b';

if (!is_dir($data_dir)) { mkdir($data_dir, 0755, true); }

// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
// スクレイピング対象URL（従来）
// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
$source_urls = array(
    'https://shift-ai.co.jp/blog/16883/',
    'https://www.salesforce.com/jp/blog/jp-ai-influencers/',
    'https://qiita.com/hikarun_videoai/items/e27cbd350aed73625864',
    'https://ai.codigital.co.jp/tutorial/ai-influencers-in-japan/',
    'https://yoshikazunomori.com/blog/digitalmarketing/ai-influencers/',
);

$skip_names = array(
    'Twitter','twitter','X','home','search','explore','intent','hashtag',
    'share','notifications','messages','settings','help','about','privacy',
    'tos','status','web','SalesforceJapan','Salesforce','Google','YouTube',
    'LINE','LINE','NHK','yahoo','Yahoo',
);

// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
// Zenn設定
// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
$zenn_topics = array('ai', 'llm', 'machinelearning', 'python', 'chatgpt');
$zenn_pages  = 3;  // トピックあたりのページ数

// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
// ユーティリティ
// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
function fetch_url($url) {
    $opts = array('http' => array(
        'method'        => 'GET',
        'header'        => "User-Agent: Mozilla/5.0 (compatible; AIKnowledgeBot/1.0)\r\n",
        'timeout'       => 15,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents($url, false, stream_context_create($opts));
    return $res ? $res : '';
}

function fetch_json_url($url) {
    $opts = array('http' => array(
        'method'        => 'GET',
        'header'        => "User-Agent: Mozilla/5.0 (compatible; AIKnowledgeBot/1.0)\r\nAccept: application/json\r\n",
        'timeout'       => 15,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents($url, false, stream_context_create($opts));
    if (!$res) { return null; }
    return json_decode($res, true);
}

// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
// curl単体取得（HTTPステータス返却付き）
// 429検知のためステータスを返す
// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
function curl_fetch_single($url, $timeout=15) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; AIKnowledgeBot/1.0)');
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json'));
    $body = curl_exec($ch);
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return array('body' => $body, 'http' => $http, 'err' => $err);
}

/* curl_multiで複数URLを一括並列取得する関数 */
function curl_multi_fetch($urls, $timeout=15) {
    $mh      = curl_multi_init();
    $handles = array();
    foreach ($urls as $key => $url) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; AIKnowledgeBot/1.0)');
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json'));
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }
    $running = null;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($status > CURLM_OK) { break; }
        curl_multi_select($mh);
    } while ($running > 0);

    $results = array();
    foreach ($handles as $key => $ch) {
        $body = curl_multi_getcontent($ch);
        $err  = curl_error($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $results[$key] = ($err === '' && $body !== false && $http >= 200 && $http < 300) ? $body : false;
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $results;
}

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

function extract_usernames($html) {
    $found = array();
    preg_match_all('/@([a-zA-Z0-9_]{4,20})/', $html, $m1);
    foreach ($m1[1] as $u) { $found[$u] = true; }
    preg_match_all('/(?:twitter\.com|x\.com)\/([a-zA-Z0-9_]{4,20})(?:[^a-zA-Z0-9_]|$)/', $html, $m2);
    $skip = array('intent','search','hashtag','share','home','explore','notifications',
                  'messages','settings','help','about','privacy','tos','i','web','status');
    foreach ($m2[1] as $u) {
        if (!in_array(strtolower($u), $skip)) { $found[$u] = true; }
    }
    return array_keys($found);
}

// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
// SSE送信（共通）
// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
function sse($type, $text, $cls, $extra) {
    $data = array('type' => $type, 'text' => $text, 'cls' => $cls);
    foreach ($extra as $k => $v) { $data[$k] = $v; }
    echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
    if (ob_get_level() > 0) { ob_flush(); }
    flush();
}

function load_accounts($data_dir) {
    $accounts = array();
    foreach (glob($data_dir . 'keyword_*.json') as $f) {
        $raw  = file_get_contents($f);
        $json = json_decode($raw, true);
        if (!$json || !isset($json['account'])) { continue; }
        $accounts[] = array(
            'account'  => $json['account'],
            'keywords' => isset($json['keywords']) ? $json['keywords'] : array(),
            'user'     => isset($json['user'])     ? $json['user']     : array(),
            'sources'  => isset($json['sources'])  ? $json['sources']  : array(),
            'updated'  => isset($json['updated'])  ? $json['updated']  : '',
            'file'     => basename($f),
        );
    }
    usort($accounts, function($a, $b) { return strcmp($b['updated'], $a['updated']); });
    return $accounts;
}

// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
// Zenn: ユーザー詳細取得
// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
function zenn_get_user($zenn_username) {
    $data = fetch_json_url('https://zenn.dev/api/users/' . $zenn_username);
    if (!$data || !isset($data['user'])) { return null; }
    return $data['user'];
}

function zenn_get_user_tags($zenn_username) {
    $data = fetch_json_url('https://zenn.dev/api/articles?username=' . $zenn_username . '&order=latest');
    if (!$data || !isset($data['articles'])) { return array(); }
    $tag_count = array();
    foreach ($data['articles'] as $article) {
        if (isset($article['topics']) && is_array($article['topics'])) {
            foreach ($article['topics'] as $t) {
                $name = is_array($t) ? (isset($t['name']) ? $t['name'] : '') : $t;
                if ($name) {
                    $tag_count[$name] = isset($tag_count[$name]) ? $tag_count[$name] + 1 : 1;
                }
            }
        }
    }
    arsort($tag_count);
    return array_keys(array_slice($tag_count, 0, 5, true));
}

// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
// keyword_*.json 読み書き
// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
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
// API: アカウント一覧JSON
// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
if (isset($_GET['action']) && $_GET['action'] === 'list') {
    header('Content-Type: application/json; charset=UTF-8');
    $accounts = load_accounts($data_dir);
    echo json_encode(array('ok' => true, 'accounts' => $accounts), JSON_UNESCAPED_UNICODE);
    exit;
}

// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
// API: 削除
// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
if (isset($_GET['action']) && $_GET['action'] === 'delete') {
    header('Content-Type: application/json');
    $body = file_get_contents('php://input');
    $req  = json_decode($body, true);
    $file = isset($req['file']) ? basename($req['file']) : '';
    if (!$file || !preg_match('/^keyword_[a-zA-Z0-9_]+\.json$/', $file)) {
        echo json_encode(array('ok' => false, 'reason' => 'invalid_file'));
        exit;
    }
    $path = $data_dir . $file;
    if (!file_exists($path)) {
        echo json_encode(array('ok' => false, 'reason' => 'not_found'));
        exit;
    }
    unlink($path);
    echo json_encode(array('ok' => true));
    exit;
}

// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
// API: SSEストリーム（従来スクレイピング→Ollama生成）
// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
if (isset($_GET['action']) && $_GET['action'] === 'stream') {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    set_time_limit(0);

    $data_dir     = __DIR__ . '/data/';
    $ollama_url   = 'https://exbridge.ddns.net/api/generate';
    $ollama_model = 'gemma3:12b';
    $source_urls = array(
        'https://shift-ai.co.jp/blog/16883/',
        'https://www.salesforce.com/jp/blog/jp-ai-influencers/',
        'https://qiita.com/hikarun_videoai/items/e27cbd350aed73625864',
        'https://ai.codigital.co.jp/tutorial/ai-influencers-in-japan/',
        'https://yoshikazunomori.com/blog/digitalmarketing/ai-influencers/',
    );
    $skip_names = array(
        'Twitter','twitter','X','home','search','explore','intent','hashtag',
        'share','notifications','messages','settings','help','about','privacy',
        'tos','status','web','SalesforceJapan','Salesforce','Google','YouTube',
        'LINE','LINE','NHK','yahoo','Yahoo',
    );

    sse('log', '=== スクレイピング開始 ===', 'log-head', array());

    $all_usernames = array();
    foreach ($source_urls as $url) {
        sse('log', '取得中: ' . $url, '', array());
        $html = fetch_url($url);
        if (!$html) { sse('log', '  [SKIP] 取得失敗', 'log-skip', array()); continue; }
        $found = extract_usernames($html);
        sse('log', '  候補: ' . count($found) . '件', 'log-ok', array());
        foreach ($found as $u) { $all_usernames[$u] = true; }
        sleep(1);
    }

    $all_usernames = array_keys($all_usernames);
    sse('log', '収集アカウント総数: ' . count($all_usernames) . '件', 'log-head', array());

    $targets = array();
    foreach ($all_usernames as $u) {
        $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $u);
        if (strlen($safe) < 4) { continue; }
        if (in_array($u, $skip_names)) { continue; }
        $file = $data_dir . 'keyword_' . $safe . '.json';
        if (file_exists($file)) {
            sse('log', '[SKIP] @' . $u . ' (既存)', 'log-skip', array());
            continue;
        }
        $targets[] = $u;
    }

    sse('log', '生成対象: ' . count($targets) . '件', 'log-head', array());

    $success = 0;
    $fail    = 0;
    foreach ($targets as $username) {
        sse('log', '生成中: @' . $username . ' ...', '', array());
        $prompt =
            "Xアカウント @{$username} は日本のAI・テクノロジー分野のインフルエンサーです。\n" .
            "このアカウントについて以下のJSON形式のみ返してください。説明不要。\n\n" .
            "{\n" .
            "  \"name\": \"表示名（推測）\",\n" .
            "  \"description\": \"bio文（100字以内、日本語）\",\n" .
            "  \"keywords\": [\"キーワード1\",\"キーワード2\",\"キーワード3\",\"キーワード4\",\"キーワード5\"],\n" .
            "  \"followers_count\": 推定フォロワー数（整数）,\n" .
            "  \"following_count\": 推定フォロー数（整数）\n" .
            "}";

        $raw    = ollama_generate($prompt, $ollama_url, $ollama_model);
        $parsed = parse_json_from_text($raw);

        if (!$parsed || !isset($parsed['keywords']) || count($parsed['keywords']) === 0) {
            sse('log', '  [FAIL] 生成失敗', 'log-err', array());
            $fail++;
            continue;
        }

        $safe = preg_replace('/[^a-zA-Z0-9_]/', '', $username);
        $fdata = array(
            'account'  => $username,
            'keywords' => $parsed['keywords'],
            'user'     => array(
                'username'       => $username,
                'name'           => isset($parsed['name'])        ? $parsed['name']        : $username,
                'description'    => isset($parsed['description']) ? $parsed['description'] : '',
                'public_metrics' => array(
                    'followers_count' => isset($parsed['followers_count']) ? intval($parsed['followers_count']) : 1000,
                    'following_count' => isset($parsed['following_count']) ? intval($parsed['following_count']) : 500,
                    'tweet_count'     => 0,
                ),
            ),
            'sources'  => array(),
            'updated'  => date('Y-m-d'),
        );
        save_keyword_json($data_dir, $username, $fdata);
        sse('log', '  [OK] ' . implode(', ', $parsed['keywords']), 'log-ok', array());
        $success++;
        sleep(1);
    }

    sse('done', '', '', array('success' => $success, 'skip' => count($all_usernames) - count($targets)));
    exit;
}

// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
// API: SSEストリーム（Zennからアカウント収集）
// qq フェーズ1: トピック単位でページをシリアル取得、リクエスト間sleep(2)で429回避
// qq フェーズ2: ユーザー詳細をシリアル取得、429検知でスキップ
// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
if (isset($_GET['action']) && $_GET['action'] === 'stream_zenn') {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    set_time_limit(0);

    $data_dir     = __DIR__ . '/data/';
    $ollama_url   = 'https://exbridge.ddns.net/api/generate';
    $ollama_model = 'gemma3:12b';
    $zenn_topics  = array('ai', 'llm', 'machinelearning', 'python', 'chatgpt');
    $zenn_pages   = 3;

    sse('log', '=== Zenn収集開始 ===', 'log-head', array());

    // qq STEP1: トピック×ページをシリアル取得、sleep(2)で429回避 qq
    $zenn_users = array();
    foreach ($zenn_topics as $topic) {
        sse('log', 'トピック: ' . $topic, 'log-head', array());
        for ($page = 1; $page <= $zenn_pages; $page++) {
            $url = 'https://zenn.dev/api/articles?topic_name=' . $topic . '&order=latest&page=' . $page;
            sse('log', '[DEBUG] fetch: ' . $url, '', array());
            $result = curl_fetch_single($url);
            $http   = $result['http'];
            $body   = $result['body'];
            sse('log', '[DEBUG] http=' . $http . ' body_len=' . strlen($body), '', array());

            if ($http === 429) {
                sse('log', '[WARN] 429 Too Many Requests topic=' . $topic . ' page=' . $page . ' — スキップ', 'log-err', array());
                sleep(5);
                continue;
            }
            if ($http < 200 || $http >= 300 || $body === false) {
                sse('log', '[ERROR] fetch失敗 http=' . $http . ' topic=' . $topic . ' page=' . $page, 'log-err', array());
                sleep(2);
                continue;
            }
            $data = json_decode($body, true);
            if (!$data || !isset($data['articles'])) {
                sse('log', '[ERROR] JSON不正 topic=' . $topic . ' page=' . $page, 'log-err', array());
                sleep(2);
                continue;
            }
            if (count($data['articles']) === 0) {
                sse('log', '[DEBUG] 記事なし topic=' . $topic . ' page=' . $page, 'log-skip', array());
                sleep(2);
                continue;
            }
            foreach ($data['articles'] as $article) {
                $uname = isset($article['user']['username']) ? $article['user']['username'] : '';
                if ($uname) {
                    $zenn_users[$uname] = isset($zenn_users[$uname]) ? $zenn_users[$uname] + 1 : 1;
                }
            }
            sse('log', '  page=' . $page . ' 記事=' . count($data['articles']) . '件 累計ユーザー=' . count($zenn_users) . '件', 'log-ok', array());
            sleep(2);
        }
        sleep(3);
    }

    sse('log', 'Zennユーザー総数: ' . count($zenn_users) . '件', 'log-head', array());

    // qq STEP2: ユーザー詳細をシリアル取得、429検知でスキップ qq
    $usernames = array_keys($zenn_users);
    sse('log', 'ユーザー詳細 ' . count($usernames) . '件をシリアル取得開始', 'log-head', array());

    $success = 0;
    $skip    = 0;
    $idx     = 0;
    foreach ($usernames as $zenn_username) {
        $idx++;
        $user_url = 'https://zenn.dev/api/users/' . $zenn_username;
        sse('log', '[DEBUG] (' . $idx . '/' . count($usernames) . ') user fetch: ' . $user_url, '', array());
        $result   = curl_fetch_single($user_url);
        $http     = $result['http'];
        $raw_user = $result['body'];
        sse('log', '[DEBUG] http=' . $http, '', array());

        if ($http === 429) {
            sse('log', '[WARN] 429 zenn:' . $zenn_username . ' — スキップ（5秒待機）', 'log-err', array());
            sleep(5);
            $skip++; continue;
        }
        if ($http < 200 || $http >= 300 || $raw_user === false) {
            sse('log', '[ERROR] (' . $idx . '/' . count($usernames) . ') user fetch失敗 http=' . $http . ' zenn:' . $zenn_username, 'log-err', array());
            sleep(2);
            $skip++; continue;
        }

        $zuser_data = json_decode($raw_user, true);
        if (!$zuser_data || !isset($zuser_data['user'])) {
            sse('log', '[SKIP] (' . $idx . '/' . count($usernames) . ') zenn:' . $zenn_username . ' 取得失敗', 'log-skip', array());
            sleep(1);
            $skip++; continue;
        }
        $zuser = $zuser_data['user'];

        $twitter = isset($zuser['twitter_username']) ? $zuser['twitter_username'] : '';
        $github  = isset($zuser['github_username'])  ? $zuser['github_username']  : '';

        if (!$twitter) {
            sse('log', '[SKIP] zenn:' . $zenn_username . ' (Xアカウントなし)', 'log-skip', array());
            sleep(1);
            $skip++; continue;
        }

        sse('log', '[DEBUG] @' . $twitter . ' タグ取得中...', '', array());
        $tags = zenn_get_user_tags($zenn_username);
        sleep(1);

        $zenn_source = array(
            'username'          => $zenn_username,
            'articles_count'    => isset($zuser['articles_count'])    ? intval($zuser['articles_count'])    : 0,
            'total_liked_count' => isset($zuser['total_liked_count']) ? intval($zuser['total_liked_count']) : 0,
            'follower_count'    => isset($zuser['follower_count'])     ? intval($zuser['follower_count'])    : 0,
            'github_username'   => $github,
            'tags'              => $tags,
            'fetched_at'        => date('Y-m-d'),
        );

        $existing = load_keyword_json($data_dir, $twitter);
        if ($existing) {
            $bio_hint  = isset($zuser['bio']) ? $zuser['bio'] : '';
            $tags_hint = implode(', ', $tags);
            $prompt =
                "Xアカウント @{$twitter} のZennユーザー情報: bio=\"{$bio_hint}\" タグ=\"{$tags_hint}\"\n" .
                "以下のJSON形式のみ返してください。説明不要。\n\n" .
                "{\n" .
                "  \"name\": \"表示名\",\n" .
                "  \"description\": \"bio文（100字以内、日本語）\",\n" .
                "  \"keywords\": [\"キーワード1\",\"キーワード2\",\"キーワード3\",\"キーワード4\",\"キーワード5\"]\n" .
                "}";
            sse('log', '[DEBUG] @' . $twitter . ' Ollama生成中(既存更新)...', '', array());
            $raw    = ollama_generate($prompt, $ollama_url, $ollama_model);
            $parsed = parse_json_from_text($raw);
            $keywords = (isset($parsed['keywords']) && is_array($parsed['keywords'])) ? $parsed['keywords'] : $tags;
            $name     = isset($parsed['name'])        ? $parsed['name']        : (isset($zuser['name']) ? $zuser['name'] : $twitter);
            $desc     = isset($parsed['description']) ? $parsed['description'] : $bio_hint;
            if (!isset($existing['sources'])) { $existing['sources'] = array(); }
            $existing['sources']['zenn'] = $zenn_source;
            $existing['keywords']        = $keywords;
            $existing['user']['name']        = $name;
            $existing['user']['description'] = $desc;
            $existing['updated'] = date('Y-m-d');
            save_keyword_json($data_dir, $twitter, $existing);
            sse('log', '[UPDATE] @' . $twitter . ' / keywords=' . implode(',', $keywords), 'log-ok', array());
            $success++;
            sleep(1);
            continue;
        }

        $bio_hint  = isset($zuser['bio']) ? $zuser['bio'] : '';
        $tags_hint = implode(', ', $tags);
        $prompt =
            "Xアカウント @{$twitter} のZennユーザー情報: bio=\"{$bio_hint}\" タグ=\"{$tags_hint}\"\n" .
            "以下のJSON形式のみ返してください。説明不要。\n\n" .
            "{\n" .
            "  \"name\": \"表示名\",\n" .
            "  \"description\": \"bio文（100字以内、日本語）\",\n" .
            "  \"keywords\": [\"キーワード1\",\"キーワード2\",\"キーワード3\",\"キーワード4\",\"キーワード5\"]\n" .
            "}";

        sse('log', '[DEBUG] @' . $twitter . ' Ollama生成中(新規)...', '', array());
        $raw    = ollama_generate($prompt, $ollama_url, $ollama_model);
        $parsed = parse_json_from_text($raw);

        $keywords = (isset($parsed['keywords']) && is_array($parsed['keywords'])) ? $parsed['keywords'] : $tags;
        $name     = isset($parsed['name'])        ? $parsed['name']        : (isset($zuser['name']) ? $zuser['name'] : $twitter);
        $desc     = isset($parsed['description']) ? $parsed['description'] : $bio_hint;

        $fdata = array(
            'account'  => $twitter,
            'keywords' => $keywords,
            'user'     => array(
                'username'       => $twitter,
                'name'           => $name,
                'description'    => $desc,
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
        sse('log', '[NEW] @' . $twitter . ' / zenn:' . $zenn_username . ' 記事:' . $zenn_source['articles_count'] . ' いいね:' . $zenn_source['total_liked_count'], 'log-ok', array());
        $success++;
        sleep(1);
    }

    sse('done', '', '', array('success' => $success, 'skip' => $skip));
    exit;
}

$do_generate      = isset($_GET['generate']);
$do_generate_zenn = isset($_GET['generate_zenn']);
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>アカウント一覧 — AIKnowledgeSNS</title>
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Exo+2:wght@300;400;600&display=swap" rel="stylesheet">
<style>
:root{
  --blue:#00aaff;--bdim:#0077cc;--bbg:rgba(0,170,255,.06);--bborder:rgba(0,170,255,.22);
  --dark:#050a0d;--text:#c8e8ff;--muted:#4488aa;--vm:#335566;
  --green:#00ff88;--orange:#ff8800;--red:#ff4466;--purple:#aa88ff;
}
*{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--dark);color:var(--text);font-family:'Exo 2',sans-serif;min-height:100vh;padding:28px 20px;}
.app{max-width:1100px;margin:0 auto;}
.header{margin-bottom:20px;display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;}
.title{font-family:'Share Tech Mono',monospace;font-size:1.3rem;color:var(--blue);letter-spacing:.1em;}
.subtitle{font-family:'Share Tech Mono',monospace;font-size:.68rem;color:var(--muted);margin-top:4px;}
.nav{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;}
.nav a{font-family:'Share Tech Mono',monospace;font-size:.68rem;color:var(--muted);
  text-decoration:none;border:1px solid var(--bborder);border-radius:3px;padding:5px 12px;transition:all .2s;}
.nav a:hover{color:var(--blue);border-color:var(--blue);}
.nav a.gen{color:var(--orange);border-color:rgba(255,136,0,.35);}
.nav a.gen:hover{background:rgba(255,136,0,.08);border-color:var(--orange);}
.nav a.gen-zenn{color:var(--purple);border-color:rgba(170,136,255,.35);}
.nav a.gen-zenn:hover{background:rgba(170,136,255,.08);border-color:var(--purple);}
.search-bar{margin-bottom:12px;}
.search-bar input{width:100%;padding:9px 14px;background:rgba(0,170,255,.06);
  border:1px solid var(--bborder);border-radius:4px;color:var(--text);
  font-family:'Share Tech Mono',monospace;font-size:.8rem;outline:none;}
.search-bar input:focus{border-color:var(--blue);}
.count-bar{font-family:'Share Tech Mono',monospace;font-size:.66rem;color:var(--muted);margin-bottom:14px;}
.count-bar span{color:var(--green);}
.logbox{background:rgba(0,0,0,.5);border:1px solid rgba(255,136,0,.25);border-radius:4px;
  padding:12px 14px;height:200px;overflow-y:auto;font-family:'Share Tech Mono',monospace;
  font-size:.69rem;color:var(--muted);line-height:1.8;margin-bottom:18px;}
.logbox::-webkit-scrollbar{width:4px;}
.logbox::-webkit-scrollbar-thumb{background:rgba(255,136,0,.2);}
.log-ok{color:var(--green);}
.log-skip{color:var(--vm);}
.log-err{color:var(--red);}
.log-head{color:var(--orange);}
.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:14px;}
.card{background:var(--bbg);border:1px solid var(--bborder);border-radius:6px;
  padding:16px 18px;position:relative;overflow:hidden;transition:border-color .2s,background .2s;}
.card::before{content:'';position:absolute;top:0;left:0;right:0;height:1px;
  background:linear-gradient(90deg,transparent,var(--blue),transparent);}
.card:hover{border-color:rgba(0,170,255,.5);background:rgba(0,170,255,.09);}
.card-handle{font-family:'Share Tech Mono',monospace;font-size:.88rem;color:var(--blue);margin-bottom:3px;}
.card-handle a{color:var(--blue);text-decoration:none;}
.card-handle a:hover{text-decoration:underline;}
.card-name{font-size:.78rem;color:var(--text);margin-bottom:5px;}
.card-metrics{font-family:'Share Tech Mono',monospace;font-size:.61rem;color:var(--vm);margin-bottom:7px;}
.card-metrics span{color:var(--muted);}
.card-bio{font-size:.74rem;color:var(--muted);line-height:1.65;margin-bottom:9px;
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
.kwlist{display:flex;flex-wrap:wrap;gap:4px;margin-bottom:6px;}
.kwtag{font-family:'Share Tech Mono',monospace;font-size:.6rem;padding:2px 7px;
  border-radius:20px;background:rgba(0,170,255,.09);border:1px solid rgba(0,170,255,.25);color:var(--blue);}
.zenn-badge{font-family:'Share Tech Mono',monospace;font-size:.6rem;padding:3px 8px;
  border-radius:3px;background:rgba(170,136,255,.1);border:1px solid rgba(170,136,255,.3);
  color:var(--purple);margin-bottom:8px;display:inline-block;}
.zenn-badge a{color:var(--purple);text-decoration:none;}
.zenn-badge a:hover{text-decoration:underline;}
.card-footer{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:5px;}
.card-date{font-family:'Share Tech Mono',monospace;font-size:.59rem;color:var(--vm);}
.card-actions{display:flex;gap:5px;}
.btn-sm{font-family:'Share Tech Mono',monospace;font-size:.61rem;padding:3px 9px;
  border-radius:3px;text-decoration:none;cursor:pointer;border:1px solid;transition:all .15s;background:none;}
.btn-blue{background:rgba(0,170,255,.1);color:var(--blue);border-color:rgba(0,170,255,.35);}
.btn-blue:hover{background:rgba(0,170,255,.22);}
.btn-green{background:rgba(0,255,136,.1);color:var(--green);border-color:rgba(0,255,136,.35);}
.btn-green:hover{background:rgba(0,255,136,.22);}
.btn-purple{background:rgba(170,136,255,.1);color:var(--purple);border-color:rgba(170,136,255,.35);}
.btn-purple:hover{background:rgba(170,136,255,.22);}
.btn-red{background:rgba(255,68,102,.08);color:var(--red);border-color:rgba(255,68,102,.3);}
.btn-red:hover{background:rgba(255,68,102,.2);}
.empty{text-align:center;font-family:'Share Tech Mono',monospace;font-size:.82rem;
  color:var(--vm);padding:50px;line-height:2.5;}
#no-results{display:none;text-align:center;font-family:'Share Tech Mono',monospace;font-size:.78rem;color:var(--vm);padding:30px;}
</style>
</head>
<body>
<div class="app">
  <div class="header">
    <div>
      <div class="title">◎ ACCOUNT LIST</div>
      <div class="subtitle">keyword_*.json から生成されたアカウント一覧</div>
    </div>
  </div>
  <div class="nav">
    <a href="aiknowledgesns.php">← AIKnowledgeSNS</a>
    <a href="airadarx.php">← AIRadarX</a>
    <a href="accounts.php?generate=1" class="gen">⟳ スクレイピング&amp;生成</a>
    <a href="accounts.php?generate_zenn=1" class="gen-zenn">⟳ Zennから収集</a>
  </div>
  <?php if ($do_generate || $do_generate_zenn): ?>
  <div class="logbox" id="logbox">
    <div style="color:#ff8800;">◎ 処理を開始しています...</div>
  </div>
  <?php endif; ?>
  <div class="search-bar">
    <input type="text" id="search" placeholder="アカウント名・キーワード・bioで絞り込み..." oninput="filterCards()">
  </div>
  <div class="count-bar">表示中 <span id="count-num">—</span> アカウント</div>
  <div id="no-results">該当するアカウントがありません</div>
  <div class="grid" id="grid"></div>
</div>
<script>
function escHtml(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}

function renderAccounts(accounts) {
  var grid = document.getElementById('grid');
  if (!accounts || accounts.length === 0) {
    grid.innerHTML = '<div class="empty">アカウントがありません<br>「スクレイピング&生成」または「Zennから収集」を実行してください</div>';
    document.getElementById('count-num').textContent = '0';
    return;
  }
  var html = '';
  for (var i = 0; i < accounts.length; i++) {
    var a = accounts[i];
    var user = a.user || {};
    var metrics = user.public_metrics || {};
    var sources = (a.sources && !Array.isArray(a.sources)) ? a.sources : {};
    var zenn = sources.zenn || null;
    var name = user.name || ('@' + a.account);
    var bio  = user.description || '';
    var fw   = metrics.followers_count !== undefined ? Number(metrics.followers_count).toLocaleString() : '—';
    var fwing= metrics.following_count !== undefined ? Number(metrics.following_count).toLocaleString() : '—';
    var kws  = a.keywords || [];
    var kwHtml = '';
    for (var j = 0; j < kws.length; j++) { kwHtml += '<span class="kwtag">#' + escHtml(kws[j]) + '</span>'; }

    var zennHtml = '';
    if (zenn) {
      zennHtml =
        '<div class="zenn-badge">' +
        'Zenn: <a href="https://zenn.dev/' + encodeURIComponent(zenn.username) + '" target="_blank">' + escHtml(zenn.username) + '</a>' +
        ' &nbsp;記事 ' + zenn.articles_count +
        ' &nbsp;いいね ' + zenn.total_liked_count +
        (zenn.github_username ? ' &nbsp;<a href="https://github.com/' + encodeURIComponent(zenn.github_username) + '" target="_blank">GitHub</a>' : '') +
        '</div>';
    }

    var zennTags = (zenn && zenn.tags) ? zenn.tags : [];
    var searchData = (a.account + ' ' + name + ' ' + bio + ' ' + kws.join(' ') + (zenn ? ' ' + zenn.username + ' ' + zennTags.join(' ') : '')).toLowerCase();

    html +=
      '<div class="card" data-search="' + escHtml(searchData) + '">' +
        '<div class="card-handle"><a href="aiknowledgesns.php?view=account&u=' + encodeURIComponent(a.account) + '">@' + escHtml(a.account) + '</a></div>' +
        (name !== '@' + a.account ? '<div class="card-name">' + escHtml(name) + '</div>' : '') +
        '<div class="card-metrics">フォロワー <span>' + fw + '</span> &nbsp; フォロー <span>' + fwing + '</span></div>' +
        (bio ? '<div class="card-bio">' + escHtml(bio) + '</div>' : '') +
        zennHtml +
        '<div class="kwlist">' + kwHtml + '</div>' +
        '<div class="card-footer">' +
          '<span class="card-date">' + escHtml(a.updated) + '</span>' +
          '<div class="card-actions">' +
            '<a href="aiknowledgesns.php?view=account&u=' + encodeURIComponent(a.account) + '" class="btn-sm btn-blue">SNSで見る</a>' +
            '<a href="https://x.com/' + encodeURIComponent(a.account) + '" target="_blank" class="btn-sm btn-green">X →</a>' +
            (zenn ? '<a href="https://zenn.dev/' + encodeURIComponent(zenn.username) + '" target="_blank" class="btn-sm btn-purple">Zenn →</a>' : '') +
            '<button class="btn-sm btn-red" onclick="deleteAccount(\'' + escHtml(a.file) + '\',this)">削除</button>' +
          '</div>' +
        '</div>' +
      '</div>';
  }
  grid.innerHTML = html;
  document.getElementById('count-num').textContent = accounts.length;
}

function filterCards() {
  var q = document.getElementById('search').value.toLowerCase().trim();
  var cards = document.querySelectorAll('.card');
  var shown = 0;
  cards.forEach(function(card) {
    var match = !q || (card.getAttribute('data-search') || '').indexOf(q) !== -1;
    card.style.display = match ? '' : 'none';
    if (match) { shown++; }
  });
  document.getElementById('count-num').textContent = shown;
  document.getElementById('no-results').style.display = (shown === 0 && cards.length > 0) ? 'block' : 'none';
}

function deleteAccount(filename, btn) {
  var account = filename.replace('keyword_','').replace('.json','');
  if (!confirm('@' + account + ' を削除しますか？')) { return; }
  var xhr = new XMLHttpRequest();
  xhr.open('POST', 'accounts.php?action=delete', true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onreadystatechange = function() {
    if (xhr.readyState !== 4) { return; }
    try {
      var data = JSON.parse(xhr.responseText);
      if (data.ok) {
        var card = btn.closest('.card');
        card.style.transition = 'opacity .3s';
        card.style.opacity = '0';
        setTimeout(function() { card.remove(); filterCards(); }, 300);
      }
    } catch(e) {}
  };
  xhr.send(JSON.stringify({ file: filename }));
}

function addLog(msg, cls) {
  var box = document.getElementById('logbox');
  if (!box) { return; }
  var div = document.createElement('div');
  if (cls) { div.className = cls; }
  div.textContent = msg;
  box.appendChild(div);
  box.scrollTop = box.scrollHeight;
}

function loadAccounts() {
  var xhr = new XMLHttpRequest();
  xhr.open('GET', 'accounts.php?action=list', true);
  xhr.onreadystatechange = function() {
    if (xhr.readyState !== 4) { return; }
    try { renderAccounts(JSON.parse(xhr.responseText).accounts || []); }
    catch(e) { renderAccounts([]); }
  };
  xhr.send();
}

function startSSE(endpoint) {
  addLog('[DEBUG] SSE接続開始: ' + endpoint, 'log-head');
  var source = new EventSource('accounts.php?action=' + endpoint);
  var timer = null;
  source.onopen = function() {
    addLog('[DEBUG] SSE接続確立', 'log-ok');
  };
  source.onmessage = function(e) {
    addLog('[DEBUG] SSE受信: ' + e.data.substr(0, 80), '');
    try {
      var msg = JSON.parse(e.data);
      if (msg.type === 'log') {
        addLog(msg.text, msg.cls || '');
        if (timer) { clearTimeout(timer); }
        timer = setTimeout(function() {
          source.close();
          addLog('=== タイムアウト：一覧を再読み込みします ===', 'log-head');
          loadAccounts();
        }, 15000);
      } else if (msg.type === 'done') {
        if (timer) { clearTimeout(timer); }
        source.close();
        addLog('=== 完了: ' + msg.success + '件生成 / ' + msg.skip + '件スキップ ===', 'log-head');
        loadAccounts();
      }
    } catch(ex) {
      addLog('[DEBUG] JSON解析エラー: ' + ex.message, 'log-err');
    }
  };
  source.onerror = function(e) {
    if (timer) { clearTimeout(timer); }
    source.close();
    addLog('[ERROR] SSE接続エラー (readyState=' + source.readyState + ')', 'log-err');
    loadAccounts();
  };
}

<?php if ($do_generate): ?>
startSSE('stream');
<?php elseif ($do_generate_zenn): ?>
startSSE('stream_zenn');
<?php else: ?>
loadAccounts();
<?php endif; ?>
</script>
</body>
</html>
