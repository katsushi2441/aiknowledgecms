<?php
// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
// API: PHP経由でOllama呼び出し
// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
if (isset($_GET['action']) && $_GET['action'] === 'analyze') {
    header('Content-Type: application/json; charset=UTF-8');
    $body = file_get_contents('php://input');
    $req  = json_decode($body, true);
    $prompt = isset($req['prompt']) ? $req['prompt'] : '';
    $system = isset($req['system']) ? $req['system'] : '';
    if ($prompt === '') {
        echo json_encode(array('ok' => false, 'reason' => 'no_prompt'));
        exit;
    }
    $full_prompt = $system ? $system . "\n\n" . $prompt : $prompt;
    $payload = json_encode(array(
        'model'  => 'gemma3:12b',
        'prompt' => $full_prompt,
        'stream' => false,
    ));
    $opts = array(
        'http' => array(
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\n",
            'content'       => $payload,
            'timeout'       => 60,
            'ignore_errors' => true,
        )
    );
    $ctx = stream_context_create($opts);
    $res = @file_get_contents('https://exbridge.ddns.net/api/generate', false, $ctx);
    if (!$res) {
        echo json_encode(array('ok' => false, 'reason' => 'ollama_unreachable'));
        exit;
    }
    $data = json_decode($res, true);
    $response = isset($data['response']) ? $data['response'] : '';
    echo json_encode(array('ok' => true, 'response' => $response));
    exit;
}

// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
// API: ニュースJSON返却
// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
if (isset($_GET['action']) && $_GET['action'] === 'news_from_cms') {
    header('Content-Type: application/json; charset=UTF-8');
    $kw = isset($_GET['kw']) ? trim($_GET['kw']) : '';
    if ($kw === '') {
        echo json_encode(array('ok' => false, 'reason' => 'no_kw'));
        exit;
    }
    $items    = array();
    $analysis = '';
    $cms_url  = '';
    // 最新3日分を検索し、見つかったら最大3件で打ち切り
    for ($i = 0; $i < 3; $i++) {
        $date = date('Y-m-d', strtotime('-' . $i . ' days'));
        $candidate = __DIR__ . '/data/' . $date . '_' . $kw . '.json';
        if (!file_exists($candidate)) { continue; }
        $raw  = file_get_contents($candidate);
        $json = json_decode($raw, true);
        if (!$json || !isset($json['news'])) { continue; }
        // analysis と cms_url をキーワード単位で取得
        $analysis = isset($json['analysis']) ? $json['analysis'] : '';
        $cms_url  = 'https://aiknowledgecms.exbridge.jp/aithinkingmedia.php?kw=' . rawurlencode($kw) . '&base_date=' . $date;
        $count = 0;
        foreach ($json['news'] as $n) {
            if ($count >= 3) { break; }
            $items[] = array(
                'title'    => $n['title'],
                'summary'  => isset($n['summary']) ? $n['summary'] : '',
                'source'   => 'Google News',
                'link'     => $n['link'],
                'keyword'  => $kw,
                'date'     => $date,
                'analysis' => $analysis,
                'cms_url'  => $cms_url,
            );
            $count++;
        }
        break; // 最新の1日分だけ取得
    }
    echo json_encode(array('ok' => true, 'news' => $items));
    exit;
}

// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
// API: キーワード保存
// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
if (isset($_GET['action']) && $_GET['action'] === 'save_keywords') {
    header('Content-Type: application/json; charset=UTF-8');
    $body     = file_get_contents('php://input');
    $req      = json_decode($body, true);
    $account  = isset($req['account'])  ? trim($req['account'])  : '';
    $keywords = isset($req['keywords']) ? $req['keywords']       : array();
    $user     = isset($req['user'])     ? $req['user']           : array();
    if (!$account || !$keywords) {
        echo json_encode(array('ok' => false, 'reason' => 'missing_params'));
        exit;
    }
    $file = __DIR__ . '/data/keyword_' . preg_replace('/[^a-zA-Z0-9_]/', '', $account) . '.json';
    // 既存ファイルにuserがあれば保持、新しいuserが来た場合は上書き
    $existing_user = array();
    if (file_exists($file)) {
        $existing = json_decode(file_get_contents($file), true);
        if (isset($existing['user'])) { $existing_user = $existing['user']; }
    }
    $save_user = !empty($user) ? $user : $existing_user;
    $data = array('account' => $account, 'keywords' => $keywords, 'user' => $save_user, 'updated' => date('Y-m-d'));
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo json_encode(array('ok' => true));
    exit;
}

// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
// API: 関連アカウントのニュース取得
// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
if (isset($_GET['action']) && $_GET['action'] === 'get_related_news') {
    header('Content-Type: application/json; charset=UTF-8');
    $body    = file_get_contents('php://input');
    $req     = json_decode($body, true);
    $account = isset($req['account'])  ? trim($req['account'])  : '';
    $mykws   = isset($req['keywords']) ? $req['keywords']       : array();
    if (!$account || !$mykws) {
        echo json_encode(array('ok' => false, 'reason' => 'missing_params'));
        exit;
    }
    $data_dir = __DIR__ . '/data/';
    $results  = array();
    foreach (glob($data_dir . 'keyword_*.json') as $f) {
        $raw  = file_get_contents($f);
        $json = json_decode($raw, true);
        if (!$json || !isset($json['account']) || !isset($json['keywords'])) { continue; }
        if ($json['account'] === $account) { continue; }
        $other_account = $json['account'];
        $common = array_intersect($mykws, $json['keywords']);
        if (empty($common)) { continue; }
        foreach ($common as $kw) {
            for ($i = 0; $i < 30; $i++) {
                $date      = date('Y-m-d', strtotime('-' . $i . ' days'));
                $candidate = $data_dir . $date . '_' . $kw . '.json';
                if (!file_exists($candidate)) { continue; }
                $nraw  = file_get_contents($candidate);
                $njson = json_decode($nraw, true);
                if (!$njson || !isset($njson['news'])) { continue; }
                $analysis = isset($njson['analysis']) ? $njson['analysis'] : '';
                $cms_url  = 'https://aiknowledgecms.exbridge.jp/aithinkingmedia.php?kw=' . rawurlencode($kw) . '&base_date=' . $date;
                foreach ($njson['news'] as $n) {
                    $results[] = array(
                        'title'           => $n['title'],
                        'summary'         => isset($n['summary']) ? $n['summary'] : '',
                        'source'          => 'Google News',
                        'link'            => $n['link'],
                        'keyword'         => $kw,
                        'date'            => $date,
                        'related_account' => $other_account,
                        'analysis'        => $analysis,
                        'cms_url'         => $cms_url,
                    );
                }
            }
        }
    }
    echo json_encode(array('ok' => true, 'news' => $results));
    exit;
}

// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
// OAuth2 PKCE
// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
session_start();

$keys_file = __DIR__ . '/x_api_keys.sh';
$keys = array();
if (file_exists($keys_file)) {
    $lines = file($keys_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (preg_match('/(?:export\s+)?(\w+)=["\']?([^"\'#\r\n]*)["\']?/', $line, $m)) {
            $keys[trim($m[1])] = trim($m[2]);
        }
    }
}
$client_id     = isset($keys['X_API_KEY'])    ? $keys['X_API_KEY']    : '';
$client_secret = isset($keys['X_API_SECRET']) ? $keys['X_API_SECRET'] : '';
$redirect_uri  = 'https://aiknowledgecms.exbridge.jp/airadarx.php';

function base64url($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function gen_code_verifier() {
    $bytes = '';
    for ($i = 0; $i < 32; $i++) {
        $bytes .= chr(mt_rand(0, 255));
    }
    return base64url($bytes);
}
function gen_code_challenge($verifier) {
    return base64url(hash('sha256', $verifier, true));
}
function x_api_get($url, $params, $token) {
    $full = count($params) ? $url . '?' . http_build_query($params) : $url;
    $opts = array(
        'http' => array(
            'method'        => 'GET',
            'header'        => "Authorization: Bearer $token\r\nUser-Agent: AIRadarX/1.0\r\n",
            'timeout'       => 12,
            'ignore_errors' => true,
        )
    );
    $ctx = stream_context_create($opts);
    $res = @file_get_contents($full, false, $ctx);
    if (!$res) { $res = '{}'; }
    return json_decode($res, true);
}
function x_api_post($url, $post_data, $headers) {
    $opts = array(
        'http' => array(
            'method'        => 'POST',
            'header'        => implode("\r\n", $headers) . "\r\n",
            'content'       => $post_data,
            'timeout'       => 12,
            'ignore_errors' => true,
        )
    );
    $ctx = stream_context_create($opts);
    $res = @file_get_contents($url, false, $ctx);
    if (!$res) { $res = '{}'; }
    return json_decode($res, true);
}

// logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $redirect_uri);
    exit;
}

// OAuth callback
if (isset($_GET['code']) && isset($_GET['state'])) {
    $saved_state    = isset($_SESSION['oauth_state'])   ? $_SESSION['oauth_state']   : '';
    $saved_verifier = isset($_SESSION['code_verifier']) ? $_SESSION['code_verifier'] : '';
    if ($_GET['state'] !== $saved_state) {
        die('State mismatch. <a href="' . $redirect_uri . '">戻る</a>');
    }
    $post = http_build_query(array(
        'grant_type'    => 'authorization_code',
        'code'          => $_GET['code'],
        'redirect_uri'  => $redirect_uri,
        'code_verifier' => $saved_verifier,
        'client_id'     => $client_id,
    ));
    $cred = base64_encode($client_id . ':' . $client_secret);
    $data = x_api_post('https://api.twitter.com/2/oauth2/token', $post, array(
        'Content-Type: application/x-www-form-urlencoded',
        'Authorization: Basic ' . $cred,
    ));
    if (isset($data['access_token'])) {
        $_SESSION['access_token'] = $data['access_token'];
        unset($_SESSION['oauth_state'], $_SESSION['code_verifier']);
        $me = x_api_get('https://api.twitter.com/2/users/me', array(), $data['access_token']);
        if (isset($me['data']['username'])) {
            $_SESSION['session_username'] = $me['data']['username'];
        }
        header('Location: ' . $redirect_uri);
    } else {
        $err = isset($data['error_description']) ? $data['error_description'] : json_encode($data);
        die('Token error: ' . htmlspecialchars($err) . ' <a href="' . $redirect_uri . '">戻る</a>');
    }
    exit;
}

// OAuth start
if (isset($_GET['login'])) {
    $verifier  = gen_code_verifier();
    $challenge = gen_code_challenge($verifier);
    $state     = md5(uniqid('', true));
    $_SESSION['code_verifier'] = $verifier;
    $_SESSION['oauth_state']   = $state;
    $params = array(
        'response_type'         => 'code',
        'client_id'             => $client_id,
        'redirect_uri'          => $redirect_uri,
        'scope'                 => 'tweet.read users.read tweet.write offline.access',
        'state'                 => $state,
        'code_challenge'        => $challenge,
        'code_challenge_method' => 'S256',
    );
    header('Location: https://twitter.com/i/oauth2/authorize?' . http_build_query($params));
    exit;
}

// API proxy
$action = isset($_GET['action']) ? $_GET['action'] : '';
if ($action) {
    header('Content-Type: application/json');
    $token = isset($_SESSION['access_token']) ? $_SESSION['access_token'] : '';
    if (!$token) {
        echo json_encode(array('ok' => false, 'error' => 'not_logged_in'));
        exit;
    }
    if ($action === 'lookup_user') {
        $uname = isset($_GET['username']) ? trim($_GET['username']) : '';
        if (!$uname) {
            echo json_encode(array('ok' => false, 'error' => 'username required'));
            exit;
        }
        $data = x_api_get(
            'https://api.twitter.com/2/users/by/username/' . rawurlencode($uname),
            array('user.fields' => 'description,public_metrics,created_at'),
            $token
        );
        if (isset($data['data'])) {
            echo json_encode(array('ok' => true, 'user' => $data['data']));
        } else {
            $err = isset($data['errors'][0]['message']) ? $data['errors'][0]['message'] : 'unknown';
            echo json_encode(array('ok' => false, 'error' => $err));
        }
        exit;
    }
    if ($action === 'me') {
        $data = x_api_get(
            'https://api.twitter.com/2/users/me',
            array('user.fields' => 'description,public_metrics,created_at'),
            $token
        );
        if (isset($data['data'])) {
            echo json_encode(array('ok' => true, 'user' => $data['data']));
        } else {
            $err = isset($data['errors'][0]['message']) ? $data['errors'][0]['message'] : 'unknown';
            echo json_encode(array('ok' => false, 'error' => $err));
        }
        exit;
    }
    if ($action === 'tweets') {
        $uid = isset($_GET['user_id']) ? $_GET['user_id'] : '';
        if (!$uid) {
            echo json_encode(array('ok' => false, 'error' => 'user_id required'));
            exit;
        }
        $data = x_api_get(
            'https://api.twitter.com/2/users/' . rawurlencode($uid) . '/tweets',
            array('max_results' => 10, 'tweet.fields' => 'text,created_at', 'exclude' => 'retweets,replies'),
            $token
        );
        if (isset($data['data'])) {
            echo json_encode(array('ok' => true, 'tweets' => $data['data']));
        } else {
            $err = isset($data['errors'][0]['message']) ? $data['errors'][0]['message'] : 'unknown';
            echo json_encode(array('ok' => false, 'error' => $err));
        }
        exit;
    }
    echo json_encode(array('ok' => false, 'error' => 'unknown action'));
    exit;
}

$logged_in    = isset($_SESSION['access_token']) && $_SESSION['access_token'] !== '';
$session_user = isset($_SESSION['session_username']) ? $_SESSION['session_username'] : '';
if (isset($_GET['action']) && $_GET['action'] === 'save_username' && $logged_in) {
    $uname = isset($_GET['username']) ? trim($_GET['username']) : '';
    if ($uname) { $_SESSION['session_username'] = $uname; }
    header('Content-Type: application/json');
    echo json_encode(array('ok' => true));
    exit;
}
?><!DOCTYPE html>
<html lang="ja">
<head>
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-BP0650KDFR"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'G-BP0650KDFR');
</script>
<script>
(function () {
    var s = document.createElement('script');
    s.src = 'https://aiknowledgecms.exbridge.jp/simpletrack.php'
        + '?url=' + encodeURIComponent(location.href)
        + '&ref=' + encodeURIComponent(document.referrer);
    document.head.appendChild(s);
})();
</script>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AIRadarX — シャドーバンチェック・Xアカウント診断・AIキーワード分析</title>
<meta name="description" content="AIRadarXはXアカウントのシャドーバン診断・プロフィール分析・AIキーワード抽出・関連ニュース収集・投稿文自動生成ができる無料ツールです。">
<meta name="keywords" content="シャドーバン,シャドーバンチェック,Xアカウント診断,Twitter診断,AIキーワード分析,AIRadarX">
<link rel="canonical" href="https://aiknowledgecms.exbridge.jp/airadarx.php">
<!-- OGP -->
<meta property="og:type" content="website">
<meta property="og:title" content="AIRadarX — シャドーバンチェック・Xアカウント診断">
<meta property="og:description" content="Xアカウントのシャドーバン診断・AI分析・ニュース収集・投稿文生成が無料でできます。">
<meta property="og:url" content="https://aiknowledgecms.exbridge.jp/airadarx.php">
<meta property="og:site_name" content="AIRadarX">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="AIRadarX — シャドーバンチェック・Xアカウント診断">
<meta name="twitter:description" content="Xアカウントのシャドーバン診断・AI分析・ニュース収集・投稿文生成が無料でできます。">
<!-- 構造化データ -->
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "WebApplication",
  "name": "AIRadarX",
  "url": "https://aiknowledgecms.exbridge.jp/airadarx.php",
  "description": "Xアカウントのシャドーバン診断・AIキーワード分析・関連ニュース収集・投稿文自動生成ツール",
  "applicationCategory": "SocialNetworkingApplication",
  "operatingSystem": "Web",
  "offers": {"@type": "Offer", "price": "0", "priceCurrency": "JPY"},
  "keywords": "シャドーバン,Xアカウント診断,AIキーワード分析,Twitter診断"
}
</script>
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Exo+2:wght@300;400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="airadarx.css">
</head>
<body>
<div class="app">
  <div class="header">
    <a href="airadarx.php" class="logo">AIRadarX</a>
    <div class="tagline">◈ REAL X DATA · AI ANALYSIS · NEWS RADAR · POST GENERATOR ◈</div>
    <div class="loginbar">
      <?php if ($logged_in): ?>
        <span class="user" id="username-display">● logged in</span>
        <a href="?logout=1">logout</a>
      <?php else: ?>
        <a href="?login=1">X でログイン</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!$logged_in): ?>
  <div class="gate">
    <div class="gate-inner">
      <div class="gate-logo">◎</div>
      <div class="gate-msg">
        <div>AIRadarX — Xアカウント分析ハブ</div>
        <div class="gate-features">
          <div>◈ シャドーバン・アカウント健全性を多角診断</div>
          <div>◈ AIがプロフィール・投稿からキーワードを自動抽出</div>
          <div>◈ キーワードに関連するニュースをリアルタイム収集</div>
          <div>◈ AIがニュースを分析してXポスト草稿を自動生成</div>
          <div>◈ AIKnowledgeSNSで知識でつながる人を発見</div>
        </div>
      </div>
      <a href="?login=1" class="xbtn">X でログイン</a>
      <div class="gate-assoc">
        <div class="gate-assoc-title">◯ AMAZON アソシエイトで収益を得る</div>
        <div class="gate-assoc-body">
          ログイン後、AIKnowledgeSNSのマイページで<strong>Amazonアソシエイト ID</strong>を登録すると、
          あなたのページに広告が表示され、閲覧者が購入した場合に<strong>収益を得る</strong>ことができます。<br><br>
          キーワードに基づいた関連商品が自動表示されます。
        </div>
      </div>
      <div id="aigm-ad-gate" style="margin-top:16px;"></div>
    </div>
  </div>
<script>
function aigmAdGateLogin(data) {
  if (!data || !data.ok || !data.html) { return; }
  var c = document.getElementById('aigm-ad-gate');
  if (c) { c.innerHTML = data.html; }
}
(function() {
  var s = document.createElement('script');
  s.src = 'https://aiknowledgecms.exbridge.jp/adwidget.php?callback=aigmAdGateLogin&slot=gate&limit=3';
  document.body.appendChild(s);
})();
</script>

  <?php else: ?>
  <div class="grid">
    <div>
      <div class="panel">
        <div class="pt">◯ RADAR SCAN</div>
        <div class="radar-wrap">
          <svg class="rsv" viewBox="0 0 190 190">
            <defs>
              <radialGradient id="rg" cx="50%" cy="50%">
                <stop offset="0%" stop-color="#00ff88" stop-opacity="0.18"/>
                <stop offset="100%" stop-color="#00ff88" stop-opacity="0"/>
              </radialGradient>
            </defs>
            <circle cx="95" cy="95" r="28" fill="none" stroke="#00ff88" stroke-width=".5" stroke-opacity=".3"/>
            <circle cx="95" cy="95" r="52" fill="none" stroke="#00ff88" stroke-width=".5" stroke-opacity=".25"/>
            <circle cx="95" cy="95" r="76" fill="none" stroke="#00ff88" stroke-width=".5" stroke-opacity=".2"/>
            <line x1="95" y1="8"   x2="95"  y2="182" stroke="#00ff88" stroke-width=".3" stroke-opacity=".2"/>
            <line x1="8"  y1="95"  x2="182" y2="95"  stroke="#00ff88" stroke-width=".3" stroke-opacity=".2"/>
            <line x1="28" y1="28"  x2="162" y2="162" stroke="#00ff88" stroke-width=".3" stroke-opacity=".15"/>
            <line x1="162" y1="28" x2="28"  y2="162" stroke="#00ff88" stroke-width=".3" stroke-opacity=".15"/>
            <g id="sweep-grp" class="sweep-grp">
              <path d="M95,95 L95,12 A83,83 0 0,1 165,155 Z" fill="url(#rg)" opacity=".75"/>
              <line x1="95" y1="95" x2="95" y2="13" stroke="#00ff88" stroke-width="1.5" stroke-opacity=".9"/>
            </g>
            <circle cx="95" cy="95" r="3" fill="#00ff88" opacity=".95"/>
          </svg>
          <div class="sbar" id="sbar"></div>
        </div>
        <div class="stl" id="stl">STANDBY</div>
      </div>

      <div class="panel">
        <div class="pt">◯ TARGET ACCOUNT</div>
        <?php if ($session_user === 'xb_bittensor'): ?>
        <div id="admin-input-area" style="margin-bottom:10px;">
          <input id="target-input" type="text" placeholder="@username（空欄=自分）"
            style="width:100%;padding:8px 10px;background:rgba(0,255,136,.06);border:1px solid rgba(0,255,136,.3);border-radius:3px;color:#c8ffe8;font-family:'Share Tech Mono',monospace;font-size:.8rem;outline:none;">
        </div>
        <?php endif; ?>
        <div id="target-display" style="font-family:'Share Tech Mono',monospace;font-size:.82rem;color:var(--muted);padding:8px 12px;background:rgba(0,255,136,.06);border:1px solid rgba(0,255,136,.2);border-radius:3px;margin-bottom:12px;"> —</div>
        <button class="btn" id="scanbtn" onclick="runScan()">▶ START SCAN</button>
      </div>

      <div class="panel" id="ppanel" style="display:none">
        <div class="pt">◯ X PROFILE</div>
        <div class="pcard" id="pcard"></div>
        <div class="pt" id="kwpt" style="display:none">◯ EXTRACTED KEYWORDS</div>
        <div class="kwlist" id="kwlist"></div>
      </div>

      <div class="panel" id="logpanel" style="display:none">
        <div class="pt">◯ SYSTEM LOG</div>
        <div class="logbox" id="logbox"></div>
      </div>
    </div>

    <div>
      <!-- ======== 診断ハブ ======== -->
      <div class="panel diag-hub" id="diaghub">
        <div class="pt pt-orange">◯ ACCOUNT DIAGNOSIS HUB</div>
        <div style="font-size:.76rem;color:var(--muted);margin-bottom:12px;line-height:1.6;">
          Xアカウントの健康状態を多角的に診断します。各ツールで調べた結果をAIが総合分析します。
        </div>
        <div id="aigm-ad-hub" style="margin-bottom:12px;"></div>
        <div class="diag-grid" id="diaggrid">
          <!-- JSで動的生成 -->
        </div>
        <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
          <button class="bsm borg" id="aidiagbtn" onclick="runAIDiagnosis()" disabled>🤖 AI総合診断</button>
          <span style="font-family:'Share Tech Mono',monospace;font-size:.65rem;color:var(--vm);">
            ↑ 外部ツールで確認後にクリック
          </span>
        </div>
        <div class="airesult-box" id="airesultbox">
          <div class="airesult-label">◯ AI DIAGNOSIS RESULT</div>
          <div id="airesulttext"></div>
        </div>
      </div>

      <!-- ======== AIKnowledgeSNS誘導バナー ======== -->
      <div class="aikns-banner" id="aiknsbanner">
        <div class="aikns-banner-title">◈ AIKnowledgeSNS へ</div>
        <div class="aikns-banner-body">
          診断でXの課題が見つかりましたか？<br>
          <span class="aikns-banner-highlight">AIKnowledgeSNS</span>では、あなたのキーワード知識でつながれる人を発見できます。<br>
          フォローすべきアカウントが、知識グラフで見つかります。
        </div>
        <div class="aikns-banner-actions">
          <a href="https://aiknowledgecms.exbridge.jp/aiknowledgesns.php" target="_blank" class="diag-btn diag-btn-green">→ AIKnowledgeSNSへ</a>
          <button class="bsm" onclick="dismissBanner()">後で</button>
        </div>
      </div>

      <!-- ======== AIKnowledgeSNS紹介 ======== -->
      <div class="panel aikns-intro" id="aikns-intro">
        <div class="pt">◯ AIKnowledgeSNS</div>
        <div class="aikns-intro-body">
          <div class="aikns-intro-catchcopy">知識でつながる、次世代のXサポートSNS</div>
          <div class="aikns-intro-desc">
            AIRadarXで分析したキーワードをもとに、同じ知識を持つXアカウントを発見できます。
            フォローよりも前に、<strong>知識グラフ</strong>で相手を見つけましょう。
          </div>
          <div class="aikns-intro-features">
            <div class="aikns-feat">◈ キーワードで繋がるアカウント推薦</div>
            <div class="aikns-feat">◈ AIKnowledgeCMSの考察をSNSで共有</div>
            <div class="aikns-feat">◈ いいねでXフォローへの自然な導線</div>
            <div class="aikns-feat aikns-feat-gold">◈ Amazonアソシエイト IDを登録するだけで、あなたのページに広告が自動表示。閲覧者の購入で収益を得られる可能性があります。</div>
          </div>
          <div class="aikns-intro-actions">
            <a href="https://aiknowledgecms.exbridge.jp/aiknowledgesns.php" target="_blank" class="aikns-intro-btn">
              → AIKnowledgeSNSを使う
            </a>
          </div>
        </div>
      </div>

      <div class="panel empty" id="emptystate">
        <div>
          <div class="eicon">◎</div>
          <div>START SCANを押すと</div>
          <div>あなたのXデータで</div>
          <div>分析が始まります</div>
        </div>
      </div>
      <div id="newssec" style="display:none">
        <div class="panel">
          <div class="pt" id="newspt">◯ DETECTED NEWS</div>
          <div id="newslist"></div>
        </div>
      </div>
    </div>
  </div>

<script>
var xTweets   = [];
var keywords  = [];
var newsItems = [];
var scanning  = false;
var isAdmin   = <?php echo ($session_user === 'xb_bittensor') ? 'true' : 'false'; ?>;
var currentUsername = '';

// ---- 診断ツール定義 ----
var DIAG_TOOLS = [
  {
    id: 'shadowban',
    name: 'シャドーバン診断',
    icon: '👻',
    desc: '検索除外・返信制限・BANの有無をチェック',
    color: 'orange',
    btnClass: 'diag-btn-orange',
    url: function(u){ return 'https://shadowban.yuzurisa.com/' + u; }
  },
  {
    id: 'shadowban2',
    name: 'シャドーバン (EU)',
    icon: '🔍',
    desc: '複数タイプのシャドーバンを詳細診断',
    color: 'orange',
    btnClass: 'diag-btn-orange',
    url: function(u){ return 'https://shadowban.eu/@' + u; }
  },
  {
    id: 'foller',
    name: 'プロフィール分析',
    icon: '👤',
    desc: 'ツイート傾向・感情分析・活動時間帯を表示',
    color: '',
    btnClass: 'diag-btn-green',
    url: function(u){ return 'https://foller.me/' + u; }
  },
  {
    id: 'tweethunter',
    name: 'メトリクス計算',
    icon: '📊',
    desc: '平均インプレ・エンゲージメント率を計算',
    color: 'blue',
    btnClass: 'diag-btn-blue',
    url: function(u){ return 'https://tweethunter.io/metrics-calculator?handle=' + u; }
  },
  {
    id: 'followerwonk',
    name: 'フォロワー分析',
    icon: '👥',
    desc: 'フォロワーのデモグラフィック・bio検索',
    color: 'blue',
    btnClass: 'diag-btn-blue',
    url: function(u){ return 'https://followerwonk.com/analyze/' + u; }
  },
  {
    id: 'allhashtag',
    name: 'ハッシュタグ分析',
    icon: '#️⃣',
    desc: '関連ハッシュタグの効果・トレンドを確認',
    color: '',
    btnClass: 'diag-btn-green',
    url: function(){ return 'https://www.all-hashtag.com/'; }
  },
  {
    id: 'twitonomy',
    name: 'アカウント詳細分析',
    icon: '🔬',
    desc: 'ツイート履歴・競合比較・詳細統計',
    color: '',
    btnClass: 'diag-btn-green',
    url: function(u){ return 'https://www.twitonomy.com/profile.php?sn=' + u; }
  },
  {
    id: 'xanalytics',
    name: 'X公式アナリティクス',
    icon: '📈',
    desc: 'インプレ・エンゲージメント公式データ（Premium）',
    color: 'blue',
    btnClass: 'diag-btn-blue',
    url: function(){ return 'https://analytics.twitter.com/'; }
  }
];

function g(id) { return document.getElementById(id); }
function setStatus(t) { g('stl').innerHTML = t; }

function setSweep(on) {
  g('sweep-grp').className = on ? 'sweep-grp on' : 'sweep-grp';
  g('sbar').className      = on ? 'sbar on'      : 'sbar';
}

function addLog(msg, type) {
  var box = g('logbox');
  g('logpanel').style.display = '';
  var cls = '';
  if (type === 'debug') { cls = ' class="log-debug"'; }
  if (type === 'error') { cls = ' class="log-error"'; }
  box.innerHTML += '<div' + cls + '>' + msg + '</div>';
  box.scrollTop  = box.scrollHeight;
}

function parseObj(text) {
  if (!text) { return null; }
  text = text.replace(/```json/g, '').replace(/```/g, '');
  var s = text.indexOf('{');
  var e = text.lastIndexOf('}');
  if (s === -1 || e === -1) { return null; }
  try { return JSON.parse(text.substring(s, e + 1)); } catch (ex) { return null; }
}

function parseArr(text) {
  if (!text) { return []; }
  text = text.replace(/```json/g, '').replace(/```/g, '');
  var s = text.indexOf('[');
  var e = text.lastIndexOf(']');
  if (s === -1 || e === -1) { return []; }
  try { return JSON.parse(text.substring(s, e + 1)); } catch (ex) { return []; }
}

function xhrGet(url, cb) {
  var xhr = new XMLHttpRequest();
  xhr.open('GET', url, true);
  xhr.onreadystatechange = function () {
    if (xhr.readyState !== 4) { return; }
    try { cb(null, JSON.parse(xhr.responseText)); }
    catch (e) { cb(e, null); }
  };
  xhr.send();
}

function callOllama(prompt, systemPrompt, cb) {
  var xhr = new XMLHttpRequest();
  xhr.open('POST', '?action=analyze', true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onreadystatechange = function () {
    if (xhr.readyState !== 4) { return; }
    addLog('[DEBUG] analyze HTTP ' + xhr.status, 'debug');
    try {
      var data = JSON.parse(xhr.responseText);
      if (!data.ok) {
        addLog('[ERROR] Ollama失敗: ' + (data.reason || '不明'), 'error');
        cb('error', '');
        return;
      }
      var resp = data.response || '';
      addLog('[DEBUG] Ollama: ' + resp.substring(0, 60), 'debug');
      cb(null, resp);
    } catch (e) {
      addLog('[ERROR] レスポンス解析失敗', 'error');
      cb(e, '');
    }
  };
  xhr.send(JSON.stringify({ prompt: prompt, system: systemPrompt || '' }));
}

// ---- 診断ハブ描画 ----
function renderDiagHub(username) {
  var grid = g('diaggrid');
  grid.innerHTML = '';
  for (var i = 0; i < DIAG_TOOLS.length; i++) {
    (function(tool) {
      var card = document.createElement('div');
      card.className = 'diag-card' + (tool.color ? ' ' + tool.color : '');
      var url = tool.url(username);
      card.innerHTML =
        '<div class="diag-icon">' + tool.icon + '</div>' +
        '<div class="diag-name">' + tool.name + '</div>' +
        '<div class="diag-desc">' + tool.desc + '</div>' +
        '<a href="' + url + '" target="_blank" class="diag-btn ' + tool.btnClass + '" ' +
          'onclick="onDiagClick(\'' + tool.id + '\')">診断する →</a>';
      grid.appendChild(card);
    })(DIAG_TOOLS[i]);
  }
  g('aidiagbtn').disabled = false;
}

function onDiagClick(toolId) {
  addLog('[DEBUG] 診断ツール使用: ' + toolId, 'debug');
  var btn = g('aidiagbtn');
  btn.style.animation = 'pulse .6s ease 3';
}

// ---- AI総合診断 ----
function runAIDiagnosis() {
  var btn = g('aidiagbtn');
  btn.disabled = true;
  btn.textContent = '分析中...';
  addLog('AI総合診断開始...');
  var kwText = keywords.length > 0 ? keywords.join(', ') : '不明';
  var prompt =
    'Xアカウント @' + currentUsername + ' の総合診断をしてください。\n' +
    'このアカウントのキーワード: ' + kwText + '\n\n' +
    '以下の観点で日本語で診断・アドバイスをしてください：\n' +
    '1. シャドーバンリスク（スパム判定を避けるための注意点）\n' +
    '2. エンゲージメント改善のヒント\n' +
    '3. フォロワー獲得のための投稿戦略\n' +
    '4. このキーワード分野で知識でつながれる人を見つける方法\n\n' +
    '200字程度で簡潔にまとめてください。';
  callOllama(prompt, 'あなたはXアカウント診断の専門AIです。日本語で実践的なアドバイスをしてください。', function(err, text) {
    var resultBox = g('airesultbox');
    var resultText = g('airesulttext');
    resultBox.className = 'airesult-box show';
    if (err || !text) {
      resultText.innerHTML = '<span style="color:var(--red)">診断に失敗しました。Ollamaの接続を確認してください。</span>';
    } else {
      resultText.innerHTML = text.replace(/\n/g, '<br>');
      showAIKnsBanner();
    }
    btn.disabled = false;
    btn.textContent = '🤖 AI総合診断';
    addLog('AI総合診断完了');
  });
}

// ---- AIKnowledgeSNS誘導バナー ----
function showAIKnsBanner() {
  var banner = g('aiknsbanner');
  if (keywords.length > 0) {
    var kw = keywords[0];
    g('aiknsbanner').querySelector('.aikns-banner-body').innerHTML =
      '診断でXの課題が見つかりましたか？<br>' +
      '<span class="aikns-banner-highlight">AIKnowledgeSNS</span>では、' +
      '「<strong>' + kw + '</strong>」などのキーワードでつながれる人を発見できます。<br>' +
      'Xのフォローよりも前に、知識グラフで相手を見つけましょう。';
  }
  banner.className = 'aikns-banner show';
}

function dismissBanner() {
  g('aiknsbanner').style.display = 'none';
}

function fetchMe(cb) {
  addLog('Xプロフィール取得中...');
  xhrGet('?action=me', function (err, data) {
    if (err || !data || !data.ok) {
      addLog('[ERROR] プロフィール取得失敗: ' + JSON.stringify(data), 'error');
      cb('error');
      return;
    }
    var user = data.user;
    g('username-display').textContent = '● @' + user.username;
    xhrGet('?action=tweets&user_id=' + user.id, function (err2, tw) {
      if (!err2 && tw && tw.ok && tw.tweets) {
        xTweets = tw.tweets;
        addLog('ツイート取得: ' + xTweets.length + '件');
      } else {
        addLog('[DEBUG] ツイート取得スキップ', 'debug');
      }
      cb(null, { user: user, tweets: xTweets });
    });
  });
}

function fetchTargetUser(username, cb) {
  addLog('対象アカウント取得中: @' + username);
  xhrGet('?action=lookup_user&username=' + encodeURIComponent(username), function (err, data) {
    if (err || !data || !data.ok) {
      addLog('[ERROR] ユーザー取得失敗: ' + JSON.stringify(data), 'error');
      cb('error');
      return;
    }
    var user = data.user;
    addLog('ユーザー取得OK: @' + user.username);
    xhrGet('?action=tweets&user_id=' + user.id, function (err2, tw) {
      var tweets = [];
      if (!err2 && tw && tw.ok && tw.tweets) {
        tweets = tw.tweets;
        addLog('ツイート取得: ' + tweets.length + '件');
      } else {
        addLog('[DEBUG] ツイート取得スキップ（権限不足の可能性）', 'debug');
      }
      cb(null, { user: user, tweets: tweets });
    });
  });
}

function analyzeAccount(user, tweets, isTarget, cb) {
  addLog('AIキーワード分析中...');
  var tweetText = '';
  for (var i = 0; i < tweets.length && tweetText.length < 1200; i++) {
    tweetText += tweets[i].text + '\n';
  }
  var prompt =
    'Xアカウントを分析してキーワードを5個抽出してください。\n' +
    'ユーザー名: ' + (user.name || user.username) + '\n' +
    'bio: ' + (user.description || 'なし') + '\n' +
    '投稿:\n' + tweetText + '\n\n' +
    '必ず以下のJSON形式のみ返してください。説明不要。\n' +
    '{"keywords":["キーワード1","キーワード2","キーワード3","キーワード4","キーワード5"]}';

  callOllama(prompt, 'あなたはSNS分析AIです。JSONのみ出力してください。', function (err, text) {
    var extracted = [];
    if (!err) {
      var obj = parseObj(text);
      if (obj && obj.keywords && obj.keywords.length > 0) {
        extracted = obj.keywords;
        addLog('キーワード（AI抽出）: ' + extracted.join(', '));
      }
    }
    if (extracted.length === 0) {
      addLog('[DEBUG] キーワード抽出失敗。デフォルト使用', 'debug');
      extracted = ['AI', 'テクノロジー', 'ヘルスケア', 'DX', '介護'];
    }
    if (isTarget) {
      var extra = [];
      if (user.username) { extra.push(user.username); }
      if (user.name && user.name !== user.username) { extra.push(user.name); }
      if (user.id) { extra.push(user.id); }
      extracted = extra.concat(extracted);
      addLog('追加キーワード（アカウント名/ID）: ' + extra.join(', '));
    }
    cb(null, { keywords: extracted });
  });
}

function fetchNewsForKeyword(kw, cb) {
  addLog('ニュース検索: ' + kw);
  xhrGet('?action=news_from_cms&kw=' + encodeURIComponent(kw), function (err, data) {
    addLog('[DEBUG] kw=' + kw + ' ok=' + (data ? data.ok : 'err') + (data && data.reason ? ' reason=' + data.reason : ''), 'debug');
    if (!err && data && data.ok && data.news) {
      cb(null, data.news);
    } else {
      cb('no_news', []);
    }
  });
}

function fetchAllNews(account, kws, cb) {
  var results  = [];
  var foundKws = [];
  var limit    = kws.length < 10 ? kws.length : 10;
  var done     = 0;
  if (limit === 0) { cb([], []); return; }
  for (var i = 0; i < limit; i++) {
    (function (kw) {
      fetchNewsForKeyword(kw, function (err, items) {
        items = items || [];
        if (items.length > 0) {
          foundKws.push(kw);
          for (var j = 0; j < items.length; j++) { results.push(items[j]); }
        }
        done++;
        if (done >= limit) { cb(results, foundKws); }
      });
    })(kws[i]);
  }
}

function saveKeywords(account, kws, user, cb) {
  var xhr = new XMLHttpRequest();
  xhr.open('POST', '?action=save_keywords', true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onreadystatechange = function () {
    if (xhr.readyState !== 4) { return; }
    addLog('[DEBUG] save_keywords HTTP ' + xhr.status, 'debug');
    if (cb) { cb(); }
  };
  xhr.send(JSON.stringify({ account: account, keywords: kws, user: user || {} }));
}

function fetchRelatedNews(account, kws, cb) {
  var xhr = new XMLHttpRequest();
  xhr.open('POST', '?action=get_related_news', true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onreadystatechange = function () {
    if (xhr.readyState !== 4) { return; }
    try {
      var data = JSON.parse(xhr.responseText);
      cb(null, (data.ok && data.news) ? data.news : []);
    } catch(e) { cb(e, []); }
  };
  xhr.send(JSON.stringify({ account: account, keywords: kws }));
}

function sortNewsByDate(items) {
  return items.slice().sort(function(a, b) {
    return (b.date || '').localeCompare(a.date || '');
  });
}

function renderNews(items) {
  // 上限15件に絞る
  items = items.slice(0, 15);
  newsItems = items;
  g('emptystate').style.display = 'none';
  g('newssec').style.display    = '';
  g('newspt').textContent = '◯ DETECTED NEWS — ' + items.length + ' SIGNALS';
  var list = g('newslist');
  list.innerHTML = '';
  if (items.length === 0) {
    list.innerHTML = '<div style="font-family:\'Share Tech Mono\',monospace;font-size:.8rem;color:var(--muted);padding:20px 0;text-align:center;">ニュースJSONファイルが見つかりません（過去30日分検索済み）<br><small style="color:var(--vm)">data/YYYY-MM-DD_キーワード.json を確認してください</small></div>';
    return;
  }

  // キーワード+dateでグループ化（同一キーワードの考察は1回だけ表示）
  var groups = [];
  var groupIndex = {};
  for (var i = 0; i < items.length; i++) {
    var item    = items[i];
    var groupKey = item.keyword + '|' + (item.date || '') + '|' + (item.related_account || '');
    if (groupIndex[groupKey] === undefined) {
      groupIndex[groupKey] = groups.length;
      groups.push({
        keyword:         item.keyword,
        date:            item.date || '',
        related_account: item.related_account || '',
        analysis:        item.analysis || '',
        cms_url:         item.cms_url  || '',
        news:            []
      });
    }
    groups[groupIndex[groupKey]].news.push(item);
  }

  for (var g_i = 0; g_i < groups.length; g_i++) {
    var grp     = groups[g_i];
    var wrapper = document.createElement('div');
    wrapper.className = 'ngroup';

    // グループヘッダー（キーワード + 日付 + related_account）
    var relatedHtml = grp.related_account
      ? '<span style="font-family:\'Share Tech Mono\',monospace;font-size:.63rem;color:#1da1f2;background:rgba(29,161,242,.1);border-radius:20px;padding:2px 9px;margin-left:6px;">@' + grp.related_account + '</span>'
      : '';
    var headerHtml =
      '<div class="ngroup-header">' +
        '<span class="nkw">#' + grp.keyword + '</span>' + relatedHtml +
        (grp.date ? '<span style="font-family:\'Share Tech Mono\',monospace;font-size:.6rem;color:var(--vm);margin-left:auto;">' + grp.date + '</span>' : '') +
      '</div>';

    // AI考察（グループに1回だけ）
    var analysisHtml = '';
    if (grp.analysis && grp.cms_url) {
      var shortAnalysis = grp.analysis.length > 120
        ? grp.analysis.substring(0, 120) + '...'
        : grp.analysis;
      analysisHtml =
        '<div class="nanalysis">' +
          '<div class="nanalysis-label">◯ AI考察 — AIKnowledgeCMS</div>' +
          '<div class="nanalysis-body">' + shortAnalysis + '</div>' +
          '<a href="' + grp.cms_url + '" target="_blank" class="nanalysis-link">→ AIKnowledgeCMSで読む</a>' +
        '</div>';
    }

    wrapper.innerHTML = headerHtml + analysisHtml;

    // ニュースアイテム（複数）
    for (var n_i = 0; n_i < grp.news.length; n_i++) {
      (function(item, idx) {
        var card = document.createElement('div');
        card.className = 'ncard ncard-sub';
        var titleHtml = item.link
          ? '<a class="ntitlelink" href="' + item.link + '" target="_blank">' + item.title + '</a>'
          : item.title;
        card.innerHTML =
          '<div class="ntitle">' + titleHtml + '</div>' +
          (item.summary ? '<div class="nsum">' + item.summary + '</div>' : '') +
          '<div class="nfoot">' +
            '<span class="src">via ' + item.source + '</span>' +
            '<button class="bsm" id="gb' + idx + '" onclick="generateDraft(' + idx + ')">✏ 投稿文生成</button>' +
          '</div>' +
          '<div id="dr' + idx + '" style="display:none"></div>';
        wrapper.appendChild(card);
      })(item, newsItems.indexOf(item));
    }

    list.appendChild(wrapper);
  }
}

function generateDraft(idx) {
  var item = newsItems[idx];
  var btn  = g('gb' + idx);
  btn.disabled    = true;
  btn.textContent = '生成中...';
  var prompt =
    '以下のニュースをもとにXの投稿文を3つ作ってください。\n' +
    'タイトル: ' + item.title + '\n' +
    (item.summary ? '概要: ' + item.summary + '\n' : '') +
    'キーワード: ' + item.keyword + '\n\n' +
    '必ず以下のJSON配列のみ返してください。説明不要。\n' +
    '["投稿文1","投稿文2","投稿文3"]';

  callOllama(prompt, 'あなたはSNS投稿生成AIです。JSON配列のみ出力してください。', function (err, text) {
    var drafts = parseArr(text);
    if (drafts.length === 0 && text) { drafts = [text.trim()]; }
    if (drafts.length === 0)         { drafts = ['投稿文の生成に失敗しました']; }
    var sec = g('dr' + idx);
    sec.style.display = '';
    sec.innerHTML = '<div class="dlabel">生成された投稿</div>';
    for (var i = 0; i < drafts.length; i++) {
      sec.innerHTML +=
        '<div class="dbox">' + drafts[i] +
        '<div class="dact">' +
          '<button class="bsm" onclick="copyTxt(' + JSON.stringify(drafts[i]) + ')">コピー</button>' +
          '<button class="bsm bx" onclick="postX(' + JSON.stringify(drafts[i]) + ')">X投稿</button>' +
        '</div></div>';
    }
    btn.textContent = '生成済み';
  });
}

function copyTxt(t) {
  if (navigator.clipboard) { navigator.clipboard.writeText(t); }
}
function postX(t) {
  window.open('https://twitter.com/intent/tweet?text=' + encodeURIComponent(t), '_blank');
}

function showProfile(user) {
  currentUsername = user.username;
  g('target-display').textContent = '@' + user.username;
  g('ppanel').style.display       = '';
  g('pcard').innerHTML =
    '<div class="phandle">@' + user.username + '</div>' +
    '<div>' + (user.description || 'bioなし') + '</div>';
  renderDiagHub(user.username);
}

function runScan() {
  if (scanning) { return; }
  scanning = true;
  keywords = [];
  xTweets  = [];
  g('scanbtn').disabled = true;
  setSweep(true);
  setStatus('スキャン中...');
  g('airesultbox').className = 'airesult-box';
  g('aiknsbanner').style.display = 'none';
  g('aiknsbanner').className = 'aikns-banner';

  var targetInput = isAdmin && g('target-input') ? g('target-input').value.trim().replace(/^@/, '') : '';
  var isTarget    = targetInput !== '';

  function doAnalyze(xData) {
    showProfile(xData.user);
    analyzeAccount(xData.user, xData.tweets, isTarget, function (err2, analysis) {
      keywords = analysis.keywords;
      // adwidget.php JSONP で広告表示（キーワード確定後）
      (function(kws) {
        var kw = kws.length > 0 ? kws[0] : '';
        var old = document.getElementById('aigm-ad-radar-script');
        if (old) { old.parentNode.removeChild(old); }
        var s = document.createElement('script');
        s.id  = 'aigm-ad-radar-script';
        s.src = 'https://aiknowledgecms.exbridge.jp/adwidget.php?callback=aigmAdRadar&slot=radar&limit=4&kw=' + encodeURIComponent(kw);
        document.body.appendChild(s);
      })(keywords);
      g('kwpt').style.display = '';
      var html = '';
      for (var i = 0; i < keywords.length; i++) {
        html += '<span class="kwtag">#' + keywords[i] + '</span>';
      }
      g('kwlist').innerHTML = html;

      fetchAllNews(xData.user.username, keywords, function (myNews, foundKws) {
        addLog('ニュース取得: ' + myNews.length + '件 / キーワード保存: ' + foundKws.join(', '));
        if (foundKws.length > 0) {
          saveKeywords(xData.user.username, foundKws, xData.user, function() {
            addLog('キーワード保存完了（userデータ含む）', 'debug');
          });
        }
        fetchRelatedNews(xData.user.username, keywords, function(err, relNews) {
          relNews = relNews || [];
          addLog('関連ニュース: ' + relNews.length + '件');
          var allNews = sortNewsByDate(myNews.concat(relNews));
          renderNews(allNews);
          setSweep(false);
          setStatus('SCAN COMPLETE');
          g('scanbtn').disabled = false;
          scanning = false;
        });
      });
    });
  }

  if (isTarget) {
    fetchTargetUser(targetInput, function (err, xData) {
      if (err) {
        addLog('[ERROR] ユーザー取得失敗', 'error');
        setSweep(false);
        setStatus('ERROR');
        g('scanbtn').disabled = false;
        scanning = false;
        return;
      }
      doAnalyze(xData);
    });
  } else {
    fetchMe(function (err, xData) {
      if (err) {
        addLog('[ERROR] X取得失敗。再ログインしてください', 'error');
        setSweep(false);
        setStatus('ERROR');
        g('scanbtn').disabled = false;
        scanning = false;
        return;
      }
      doAnalyze(xData);
    });
  }
}
</script>
<script>
function aigmAdRadar(data) {
  if (!data || !data.ok || !data.html) { return; }
  var container = document.getElementById('aigm-ad-radar');
  if (!container) { return; }
  container.innerHTML = data.html;
}
function aigmAdHub(data) {
  if (!data || !data.ok || !data.html) { return; }
  var container = document.getElementById('aigm-ad-hub');
  if (!container) { return; }
  container.innerHTML = data.html;
}
function aigmAdGate(data) {
  if (!data || !data.ok || !data.html) { return; }
  var container = document.getElementById('aigm-ad-gate');
  if (!container) { return; }
  container.innerHTML = data.html;
}
// ページロード時に hub/gate 広告発行
(function() {
  var base = 'https://aiknowledgecms.exbridge.jp/adwidget.php';
  var sh = document.createElement('script');
  sh.src = base + '?callback=aigmAdHub&slot=hub&limit=3';
  document.body.appendChild(sh);
  var sg = document.createElement('script');
  sg.src = base + '?callback=aigmAdGate&slot=gate&limit=3';
  document.body.appendChild(sg);
})();
</script>
<div id="aigm-ad-radar" style="margin:0 0 12px 0;"></div>
  <?php endif; ?>
</div>
<footer class="site-footer">
  当サイトはAmazonアソシエイト・プログラムに参加しています。商品リンクにはアフィリエイトリンクが含まれる場合があります。
</footer>
</body>
</html>
