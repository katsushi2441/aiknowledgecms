<?php
// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
// API: 考察タイムライン取得（重複排除・キーワードごと最新1件）
// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
if (isset($_GET['action']) && $_GET['action'] === 'timeline') {
    header('Content-Type: application/json; charset=UTF-8');
    $account = isset($_GET['account']) ? trim($_GET['account']) : '';
    if (!$account) {
        echo json_encode(array('ok' => false, 'reason' => 'no_account'));
        exit;
    }
    $file = __DIR__ . '/data/keyword_' . preg_replace('/[^a-zA-Z0-9_]/', '', $account) . '.json';
    if (!file_exists($file)) {
        echo json_encode(array('ok' => false, 'reason' => 'no_keyword_file'));
        exit;
    }
    $kdata    = json_decode(file_get_contents($file), true);
    $keywords = isset($kdata['keywords']) ? $kdata['keywords'] : array();
    $items    = array();
    $seen_kws = array();
    foreach ($keywords as $kw) {
        for ($i = 0; $i < 30; $i++) {
            $date      = date('Y-m-d', strtotime('-' . $i . ' days'));
            $candidate = __DIR__ . '/data/' . $date . '_' . $kw . '.json';
            if (!file_exists($candidate)) { continue; }
            $raw  = file_get_contents($candidate);
            $json = json_decode($raw, true);
            if (!$json || !isset($json['analysis'])) { continue; }
            if (isset($seen_kws[$kw])) { break; }
            $seen_kws[$kw] = true;
            $items[] = array(
                'keyword'  => $kw,
                'date'     => $date,
                'analysis' => $json['analysis'],
                'cms_url'  => 'https://aiknowledgecms.exbridge.jp/aithinkingmedia.php?kw=' . rawurlencode($kw) . '&base_date=' . $date,
            );
            break;
        }
    }
    usort($items, function($a, $b) { return strcmp($b['date'], $a['date']); });
    echo json_encode(array('ok' => true, 'items' => $items, 'keywords' => $keywords));
    exit;
}

// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
// API: おすすめアカウント取得
// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
if (isset($_GET['action']) && $_GET['action'] === 'recommended') {
    header('Content-Type: application/json; charset=UTF-8');
    $account = isset($_GET['account']) ? trim($_GET['account']) : '';
    if (!$account) {
        echo json_encode(array('ok' => false, 'reason' => 'no_account'));
        exit;
    }
    $myfile = __DIR__ . '/data/keyword_' . preg_replace('/[^a-zA-Z0-9_]/', '', $account) . '.json';
    if (!file_exists($myfile)) {
        echo json_encode(array('ok' => true, 'accounts' => array()));
        exit;
    }
    $mykdata = json_decode(file_get_contents($myfile), true);
    $mykws   = isset($mykdata['keywords']) ? $mykdata['keywords'] : array();
    $data_dir = __DIR__ . '/data/';
    $accounts = array();
    foreach (glob($data_dir . 'keyword_*.json') as $f) {
        $kdata = json_decode(file_get_contents($f), true);
        if (!$kdata || !isset($kdata['account'])) { continue; }
        if ($kdata['account'] === $account) { continue; }
        $common = array_values(array_intersect($mykws, $kdata['keywords']));
        if (empty($common)) { continue; }
        $accounts[] = array(
            'account'         => $kdata['account'],
            'user'            => isset($kdata['user']) ? $kdata['user'] : array(),
            'keywords'        => $kdata['keywords'],
            'common_keywords' => $common,
            'common_count'    => count($common),
        );
    }
    usort($accounts, function($a, $b) { return $b['common_count'] - $a['common_count']; });
    echo json_encode(array('ok' => true, 'accounts' => $accounts));
    exit;
}

// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
// API: いいね送信
// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
if (isset($_GET['action']) && $_GET['action'] === 'like') {
    header('Content-Type: application/json; charset=UTF-8');
    $body    = file_get_contents('php://input');
    $req     = json_decode($body, true);
    $from    = isset($req['from'])    ? trim($req['from'])    : '';
    $to      = isset($req['to'])      ? trim($req['to'])      : '';
    $keyword = isset($req['keyword']) ? trim($req['keyword']) : '';
    if (!$from || !$to) {
        echo json_encode(array('ok' => false, 'reason' => 'missing_params'));
        exit;
    }
    $likes_file = __DIR__ . '/data/likes.json';
    $likes = array();
    if (file_exists($likes_file)) {
        $raw     = file_get_contents($likes_file);
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) { $likes = $decoded; }
    }
    $already = false;
    foreach ($likes as $like) {
        if (isset($like['from']) && $like['from'] === $from && isset($like['to']) && $like['to'] === $to) {
            $already = true;
            break;
        }
    }
    if (!$already) {
        $likes[] = array(
            'from'      => $from,
            'to'        => $to,
            'keyword'   => $keyword,
            'timestamp' => date('Y-m-d\TH:i:s'),
        );
        file_put_contents($likes_file, json_encode($likes, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        echo json_encode(array('ok' => true, 'added' => true));
    } else {
        echo json_encode(array('ok' => true, 'added' => false, 'reason' => 'already_liked'));
    }
    exit;
}

// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
// API: いいね解除
// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
if (isset($_GET['action']) && $_GET['action'] === 'unlike') {
    header('Content-Type: application/json; charset=UTF-8');
    $body    = file_get_contents('php://input');
    $req     = json_decode($body, true);
    $from    = isset($req['from']) ? trim($req['from']) : '';
    $to      = isset($req['to'])   ? trim($req['to'])   : '';
    if (!$from || !$to) {
        echo json_encode(array('ok' => false, 'reason' => 'missing_params'));
        exit;
    }
    $likes_file = __DIR__ . '/data/likes.json';
    $likes = array();
    if (file_exists($likes_file)) {
        $raw     = file_get_contents($likes_file);
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) { $likes = $decoded; }
    }
    $new_likes = array();
    $removed   = false;
    foreach ($likes as $like) {
        if (isset($like['from']) && $like['from'] === $from && isset($like['to']) && $like['to'] === $to) {
            $removed = true;
            continue;
        }
        $new_likes[] = $like;
    }
    file_put_contents($likes_file, json_encode($new_likes, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo json_encode(array('ok' => true, 'removed' => $removed));
    exit;
}

// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
// API: 自分が送ったいいね取得
// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
if (isset($_GET['action']) && $_GET['action'] === 'sent_likes') {
    header('Content-Type: application/json; charset=UTF-8');
    $account = isset($_GET['account']) ? trim($_GET['account']) : '';
    if (!$account) {
        echo json_encode(array('ok' => false, 'reason' => 'no_account'));
        exit;
    }
    $likes_file = __DIR__ . '/data/likes.json';
    $sent = array();
    if (file_exists($likes_file)) {
        $raw   = file_get_contents($likes_file);
        $likes = json_decode($raw, true);
        if (is_array($likes)) {
            foreach ($likes as $like) {
                if (isset($like['from']) && $like['from'] === $account) {
                    $sent[] = $like['to'];
                }
            }
        }
    }
    echo json_encode(array('ok' => true, 'sent' => $sent));
    exit;
}

if (isset($_GET['action']) && $_GET['action'] === 'my_likes') {
    header('Content-Type: application/json; charset=UTF-8');
    $account = isset($_GET['account']) ? trim($_GET['account']) : '';
    if (!$account) {
        echo json_encode(array('ok' => false, 'reason' => 'no_account'));
        exit;
    }
    $likes_file = __DIR__ . '/data/likes.json';
    $received   = array();
    if (file_exists($likes_file)) {
        $raw   = file_get_contents($likes_file);
        $likes = json_decode($raw, true);
        if (is_array($likes)) {
            foreach ($likes as $like) {
                if (isset($like['to']) && $like['to'] === $account) {
                    $received[] = $like;
                }
            }
        }
    }
    echo json_encode(array('ok' => true, 'received' => $received, 'count' => count($received)));
    exit;
}

// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
// API: ASINからタイトル取得
// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
if (isset($_GET['action']) && $_GET['action'] === 'fetch_asin') {
    header('Content-Type: application/json; charset=UTF-8');
    $asin = isset($_GET['asin']) ? preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($_GET['asin']))) : '';
    if (!$asin || strlen($asin) !== 10) {
        echo json_encode(array('ok' => false, 'reason' => 'invalid_asin'));
        exit;
    }
    $url  = 'https://www.amazon.co.jp/dp/' . $asin;
    $opts = array('http' => array(
        'method'        => 'GET',
        'header'        => implode("\r\n", array(
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept-Language: ja,en;q=0.9',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        )),
        'timeout'       => 12,
        'ignore_errors' => true,
    ));
    $html = @file_get_contents($url, false, stream_context_create($opts));
    $title = '';
    if ($html) {
        if (preg_match("/<span[^>]+id=[\"']productTitle[\"'][^>]*>\\s*(.*?)\\s*<\\/span>/s", $html, $m)) {
            $title = html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8');
            $title = trim(preg_replace('/\s+/', ' ', $title));
        }
        if (!$title && preg_match('/<title>([^<]+)<\/title>/', $html, $m2)) {
            $t = html_entity_decode($m2[1], ENT_QUOTES, 'UTF-8');
            $t = preg_replace('/\s*[:\|].*Amazon.*$/u', '', $t);
            $title = trim($t);
        }
    }
    if ($title) {
        echo json_encode(array('ok' => true, 'asin' => $asin, 'title' => $title, 'url' => $url), JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(array('ok' => false, 'reason' => 'title_not_found', 'url' => $url));
    }
    exit;
}

// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
// API: 管理者デフォルト商品リスト保存
// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
if (isset($_GET['action']) && $_GET['action'] === 'save_admin_items') {
    header('Content-Type: application/json; charset=UTF-8');
    session_start();
    $su = isset($_SESSION['session_username']) ? $_SESSION['session_username'] : '';
    if ($su !== 'xb_bittensor') {
        echo json_encode(array('ok' => false, 'reason' => 'unauthorized'));
        exit;
    }
    $body     = file_get_contents('php://input');
    $req      = json_decode($body, true);
    $items    = isset($req['items'])        ? $req['items']        : array();
    $admin_id = isset($req['associate_id']) ? trim($req['associate_id']) : '';
    $clean = array();
    foreach ($items as $item) {
        $url   = isset($item['url'])   ? trim($item['url'])   : '';
        $title = isset($item['title']) ? trim($item['title']) : '';
        if ($url && $title) { $clean[] = array('url' => $url, 'title' => $title); }
    }
    $admin_file = __DIR__ . '/data/admin_associate.json';
    file_put_contents($admin_file, json_encode(array(
        'associate_id' => $admin_id,
        'items'        => $clean,
    ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo json_encode(array('ok' => true, 'count' => count($clean)));
    exit;
}

// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
// API: アソシエイト情報保存
// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
if (isset($_GET['action']) && $_GET['action'] === 'save_associate') {
    header('Content-Type: application/json; charset=UTF-8');
    $body    = file_get_contents('php://input');
    $req     = json_decode($body, true);
    $account = isset($req['account'])      ? trim($req['account'])      : '';
    $assoc   = isset($req['associate_id']) ? trim($req['associate_id']) : '';
    $items   = isset($req['items'])        ? $req['items']              : array();
    if (!$account) {
        echo json_encode(array('ok' => false, 'reason' => 'no_account'));
        exit;
    }
    $file = __DIR__ . '/data/keyword_' . preg_replace('/[^a-zA-Z0-9_]/', '', $account) . '.json';
    if (!file_exists($file)) {
        echo json_encode(array('ok' => false, 'reason' => 'no_keyword_file'));
        exit;
    }
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data)) { $data = array(); }
    $data['associate_id'] = $assoc;
    $clean_items = array();
    foreach ($items as $item) {
        $url   = isset($item['url'])   ? trim($item['url'])   : '';
        $title = isset($item['title']) ? trim($item['title']) : '';
        if ($url && $title) {
            $clean_items[] = array('url' => $url, 'title' => $title);
        }
    }
    $data['associate_items'] = $clean_items;
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo json_encode(array('ok' => true));
    exit;
}

// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
// API: アソシエイト情報取得
// qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
if (isset($_GET['action']) && $_GET['action'] === 'get_associate') {
    header('Content-Type: application/json; charset=UTF-8');
    $account = isset($_GET['account']) ? trim($_GET['account']) : '';
    if (!$account) {
        echo json_encode(array('ok' => false, 'reason' => 'no_account'));
        exit;
    }
    $file = __DIR__ . '/data/keyword_' . preg_replace('/[^a-zA-Z0-9_]/', '', $account) . '.json';
    if (!file_exists($file)) {
        echo json_encode(array('ok' => true, 'associate_id' => '', 'items' => array()));
        exit;
    }
    $data = json_decode(file_get_contents($file), true);
    $admin_file = __DIR__ . '/data/admin_associate.json';
    $default_items = array();
    if (file_exists($admin_file)) {
        $admin_data = json_decode(file_get_contents($admin_file), true);
        if (isset($admin_data['items'])) { $default_items = $admin_data['items']; }
    }
    $default_assoc_id = '';
    if (file_exists($admin_file)) {
        $ad = json_decode(file_get_contents($admin_file), true);
        if (isset($ad['associate_id'])) { $default_assoc_id = $ad['associate_id']; }
    }
    echo json_encode(array(
        'ok'                   => true,
        'associate_id'         => isset($data['associate_id'])    ? $data['associate_id']    : '',
        'items'                => isset($data['associate_items']) ? $data['associate_items'] : array(),
        'default_items'        => $default_items,
        'default_associate_id' => $default_assoc_id,
        'keywords'             => isset($data['keywords'])        ? $data['keywords']        : array(),
    ));
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
$redirect_uri  = 'https://aiknowledgecms.exbridge.jp/aiknowledgesns.php';

function base64url($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function gen_code_verifier() {
    $bytes = '';
    for ($i = 0; $i < 32; $i++) { $bytes .= chr(mt_rand(0, 255)); }
    return base64url($bytes);
}
function gen_code_challenge($verifier) {
    return base64url(hash('sha256', $verifier, true));
}
function x_api_post_sns($url, $post_data, $headers) {
    $opts = array('http' => array(
        'method'        => 'POST',
        'header'        => implode("\r\n", $headers) . "\r\n",
        'content'       => $post_data,
        'timeout'       => 12,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents($url, false, stream_context_create($opts));
    if (!$res) { $res = '{}'; }
    return json_decode($res, true);
}
function x_api_get_sns($url, $params, $token) {
    $full = count($params) ? $url . '?' . http_build_query($params) : $url;
    $opts = array('http' => array(
        'method'        => 'GET',
        'header'        => "Authorization: Bearer $token\r\nUser-Agent: AIKnowledgeSNS/1.0\r\n",
        'timeout'       => 12,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents($full, false, stream_context_create($opts));
    if (!$res) { $res = '{}'; }
    return json_decode($res, true);
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . $redirect_uri);
    exit;
}

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
    $data = x_api_post_sns('https://api.twitter.com/2/oauth2/token', $post, array(
        'Content-Type: application/x-www-form-urlencoded',
        'Authorization: Basic ' . $cred,
    ));
    if (isset($data['access_token'])) {
        $_SESSION['access_token'] = $data['access_token'];
        unset($_SESSION['oauth_state'], $_SESSION['code_verifier']);
        $me = x_api_get_sns('https://api.twitter.com/2/users/me', array(), $data['access_token']);
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
        'scope'                 => 'tweet.read users.read offline.access',
        'state'                 => $state,
        'code_challenge'        => $challenge,
        'code_challenge_method' => 'S256',
    );
    header('Location: https://twitter.com/i/oauth2/authorize?' . http_build_query($params));
    exit;
}

$logged_in    = isset($_SESSION['access_token']) && $_SESSION['access_token'] !== '';
$session_user = isset($_SESSION['session_username']) ? $_SESSION['session_username'] : '';

$has_keywords = false;
$my_keywords  = array();
if ($session_user) {
    $kf = __DIR__ . '/data/keyword_' . preg_replace('/[^a-zA-Z0-9_]/', '', $session_user) . '.json';
    $has_keywords = file_exists($kf);
    if ($has_keywords) {
        $mkdata      = json_decode(file_get_contents($kf), true);
        $my_keywords = isset($mkdata['keywords']) ? $mkdata['keywords'] : array();
    }
}

// アカウントページ表示
$view_account  = '';
$view_user     = array();
$view_keywords = array();
$view_sources  = array();
if (isset($_GET['view']) && $_GET['view'] === 'account' && isset($_GET['u'])) {
    $view_account = preg_replace('/[^a-zA-Z0-9_]/', '', trim($_GET['u']));
    $vkf = __DIR__ . '/data/keyword_' . $view_account . '.json';
    if (file_exists($vkf)) {
        $vkdata        = json_decode(file_get_contents($vkf), true);
        $view_user     = isset($vkdata['user'])     ? $vkdata['user']     : array();
        $view_keywords = isset($vkdata['keywords']) ? $vkdata['keywords'] : array();
        $view_sources  = isset($vkdata['sources'])  ? $vkdata['sources']  : array();
    }
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
<?php
$seo_title = 'AIKnowledgeSNS — AI×キーワードで繋がる次世代SNS | Amazonアソシエイト収益化';
$seo_desc  = 'キーワードの近さでXアカウントを発見できるAI SNS。Amazonアソシエイト登録で広告収益も。シャドーバンチェックのAIRadarXと連携。';
$seo_url   = 'https://aiknowledgecms.exbridge.jp/aiknowledgesns.php';
if ($view_account) {
    $vu = isset($view_user['name']) ? $view_user['name'] : '@' . $view_account;
    $vk = !empty($view_keywords) ? implode(', ', array_slice($view_keywords, 0, 5)) : 'AI, テクノロジー';
    $seo_title = $vu . ' (@' . $view_account . ') のキーワード知識 — AIKnowledgeSNS';
    $seo_desc  = $vu . 'のキーワード: ' . $vk . '。AIKnowledgeSNSでキーワードが近いXアカウントを発見しましょう。';
    $seo_url   = 'https://aiknowledgecms.exbridge.jp/aiknowledgesns.php?view=account&u=' . rawurlencode($view_account);
}
?>
<title><?php echo htmlspecialchars($seo_title); ?></title>
<meta name="description" content="<?php echo htmlspecialchars($seo_desc); ?>">
<meta name="keywords" content="AI SNS,Xアカウント発見,キーワードSNS,Amazonアソシエイト,シャドーバン,AIKnowledgeSNS,<?php echo htmlspecialchars(implode(',', array_slice($view_keywords, 0, 5))); ?>">
<link rel="canonical" href="<?php echo htmlspecialchars($seo_url); ?>">
<meta property="og:type" content="<?php echo $view_account ? 'profile' : 'website'; ?>">
<meta property="og:title" content="<?php echo htmlspecialchars($seo_title); ?>">
<meta property="og:description" content="<?php echo htmlspecialchars($seo_desc); ?>">
<meta property="og:url" content="<?php echo htmlspecialchars($seo_url); ?>">
<meta property="og:site_name" content="AIKnowledgeSNS">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="<?php echo htmlspecialchars($seo_title); ?>">
<meta name="twitter:description" content="<?php echo htmlspecialchars($seo_desc); ?>">
<?php if ($view_account): ?>
<meta name="twitter:site" content="@<?php echo htmlspecialchars($view_account); ?>">
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "ProfilePage",
  "name": "<?php echo htmlspecialchars($vu); ?>",
  "url": "<?php echo htmlspecialchars($seo_url); ?>",
  "mainEntity": {
    "@type": "Person",
    "name": "<?php echo htmlspecialchars($vu); ?>",
    "identifier": "<?php echo htmlspecialchars($view_account); ?>",
    "sameAs": "https://x.com/<?php echo htmlspecialchars($view_account); ?>",
    "knowsAbout": [<?php echo implode(',', array_map(function($k){ return '"' . addslashes($k) . '"'; }, $view_keywords)); ?>]
  }
}
</script>
<?php else: ?>
<script type="application/ld+json">
{
  "@context": "https://schema.org",
  "@type": "WebApplication",
  "name": "AIKnowledgeSNS",
  "url": "https://aiknowledgecms.exbridge.jp/aiknowledgesns.php",
  "description": "キーワードの近さでXアカウントを発見できるAI SNS。Amazonアソシエイト登録で広告収益も。",
  "applicationCategory": "SocialNetworkingApplication",
  "operatingSystem": "Web",
  "offers": {"@type": "Offer", "price": "0", "priceCurrency": "JPY"},
  "keywords": "AI SNS,Xアカウント発見,キーワードSNS,Amazonアソシエイト,AIKnowledgeSNS"
}
</script>
<?php endif; ?>
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Exo+2:wght@300;400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="aiknowledgesns.css">
</head>
<body>
<div class="app">
  <div class="header">
    <a href="aiknowledgesns.php" class="logo" style="text-decoration:none;">AIKnowledgeSNS</a>
    <div class="tagline">◈ CONNECT · KNOWLEDGE · DISCOVER ◈</div>
    <div class="loginbar">
      <?php if ($logged_in): ?>
        <a href="?view=account&u=<?php echo rawurlencode($session_user); ?>" class="mypage-link">● @<?php echo htmlspecialchars($session_user); ?></a>
        <a href="?logout=1">logout</a>
      <?php else: ?>
        <a href="?login=1">X でログイン</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if (!$logged_in && !$view_account): ?>
  <!-- ======== 未ログイン・非アカウントページ：ログインゲート ======== -->
  <div class="gate">
    <div class="gate-inner">
      <div class="gate-logo">◎</div>
      <div class="gate-msg">
        <div>知識でXのフォローすべき人を発見する</div>
        <div>Xアカウントでログインしてください</div>
      </div>
      <a href="?login=1" class="xbtn">X でログイン</a>
      <div class="gate-assoc">
        <div class="gate-assoc-title">◯ AMAZON アソシエイトで収益を得る</div>
        <div class="gate-assoc-body">
          ログイン後、マイページで<strong>Amazonアソシエイト ID</strong>を登録すると、
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
  (document.body || document.head).appendChild(s);
})();
</script>
  <!-- ======== ゲート画面：アカウントリスト ======== -->
  <div class="gate-account-list">
    <div class="gate-account-list-title">◈ REGISTERED ACCOUNTS</div>
    <div class="gate-account-grid" id="gate-account-grid">
      <div style="font-family:'Share Tech Mono',monospace;font-size:.72rem;color:var(--muted);padding:20px;">◎ loading...</div>
    </div>
  </div>
<script>
(function() {
  function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }
  var xhr = new XMLHttpRequest();
  xhr.open('GET', 'accounts.php?action=list', true);
  xhr.onreadystatechange = function() {
    if (xhr.readyState !== 4) { return; }
    var grid = document.getElementById('gate-account-grid');
    if (!grid) { return; }
    try {
      var data = JSON.parse(xhr.responseText);
      var accounts = (data && data.accounts) ? data.accounts : [];
      if (accounts.length === 0) {
        grid.innerHTML = '<div style="font-family:\'Share Tech Mono\',monospace;font-size:.72rem;color:var(--vm);padding:20px;">アカウントがありません</div>';
        return;
      }
      var html = '';
      for (var i = 0; i < accounts.length; i++) {
        var a    = accounts[i];
        var user = a.user || {};
        var name = user.name || ('@' + a.account);
        var bio  = user.description || '';
        var kws  = a.keywords || [];
        var kwHtml = '';
        for (var j = 0; j < kws.length && j < 4; j++) {
          kwHtml += '<span class="gate-acard-kw">#' + escHtml(kws[j]) + '</span>';
        }
        html +=
          '<a href="aiknowledgesns.php?view=account&u=' + encodeURIComponent(a.account) + '" class="gate-acard">' +
            '<div class="gate-acard-handle">@' + escHtml(a.account) + '</div>' +
            (name !== '@' + a.account ? '<div class="gate-acard-name">' + escHtml(name) + '</div>' : '') +
            (bio ? '<div class="gate-acard-bio">' + escHtml(bio) + '</div>' : '') +
            '<div class="gate-acard-kws">' + kwHtml + '</div>' +
          '</a>';
      }
      grid.innerHTML = html;
    } catch(e) {
      grid.innerHTML = '<div style="font-family:\'Share Tech Mono\',monospace;font-size:.72rem;color:var(--vm);padding:20px;">取得失敗</div>';
    }
  };
  xhr.send();
})();
</script>

  <?php elseif ($logged_in && !$has_keywords && !$view_account): ?>
  <!-- ======== ログイン済みだがキーワードなし ======== -->
  <div class="gate">
    <div class="gate-inner">
      <div class="gate-logo">◎</div>
      <div class="gate-msg">
        <div>@<?php echo htmlspecialchars($session_user); ?> のキーワードデータがありません</div>
        <div>先にAIRadarXでスキャンしてください</div>
      </div>
      <a href="airadarx.php" class="xbtn">AIRadarX へ</a>
    </div>
  </div>

  <?php elseif ($view_account): ?>
  <!-- ======== アカウントページ（ログイン有無問わず表示） ======== -->
  <div class="profile-card">
    <div class="profile-handle">@<?php echo htmlspecialchars($view_account); ?>
      <?php if (!empty($view_user['name'])): ?>
      <span class="profile-name"><?php echo htmlspecialchars($view_user['name']); ?></span>
      <?php endif; ?>
    </div>
    <?php if (!empty($view_user['public_metrics'])): $m = $view_user['public_metrics']; ?>
    <div class="profile-metrics">
      フォロワー <span><?php echo number_format($m['followers_count']); ?></span>
      &nbsp; フォロー <span><?php echo number_format($m['following_count']); ?></span>
    </div>
    <?php endif; ?>
    <?php if (!empty($view_user['description'])): ?>
    <div class="profile-bio"><?php echo htmlspecialchars($view_user['description']); ?></div>
    <?php endif; ?>

    <?php
    // qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
    // Zenn情報バッジ（追加）
    // qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
    $vs   = (is_array($view_sources) && !isset($view_sources[0])) ? $view_sources : array();
    $zenn = isset($vs['zenn']) ? $vs['zenn'] : null;
    if ($zenn):
    ?>
    <div style="margin:8px 0 12px;font-family:'Share Tech Mono',monospace;font-size:.68rem;padding:8px 14px;background:rgba(170,136,255,.08);border:1px solid rgba(170,136,255,.3);border-radius:4px;color:#aa88ff;line-height:2;">
      Zenn:
      <a href="https://zenn.dev/<?php echo htmlspecialchars($zenn['username']); ?>" target="_blank" style="color:#aa88ff;">
        <?php echo htmlspecialchars($zenn['username']); ?>
      </a>
      &nbsp;|&nbsp; 記事 <strong><?php echo intval($zenn['articles_count']); ?></strong>
      &nbsp;|&nbsp; いいね <strong><?php echo intval($zenn['total_liked_count']); ?></strong>
      &nbsp;|&nbsp; フォロワー <strong><?php echo intval($zenn['follower_count']); ?></strong>
      <?php if (!empty($zenn['github_username'])): ?>
      &nbsp;|&nbsp; <a href="https://github.com/<?php echo htmlspecialchars($zenn['github_username']); ?>" target="_blank" style="color:#aa88ff;">GitHub: <?php echo htmlspecialchars($zenn['github_username']); ?> →</a>
      <?php endif; ?>
      <?php if (!empty($zenn['tags'])): ?>
      <br>タグ:
      <?php foreach ($zenn['tags'] as $tag): ?>
        <span style="background:rgba(170,136,255,.15);border:1px solid rgba(170,136,255,.25);border-radius:20px;padding:1px 8px;margin-right:4px;">#<?php echo htmlspecialchars($tag); ?></span>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php
    // qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
    // Zenn RSS 最新記事取得・表示
    // qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq
    if ($zenn && isset($zenn['username']) && $zenn['username']):
        $rss_url  = 'https://zenn.dev/' . rawurlencode($zenn['username']) . '/feed';
        $rss_opts = array('http' => array(
            'method'        => 'GET',
            'header'        => "User-Agent: Mozilla/5.0 (compatible; AIKnowledgeBot/1.0)\r\nAccept: application/rss+xml,application/xml,text/xml\r\n",
            'timeout'       => 8,
            'ignore_errors' => true,
        ));
        $rss_raw  = @file_get_contents($rss_url, false, stream_context_create($rss_opts));
        $zenn_articles = array();
        if ($rss_raw) {
            libxml_use_internal_errors(true);
            $xml = simplexml_load_string($rss_raw);
            if ($xml && isset($xml->channel->item)) {
                $count = 0;
                foreach ($xml->channel->item as $item) {
                    if ($count >= 5) { break; }
                    $pub = isset($item->pubDate) ? date('Y-m-d', strtotime((string)$item->pubDate)) : '';
                    // liked_count は description 内にあることがある（なければ0）
                    $liked = 0;
                    if (isset($item->children('http://zenn.dev/ns#')->liked_count)) {
                        $liked = intval($item->children('http://zenn.dev/ns#')->liked_count);
                    }
                    $zenn_articles[] = array(
                        'title'   => (string)$item->title,
                        'link'    => (string)$item->link,
                        'pubDate' => $pub,
                        'liked'   => $liked,
                    );
                    $count++;
                }
            }
        }
    ?>
    <?php if (!empty($zenn_articles)): ?>
    <div class="zenn-articles">
      <div class="zenn-articles-title">
        ◯ ZENN 最新記事
        <a href="<?php echo htmlspecialchars($rss_url); ?>" target="_blank" class="zenn-rss-link">RSS ↗</a>
      </div>
      <?php foreach ($zenn_articles as $art): ?>
      <div class="zenn-article-item">
        <a href="<?php echo htmlspecialchars($art['link']); ?>" target="_blank" class="zenn-article-title">
          <?php echo htmlspecialchars($art['title']); ?>
        </a>
        <div class="zenn-article-meta">
          <?php if ($art['pubDate']): ?>
          <span class="zenn-article-date"><?php echo htmlspecialchars($art['pubDate']); ?></span>
          <?php endif; ?>
          <?php if ($art['liked'] > 0): ?>
          <span class="zenn-article-liked">♥ <?php echo intval($art['liked']); ?></span>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php endif; ?>

    <div class="profile-actions">
      <a href="aiknowledgesns.php" class="back-link">← 戻る</a>
      <a href="https://x.com/<?php echo htmlspecialchars($view_account); ?>" target="_blank" class="profile-xlink">X で見る →</a>
      <?php if (!empty($zenn)): ?>
      <a href="https://zenn.dev/<?php echo htmlspecialchars($zenn['username']); ?>" target="_blank" class="profile-xlink" style="color:#aa88ff;border-color:rgba(170,136,255,.35);">Zenn →</a>
      <?php endif; ?>
      <?php if ($view_account !== $session_user): ?>
      <button class="like-btn" id="likebtn-profile" onclick="sendLikeProfile('<?php echo addslashes(htmlspecialchars($view_account)); ?>')">
        <span class="heart">♡</span><span>いいね</span>
      </button>
      <span id="followwrap-profile" style="display:none;">
        <a href="https://x.com/intent/follow?screen_name=<?php echo htmlspecialchars($view_account); ?>" target="_blank" class="profile-xlink">X フォロー →</a>
      </span>
      <?php endif; ?>
    </div>
  </div>

  <div class="grid">
    <div>
      <div class="panel">
        <div class="pt">◯ <?php echo ($view_account === $session_user) ? 'RECOMMENDED ACCOUNTS' : 'RECOMMENDED FOR @' . htmlspecialchars($view_account); ?></div>
        <div id="view-recommended"><div class="loading">◎ loading...</div></div>
      </div>
    </div>
    <div>
      <?php if ($view_account === 'xb_bittensor'): ?>
      <div class="panel" style="margin-bottom:12px;">
        <div class="pt">◯ OSS TIMELINE</div>
        <?php
        $oss_file  = __DIR__ . '/data/oss_posts.json';
        $oss_posts = array();
        if (file_exists($oss_file)) {
            $oss_posts = json_decode(file_get_contents($oss_file), true);
            if (!$oss_posts) $oss_posts = array();
        }
        $oss_recent = array_slice($oss_posts, 0, 5);
        if (empty($oss_recent)):
        ?>
        <div style="padding:12px;font-size:13px;color:#888;">投稿がありません</div>
        <?php else: ?>
        <ul style="list-style:none;padding:0;margin:0;">
        <?php foreach ($oss_recent as $op): ?>
          <li style="border-bottom:1px solid #1e2330;padding:10px 14px;">
            <a href="https://aiknowledgecms.exbridge.jp/oss.php?id=<?php echo urlencode($op['id']); ?>"
               style="color:#8b9cf4;text-decoration:none;font-size:13px;font-weight:600;display:block;margin-bottom:3px;"
               target="_blank">
              <?php echo htmlspecialchars(mb_substr($op['title'], 0, 50)); ?>
            </a>
            <span style="font-size:11px;color:#555;"><?php echo htmlspecialchars(substr($op['created_at'], 0, 10)); ?></span>
          </li>
        <?php endforeach; ?>
        </ul>
        <div style="padding:8px 14px;">
          <a href="https://aiknowledgecms.exbridge.jp/oss.php" target="_blank"
             style="font-size:12px;color:#6c63ff;text-decoration:none;">→ すべて見る</a>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <div class="panel">
        <div class="pt">◯ KNOWLEDGE TIMELINE</div>
        <div class="kwlist" id="view-kwlist"></div>
        <div id="view-timeline"><div class="loading">◎ loading...</div></div>
      </div>
    </div>
  </div>

<script>
var MY_ACCOUNT   = '<?php echo addslashes($session_user); ?>';
var VIEW_ACCOUNT = '<?php echo addslashes($view_account); ?>';
var VIEW_KEYWORDS = <?php echo json_encode(array_values($view_keywords), JSON_UNESCAPED_UNICODE); ?>;
var likedAccounts = {};
var expandStates  = {};
var CMS_BASE = 'https://aiknowledgecms.exbridge.jp/aithinkingmedia.php';

function g(id) { return document.getElementById(id); }
function xhrGet(url, cb) {
  var xhr = new XMLHttpRequest();
  xhr.open('GET', url, true);
  xhr.onreadystatechange = function () {
    if (xhr.readyState !== 4) { return; }
    try { cb(null, JSON.parse(xhr.responseText)); } catch (e) { cb(e, null); }
  };
  xhr.send();
}
function xhrPost(url, payload, cb) {
  var xhr = new XMLHttpRequest();
  xhr.open('POST', url, true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onreadystatechange = function () {
    if (xhr.readyState !== 4) { return; }
    try { cb(null, JSON.parse(xhr.responseText)); } catch (e) { cb(e, null); }
  };
  xhr.send(JSON.stringify(payload));
}
function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function cmsUrl(kw, date) {
  var url = CMS_BASE + '?kw=' + encodeURIComponent(kw);
  if (date) { url += '&base_date=' + encodeURIComponent(date); }
  return url;
}
function toggleExpand(id) {
  var body = document.getElementById('tlbody-' + id);
  var btn  = document.getElementById('tlbtn-' + id);
  if (!body) { return; }
  if (expandStates[id]) {
    body.className = 'tl-body';
    btn.textContent = '▼ 続きを読む';
    expandStates[id] = false;
  } else {
    body.className = 'tl-body expanded';
    btn.textContent = '▲ 閉じる';
    expandStates[id] = true;
  }
}
function scrollToKw(kw) {
  var safeKw = kw.replace(/[^a-zA-Z0-9]/g, '_');
  var el = document.getElementById('tl-' + safeKw);
  if (!el) { return; }
  el.scrollIntoView({ behavior: 'smooth', block: 'center' });
  el.className = el.className + ' scrolled';
  setTimeout(function() { el.className = el.className.replace(' scrolled', ''); }, 900);
}
// いいね／いいね解除トグル（プロフィールボタン）
function sendLikeProfile(toAccount) {
  if (!MY_ACCOUNT) { return; }
  var btn = g('likebtn-profile');
  var fw  = g('followwrap-profile');
  if (likedAccounts[toAccount]) {
    // いいね解除
    xhrPost('?action=unlike', { from: MY_ACCOUNT, to: toAccount }, function(err, data) {
      console.log('[DEBUG] unlike profile: removed=' + (data ? data.removed : 'err'));
    });
    likedAccounts[toAccount] = false;
    btn.classList.remove('liked');
    btn.innerHTML = '<span class="heart">♡</span><span>いいね</span>';
    if (fw) { fw.style.display = 'none'; }
  } else {
    // いいね
    xhrPost('?action=like', { from: MY_ACCOUNT, to: toAccount, keyword: '' }, function(err, data) {
      console.log('[DEBUG] like profile: added=' + (data ? data.added : 'err'));
    });
    likedAccounts[toAccount] = true;
    btn.classList.add('liked');
    btn.innerHTML = '<span class="heart">♥</span><span>いいね済み</span>';
    if (fw) { fw.style.display = 'inline-block'; }
  }
}
// いいね／いいね解除トグル（推薦カードボタン）
function sendLike(toAccount, keyword, btnEl) {
  if (!MY_ACCOUNT) { return; }
  var fw = document.getElementById('followwrap-' + toAccount.replace(/[^a-zA-Z0-9]/g, '_'));
  if (likedAccounts[toAccount]) {
    // いいね解除
    xhrPost('?action=unlike', { from: MY_ACCOUNT, to: toAccount }, function(err, data) {
      console.log('[DEBUG] unlike: removed=' + (data ? data.removed : 'err'));
    });
    likedAccounts[toAccount] = false;
    btnEl.classList.remove('liked');
    btnEl.innerHTML = '<span class="heart">♡</span><span>いいね</span>';
    if (fw) { fw.style.display = 'none'; }
  } else {
    // いいね
    xhrPost('?action=like', { from: MY_ACCOUNT, to: toAccount, keyword: keyword }, function(err, data) {
      console.log('[DEBUG] like: added=' + (data ? data.added : 'err'));
    });
    likedAccounts[toAccount] = true;
    btnEl.classList.add('liked');
    btnEl.innerHTML = '<span class="heart">♥</span><span>いいね済み</span>';
    if (fw) { fw.style.display = 'inline-block'; }
  }
}
function restoreLikedButtons() {
  if (likedAccounts[VIEW_ACCOUNT]) {
    var btn = g('likebtn-profile');
    if (btn) { btn.classList.add('liked'); btn.innerHTML = '<span class="heart">♥</span><span>いいね済み</span>'; }
    var fw = g('followwrap-profile');
    if (fw) { fw.style.display = 'inline-block'; }
  }
  for (var toAccount in likedAccounts) {
    var safeId = toAccount.replace(/[^a-zA-Z0-9]/g, '_');
    var btn = document.getElementById('likebtn-' + safeId);
    if (btn) { btn.classList.add('liked'); btn.innerHTML = '<span class="heart">♥</span><span>いいね済み</span>'; }
    var fw = document.getElementById('followwrap-' + safeId);
    if (fw) { fw.style.display = 'inline-block'; }
  }
}
function renderRecommended(data) {
  var area = g('view-recommended');
  if (!data.accounts || data.accounts.length === 0) {
    area.innerHTML = '<div class="empty">共通キーワードを持つ<br>アカウントが見つかりません</div>';
    return;
  }
  var html = '';
  for (var i = 0; i < data.accounts.length; i++) {
    var a      = data.accounts[i];
    var user   = a.user || {};
    var safeId = a.account.replace(/[^a-zA-Z0-9]/g, '_');
    var firstCommon = a.common_keywords.length > 0 ? a.common_keywords[0] : '';
    var kwHtml = '';
    for (var j = 0; j < a.keywords.length; j++) {
      var kw       = a.keywords[j];
      var isCommon = a.common_keywords.indexOf(kw) !== -1;
      if (isCommon) {
        kwHtml += '<span class="akwtag common" onclick="scrollToKw(\'' + escHtml(kw) + '\')" title="→ 右の考察へ">◈ #' + escHtml(kw) + '</span>';
      } else {
        kwHtml += '<a href="' + cmsUrl(kw, '') + '" target="_blank" class="akwtag normal">#' + escHtml(kw) + '</a>';
      }
    }
    html +=
      '<div class="acard" id="acard-' + safeId + '">' +
        '<div class="acard-head">' +
          '<div class="acard-left">' +
            '<div class="ahandle">' +
              '<a href="aiknowledgesns.php?view=account&u=' + encodeURIComponent(a.account) + '">@' + escHtml(a.account) + '</a>' +
              (user.name ? '<span style="font-family:Exo 2,sans-serif;font-size:.78rem;color:var(--text);margin-left:8px;">' + escHtml(user.name) + '</span>' : '') +
              '<span class="common-badge">共通 ' + a.common_count + '</span>' +
            '</div>' +
            (user.public_metrics ? '<div style="font-family:Share Tech Mono,monospace;font-size:.63rem;color:var(--vm);margin-bottom:6px;">フォロワー <span style="color:var(--text);">' + Number(user.public_metrics.followers_count).toLocaleString() + '</span> &nbsp;フォロー <span style="color:var(--text);">' + Number(user.public_metrics.following_count).toLocaleString() + '</span></div>' : '') +
            (user.description ? '<div class="abio">' + escHtml(user.description) + '</div>' : '<div class="abio" style="font-style:italic;font-size:.72rem;">bioなし — <a href="https://x.com/' + escHtml(a.account) + '" target="_blank" style="color:var(--blue);">Xで確認 →</a></div>') +
          '</div>' +
          '<div style="display:flex;flex-direction:column;gap:6px;align-items:flex-end;">' +
            '<button class="like-btn" id="likebtn-' + safeId + '" onclick="sendLike(\'' + escHtml(a.account) + '\',\'' + escHtml(firstCommon) + '\',this)"><span class="heart">♡</span><span>いいね</span></button>' +
            '<span id="followwrap-' + safeId + '" style="display:none;"><a href="https://x.com/intent/follow?screen_name=' + escHtml(a.account) + '" target="_blank" style="font-family:Share Tech Mono,monospace;font-size:.65rem;color:#1da1f2;border:1px solid rgba(29,161,242,.35);padding:4px 10px;border-radius:3px;text-decoration:none;background:rgba(29,161,242,.08);">X フォロー →</a></span>' +
          '</div>' +
        '</div>' +
        '<div class="akws">' + kwHtml + '</div>' +
      '</div>';
  }
  area.innerHTML = html;
}
function renderTimeline(data) {
  var kwHtml = '';
  for (var i = 0; i < data.keywords.length; i++) {
    var kw = data.keywords[i];
    kwHtml += '<a href="' + cmsUrl(kw, '') + '" target="_blank" class="kwtag-link">#' + escHtml(kw) + '</a>';
  }
  g('view-kwlist').innerHTML = kwHtml;
  var area = g('view-timeline');
  if (!data.items || data.items.length === 0) {
    area.innerHTML = '<div class="empty">考察データなし<br><small>AIKnowledgeCMSでキーワードを生成してください</small></div>';
    return;
  }
  var html = '';
  for (var i = 0; i < data.items.length; i++) {
    var item   = data.items[i];
    var safeKw = item.keyword.replace(/[^a-zA-Z0-9]/g, '_');
    html +=
      '<div class="tl-item" id="tl-' + safeKw + '">' +
        '<div class="tl-kw-row">' +
          '<a href="' + escHtml(item.cms_url) + '" target="_blank" class="tl-kwtag">#' + escHtml(item.keyword) + '</a>' +
          '<span class="tl-date">' + escHtml(item.date) + '</span>' +
        '</div>' +
        '<div class="tl-body" id="tlbody-' + safeKw + '">' + escHtml(item.analysis) + '</div>' +
        '<div class="tl-footer">' +
          '<button class="expand-btn" id="tlbtn-' + safeKw + '" onclick="toggleExpand(\'' + safeKw + '\')">▼ 続きを読む</button>' +
          '<a href="' + escHtml(item.cms_url) + '" target="_blank" class="cms-link">→ AIKnowledgeCMS</a>' +
        '</div>' +
      '</div>';
  }
  area.innerHTML = html;
}

var recAccount = (VIEW_ACCOUNT === MY_ACCOUNT) ? MY_ACCOUNT : VIEW_ACCOUNT;

// 未ログインの場合はいいね復元をスキップ
if (MY_ACCOUNT) {
  xhrGet('?action=sent_likes&account=' + encodeURIComponent(MY_ACCOUNT), function(err, data) {
    if (!err && data && data.ok && data.sent) {
      for (var i = 0; i < data.sent.length; i++) { likedAccounts[data.sent[i]] = true; }
    }
    xhrGet('?action=recommended&account=' + encodeURIComponent(recAccount), function(err2, data2) {
      if (err2 || !data2 || !data2.ok) {
        g('view-recommended').innerHTML = '<div class="empty">取得失敗</div>';
        return;
      }
      renderRecommended(data2);
      restoreLikedButtons();
    });
  });
} else {
  xhrGet('?action=recommended&account=' + encodeURIComponent(recAccount), function(err2, data2) {
    if (err2 || !data2 || !data2.ok) {
      g('view-recommended').innerHTML = '<div class="empty">取得失敗</div>';
      return;
    }
    renderRecommended(data2);
  });
}

xhrGet('?action=timeline&account=' + encodeURIComponent(VIEW_ACCOUNT), function(err, data) {
  if (err || !data || !data.ok) {
    g('view-timeline').innerHTML = '<div class="empty">取得失敗</div>';
    return;
  }
  renderTimeline(data);
});

xhrGet('?action=get_associate&account=' + encodeURIComponent(VIEW_ACCOUNT), function(err, data) {
  if (err || !data || !data.ok) { return; }
  var isOwn = (VIEW_ACCOUNT === MY_ACCOUNT);
  if (isOwn && MY_ACCOUNT) {
    renderAssociateEdit(data);
  }
});

function buildAssocUrl(url, assocId) {
  if (!assocId) { return url; }
  return url + (url.indexOf('?') !== -1 ? '&' : '?') + 'tag=' + encodeURIComponent(assocId);
}

// adwidget.php JSONP で広告表示（アカウントページ）
function aigmExecScripts(el) {
  var scripts = el.querySelectorAll('script');
  for (var i = 0; i < scripts.length; i++) {
    var s = document.createElement('script');
    s.textContent = scripts[i].textContent;
    document.body.appendChild(s);
  }
}
function aigmAdView(data) {
  if (!data || !data.html) { return; }
  var div = document.createElement('div');
  div.innerHTML = data.html;
  var insertAfter = document.querySelector('.assoc-edit') || document.querySelector('.profile-card');
  if (insertAfter) {
    insertAfter.parentNode.insertBefore(div, insertAfter.nextSibling);
    aigmExecScripts(div);
  }
}
function aigmAdGate(data) {
  if (!data || !data.ok || !data.html) { return; }
  var container = document.getElementById('aigm-ad-gate');
  if (!container) { return; }
  container.innerHTML = data.html;
}
// gate広告（ページロード時）
(function() {
  var sg = document.createElement('script');
  sg.src = 'https://aiknowledgecms.exbridge.jp/adwidget.php?callback=aigmAdGate&slot=gate&limit=3';
  document.body.appendChild(sg);
})();
(function() {
  var kw = (typeof VIEW_KEYWORDS !== 'undefined' && VIEW_KEYWORDS.length > 0) ? VIEW_KEYWORDS[0] : '';
  var s = document.createElement('script');
  s.src = 'https://aiknowledgecms.exbridge.jp/adwidget.php?callback=aigmAdView&slot=account&limit=4&kw=' + encodeURIComponent(kw);
  document.body.appendChild(s);
})();

function renderAssociateEdit(data) {
  var assocId      = data.associate_id  || '';
  var items        = data.items         || [];
  var defaultItems = data.default_items || [];
  var isAdmin      = (VIEW_ACCOUNT === 'xb_bittensor');
  var siteUrl = 'aiknowledgecms.exbridge.jp';
  var html = '<div class="assoc-edit"><div class="assoc-edit-title">◯ AMAZON アソシエイト設定</div>';
  html += '<div class="assoc-notice">' +
    '<div class="assoc-notice-title">⚠ 事前にサイト登録が必要です</div>' +
    '<div class="assoc-notice-body">' +
      'AmazonアソシエイトIDを使用するには、Amazon管理画面で当サイトを登録してください。<br>' +
      '<strong>登録するURL：</strong><br>' +
      '<code class="assoc-site-url">' + escHtml(siteUrl) + '</code><br><br>' +
      '手順：<a href="https://affiliate.amazon.co.jp/" target="_blank" class="assoc-link">Amazonアソシエイト管理画面</a> → ' +
      'アカウント → サイトとアプリの管理 → 上記URLを追加' +
    '</div>' +
  '</div>';
  html += '<input class="assoc-input" id="assoc-id-input" type="text" placeholder="アソシエイトID (例: xxxxx-22)" value="' + escHtml(assocId) + '">';
  html += '<div class="assoc-edit-title" style="margin-top:10px;">商品リンク（手動登録）</div>';
  html += '<div id="assoc-items-wrap">';
  for (var i = 0; i < items.length; i++) {
    html += assocItemRow(items[i].title, items[i].url);
  }
  html += '</div>';
  html += '<button class="assoc-add-btn" onclick="addAssocItem()">＋ 商品を追加</button><br>';
  html += '<button class="assoc-save-btn" onclick="saveAssociate()">保存</button>';
  if (isAdmin) {
    html += '<div class="assoc-edit-title" style="margin-top:18px;border-top:1px solid rgba(255,153,0,.2);padding-top:14px;">◯ 管理者：システムデフォルト設定</div>';
    html += '<div style="font-size:.7rem;color:var(--muted);margin-bottom:8px;">ID未設定ユーザーのページに使われる管理者アソシエイトID</div>';
    html += '<input class="assoc-input" id="admin-assoc-id-input" type="text" placeholder="管理者アソシエイトID (例: xxxxx-22)" value="' + escHtml(data.default_associate_id || '') + '">';
    html += '<div class="assoc-edit-title" style="margin-top:10px;">デフォルト商品リスト</div>';
    html += '<div style="font-size:.7rem;color:var(--muted);margin-bottom:8px;">ユーザーが手動登録していない場合に表示される商品。ユーザー自身のアソシエイトIDで表示される。</div>';
    html += '<div id="admin-items-wrap">';
    for (var j = 0; j < defaultItems.length; j++) {
      html += assocAdminItemRow(defaultItems[j].title, defaultItems[j].url);
    }
    html += '</div>';
    html += '<button class="assoc-add-btn" onclick="addAdminItem()">＋ デフォルト商品を追加</button><br>';
    html += '<button class="assoc-save-btn" onclick="saveAdminItems()">デフォルト商品を保存</button>';
  }
  html += '</div>';
  var profileCard = document.querySelector('.profile-card');
  if (profileCard) {
    var div = document.createElement('div');
    div.innerHTML = html;
    profileCard.parentNode.insertBefore(div, profileCard.nextSibling);
  }
}

function asinFromUrl(url) {
  var m = (url || '').match(/\/dp\/([A-Z0-9]{10})/);
  return m ? m[1] : '';
}

function assocItemRow(title, url) {
  var asin = asinFromUrl(url) || '';
  var uid  = 'asin_' + Math.random().toString(36).substr(2, 6);
  return '<div class="assoc-item-row" id="' + uid + '">' +
    '<input type="text" placeholder="ASIN (例: B01N6UHJI8)" value="' + escHtml(asin) + '" class="assoc-item-asin" style="max-width:160px;" oninput="fetchAsin(this,\'' + uid + '\')">' +
    '<input type="text" placeholder="商品タイトル（自動取得）" value="' + escHtml(title || '') + '" class="assoc-item-title" readonly style="flex:1;opacity:.8;">' +
    '<input type="hidden" value="' + escHtml(url || '') + '" class="assoc-item-url">' +
    '<button class="assoc-remove-btn" onclick="this.closest(\'.assoc-item-row\').remove()">削除</button>' +
  '</div>';
}

function fetchAsin(inputEl, rowId) {
  var asin = inputEl.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
  if (asin.length !== 10) { return; }
  var row       = document.getElementById(rowId);
  if (!row) { return; }
  var titleEl   = row.querySelector('.assoc-item-title');
  var urlEl     = row.querySelector('.assoc-item-url');
  titleEl.value = '取得中...';
  var xhr = new XMLHttpRequest();
  xhr.open('GET', '?action=fetch_asin&asin=' + encodeURIComponent(asin), true);
  xhr.onreadystatechange = function() {
    if (xhr.readyState !== 4) { return; }
    try {
      var data = JSON.parse(xhr.responseText);
      if (data.ok) {
        titleEl.value = data.title;
        urlEl.value   = data.url;
      } else {
        titleEl.value = '取得失敗 — 手動で入力してください';
        urlEl.value   = 'https://www.amazon.co.jp/dp/' + asin;
      }
    } catch(e) { titleEl.value = 'エラー'; }
  };
  xhr.send();
}

function addAssocItem() {
  var wrap = g('assoc-items-wrap');
  if (!wrap) { return; }
  var div = document.createElement('div');
  div.innerHTML = assocItemRow('', '');
  wrap.appendChild(div.firstChild);
}

function assocAdminItemRow(title, url) {
  var asin = asinFromUrl(url) || '';
  var uid  = 'adm_' + Math.random().toString(36).substr(2, 6);
  return '<div class="assoc-item-row" id="' + uid + '">' +
    '<input type="text" placeholder="ASIN (例: B01N6UHJI8)" value="' + escHtml(asin) + '" class="admin-item-asin" style="max-width:160px;" oninput="fetchAsinAdmin(this,\'' + uid + '\')">' +
    '<input type="text" placeholder="商品タイトル（自動取得）" value="' + escHtml(title || '') + '" class="admin-item-title" readonly style="flex:1;opacity:.8;">' +
    '<input type="hidden" value="' + escHtml(url || '') + '" class="admin-item-url">' +
    '<button class="assoc-remove-btn" onclick="this.closest(\'.assoc-item-row\').remove()">削除</button>' +
  '</div>';
}

function fetchAsinAdmin(inputEl, rowId) {
  var asin = inputEl.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
  if (asin.length !== 10) { return; }
  var row     = document.getElementById(rowId);
  if (!row) { return; }
  var titleEl = row.querySelector('.admin-item-title');
  var urlEl   = row.querySelector('.admin-item-url');
  titleEl.value = '取得中...';
  var xhr = new XMLHttpRequest();
  xhr.open('GET', '?action=fetch_asin&asin=' + encodeURIComponent(asin), true);
  xhr.onreadystatechange = function() {
    if (xhr.readyState !== 4) { return; }
    try {
      var data = JSON.parse(xhr.responseText);
      if (data.ok) {
        titleEl.value = data.title;
        urlEl.value   = data.url;
      } else {
        titleEl.value = '取得失敗 — 手動で入力してください';
        titleEl.readOnly = false;
        urlEl.value   = 'https://www.amazon.co.jp/dp/' + asin;
      }
    } catch(e) { titleEl.value = 'エラー'; }
  };
  xhr.send();
}

function addAdminItem() {
  var wrap = g('admin-items-wrap');
  if (!wrap) { return; }
  var div = document.createElement('div');
  div.innerHTML = assocAdminItemRow('', '');
  wrap.appendChild(div.firstChild);
}

function saveAdminItems() {
  var adminId = g('admin-assoc-id-input') ? g('admin-assoc-id-input').value.trim() : '';
  var rows  = document.querySelectorAll('#admin-items-wrap .assoc-item-row');
  var items = [];
  for (var i = 0; i < rows.length; i++) {
    var a = rows[i].querySelector('.admin-item-asin');
    var t = rows[i].querySelector('.admin-item-title');
    var u = rows[i].querySelector('.admin-item-url');
    if (a && a.value.trim() && u && !u.value.trim()) {
      var asin = a.value.replace(/[^A-Za-z0-9]/g,'').toUpperCase();
      if (asin.length === 10) { u.value = 'https://www.amazon.co.jp/dp/' + asin; }
    }
    if (t && u && t.value.trim() && u.value.trim()) {
      items.push({ title: t.value.trim(), url: u.value.trim() });
    }
  }
  var xhr = new XMLHttpRequest();
  xhr.open('POST', '?action=save_admin_items', true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onreadystatechange = function() {
    if (xhr.readyState !== 4) { return; }
    try {
      var res = JSON.parse(xhr.responseText);
      if (res.ok) { alert('デフォルト商品を保存しました（' + res.count + '件）'); }
      else { alert('保存失敗: ' + (res.reason || '不明')); }
    } catch(e) { alert('エラー'); }
  };
  xhr.send(JSON.stringify({ associate_id: adminId, items: items }));
}

function saveAssociate() {
  var assocId = g('assoc-id-input') ? g('assoc-id-input').value.trim() : '';
  var rows    = document.querySelectorAll('#assoc-items-wrap .assoc-item-row');
  var items   = [];
  for (var i = 0; i < rows.length; i++) {
    var t = rows[i].querySelector('.assoc-item-title');
    var u = rows[i].querySelector('.assoc-item-url');
    if (t && u && t.value.trim() && u.value.trim()) {
      items.push({ title: t.value.trim(), url: u.value.trim() });
    }
  }
  var asinRows = document.querySelectorAll('#assoc-items-wrap .assoc-item-row');
  asinRows.forEach(function(row) {
    var a = row.querySelector('.assoc-item-asin');
    var u = row.querySelector('.assoc-item-url');
    var t = row.querySelector('.assoc-item-title');
    if (a && a.value.trim() && u && !u.value.trim()) {
      var asin = a.value.replace(/[^A-Za-z0-9]/g,'').toUpperCase();
      if (asin.length === 10) {
        u.value = 'https://www.amazon.co.jp/dp/' + asin;
        if (t && !t.value.trim()) { t.value = asin; }
      }
    }
  });
  var xhr = new XMLHttpRequest();
  xhr.open('POST', '?action=save_associate', true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onreadystatechange = function() {
    if (xhr.readyState !== 4) { return; }
    try {
      var res = JSON.parse(xhr.responseText);
      if (res.ok) { alert('保存しました'); }
      else { alert('保存失敗: ' + (res.reason || '不明')); }
    } catch(e) { alert('エラー'); }
  };
  xhr.send(JSON.stringify({ account: VIEW_ACCOUNT, associate_id: assocId, items: items }));
}
</script>

  <?php else: ?>
  <!-- ======== メインSNS画面（ログイン済み・キーワードあり） ======== -->

  <div class="like-notify" id="like-notify">
    <div class="like-notify-title">♥ あなたの知識に興味を持った人がいます</div>
    <div id="like-notify-body"></div>
  </div>

  <div class="top-nav">
    <a href="?view=account&u=<?php echo rawurlencode($session_user); ?>" class="top-nav-btn">◎ マイページ</a>
    <a href="airadarx.php" class="top-nav-btn">← AIRadarX</a>
  </div>

  <div class="grid">
    <div>
      <div class="panel">
        <div class="pt">◯ RECOMMENDED ACCOUNTS — @<?php echo htmlspecialchars($session_user); ?></div>
        <div id="recommended-area"><div class="loading">◎ loading...</div></div>
      </div>
    </div>
    <div>
      <div class="panel" style="margin-bottom:12px;">
        <div class="pt">◯ OSS TIMELINE</div>
        <?php
        $oss_file2  = __DIR__ . '/data/oss_posts.json';
        $oss_posts2 = array();
        if (file_exists($oss_file2)) {
            $oss_posts2 = json_decode(file_get_contents($oss_file2), true);
            if (!$oss_posts2) $oss_posts2 = array();
        }
        $oss_recent2 = array_slice($oss_posts2, 0, 5);
        if (empty($oss_recent2)):
        ?>
        <div style="padding:12px;font-size:13px;color:#888;">投稿がありません</div>
        <?php else: ?>
        <ul style="list-style:none;padding:0;margin:0;">
        <?php foreach ($oss_recent2 as $op2): ?>
          <li style="border-bottom:1px solid #1e2330;padding:10px 14px;">
            <a href="https://aiknowledgecms.exbridge.jp/oss.php?id=<?php echo urlencode($op2['id']); ?>"
               style="color:#8b9cf4;text-decoration:none;font-size:13px;font-weight:600;display:block;margin-bottom:3px;"
               target="_blank">
              <?php echo htmlspecialchars(mb_substr($op2['title'], 0, 50)); ?>
            </a>
            <span style="font-size:11px;color:#555;"><?php echo htmlspecialchars(substr($op2['created_at'], 0, 10)); ?></span>
          </li>
        <?php endforeach; ?>
        </ul>
        <div style="padding:8px 14px;">
          <a href="https://aiknowledgecms.exbridge.jp/oss.php" target="_blank"
             style="font-size:12px;color:#6c63ff;text-decoration:none;">&#x2192; すべて見る</a>
        </div>
        <?php endif; ?>
      </div>
      <div class="panel">
        <div class="pt">◯ KNOWLEDGE TIMELINE</div>
        <div class="kwlist" id="my-kwlist"></div>
        <div id="timeline-area"><div class="loading">◎ loading...</div></div>
      </div>
    </div>
  </div>

<script>
var MY_ACCOUNT   = '<?php echo addslashes($session_user); ?>';
var MY_KEYWORDS  = <?php echo json_encode(array_values($my_keywords), JSON_UNESCAPED_UNICODE); ?>;
var likedAccounts = {};
var expandStates  = {};
var CMS_BASE = 'https://aiknowledgecms.exbridge.jp/aithinkingmedia.php';

function g(id) { return document.getElementById(id); }
function xhrGet(url, cb) {
  var xhr = new XMLHttpRequest();
  xhr.open('GET', url, true);
  xhr.onreadystatechange = function () {
    if (xhr.readyState !== 4) { return; }
    try { cb(null, JSON.parse(xhr.responseText)); } catch (e) { cb(e, null); }
  };
  xhr.send();
}
function xhrPost(url, payload, cb) {
  var xhr = new XMLHttpRequest();
  xhr.open('POST', url, true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onreadystatechange = function () {
    if (xhr.readyState !== 4) { return; }
    try { cb(null, JSON.parse(xhr.responseText)); } catch (e) { cb(e, null); }
  };
  xhr.send(JSON.stringify(payload));
}
function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function cmsUrl(kw, date) {
  var url = CMS_BASE + '?kw=' + encodeURIComponent(kw);
  if (date) { url += '&base_date=' + encodeURIComponent(date); }
  return url;
}
function scrollToKw(kw) {
  var safeKw = kw.replace(/[^a-zA-Z0-9\u3040-\u30ff\u4e00-\u9fff]/g, '_');
  var el = document.getElementById('tl-' + safeKw);
  if (!el) { return; }
  el.scrollIntoView({ behavior: 'smooth', block: 'center' });
  el.className = el.className + ' scrolled';
  setTimeout(function() { el.className = el.className.replace(' scrolled', ''); }, 900);
}
function toggleExpand(id) {
  var body = document.getElementById('tlbody-' + id);
  var btn  = document.getElementById('tlbtn-' + id);
  if (!body) { return; }
  if (expandStates[id]) {
    body.className = 'tl-body';
    btn.textContent = '▼ 続きを読む';
    expandStates[id] = false;
  } else {
    body.className = 'tl-body expanded';
    btn.textContent = '▲ 閉じる';
    expandStates[id] = true;
  }
}
function sendLike(toAccount, keyword, btnEl) {
  var fw = document.getElementById('followwrap-' + toAccount.replace(/[^a-zA-Z0-9]/g, '_'));
  if (likedAccounts[toAccount]) {
    // いいね解除
    xhrPost('?action=unlike', { from: MY_ACCOUNT, to: toAccount }, function(err, data) {
      console.log('[DEBUG] unlike: removed=' + (data ? data.removed : 'err'));
    });
    likedAccounts[toAccount] = false;
    btnEl.classList.remove('liked');
    btnEl.innerHTML = '<span class="heart">♡</span><span>いいね</span>';
    if (fw) { fw.style.display = 'none'; }
  } else {
    // いいね
    xhrPost('?action=like', { from: MY_ACCOUNT, to: toAccount, keyword: keyword }, function(err, data) {
      console.log('[DEBUG] like: added=' + (data ? data.added : 'err'));
    });
    likedAccounts[toAccount] = true;
    btnEl.classList.add('liked');
    btnEl.innerHTML = '<span class="heart">♥</span><span>いいね済み</span>';
    if (fw) { fw.style.display = 'inline-block'; }
  }
}
function renderLikeNotify(data) {
  if (!data || !data.received || data.received.length === 0) { return; }
  var html = '';
  for (var i = 0; i < data.received.length; i++) {
    var like = data.received[i];
    html += '<span class="like-from">@' + escHtml(like.from) + '</span>';
    if (like.keyword) {
      html += '<span style="font-size:.65rem;color:var(--muted);">（' + escHtml(like.keyword) + '）</span> ';
    }
  }
  g('like-notify-body').innerHTML = html;
  g('like-notify').className = 'like-notify show';
}
function renderRecommended(data) {
  var area = g('recommended-area');
  if (!data.accounts || data.accounts.length === 0) {
    area.innerHTML = '<div class="empty">共通キーワードを持つ<br>アカウントが見つかりません<br><br>' +
      '<small>AIRadarXで他のアカウントをスキャンすると<br>ここに表示されます</small></div>';
    return;
  }
  var html = '';
  for (var i = 0; i < data.accounts.length; i++) {
    var a      = data.accounts[i];
    var user   = a.user || {};
    var safeId = a.account.replace(/[^a-zA-Z0-9]/g, '_');
    var firstCommon = a.common_keywords.length > 0 ? a.common_keywords[0] : '';
    var kwHtml = '';
    for (var j = 0; j < a.keywords.length; j++) {
      var kw       = a.keywords[j];
      var isCommon = a.common_keywords.indexOf(kw) !== -1;
      if (isCommon) {
        kwHtml += '<span class="akwtag common" onclick="scrollToKw(\'' + escHtml(kw) + '\')" title="→ 右の考察へ">◈ #' + escHtml(kw) + '</span>';
      } else {
        kwHtml += '<a href="' + cmsUrl(kw, '') + '" target="_blank" class="akwtag normal">#' + escHtml(kw) + '</a>';
      }
    }
    html +=
      '<div class="acard" id="acard-' + safeId + '">' +
        '<div class="acard-head">' +
          '<div class="acard-left">' +
            '<div class="ahandle">' +
              '<a href="aiknowledgesns.php?view=account&u=' + encodeURIComponent(a.account) + '">@' + escHtml(a.account) + '</a>' +
              (user.name ? '<span style="font-family:\'Exo 2\',sans-serif;font-size:.78rem;color:var(--text);margin-left:8px;">' + escHtml(user.name) + '</span>' : '') +
              '<span class="common-badge">共通 ' + a.common_count + '</span>' +
            '</div>' +
            (user.public_metrics ? '<div style="font-family:\'Share Tech Mono\',monospace;font-size:.63rem;color:var(--vm);margin-bottom:6px;">フォロワー <span style="color:var(--text);">' + Number(user.public_metrics.followers_count).toLocaleString() + '</span> &nbsp;フォロー <span style="color:var(--text);">' + Number(user.public_metrics.following_count).toLocaleString() + '</span></div>' : '') +
            (user.description ? '<div class="abio">' + escHtml(user.description) + '</div>' : '<div class="abio" style="font-style:italic;font-size:.72rem;">bioなし — <a href="https://x.com/' + escHtml(a.account) + '" target="_blank" style="color:var(--blue);">Xで確認 →</a></div>') +
          '</div>' +
          '<div style="display:flex;flex-direction:column;gap:6px;align-items:flex-end;">' +
            '<button class="like-btn" id="likebtn-' + safeId + '" onclick="sendLike(\'' + escHtml(a.account) + '\',\'' + escHtml(firstCommon) + '\',this)"><span class="heart">♡</span><span>いいね</span></button>' +
            '<span id="followwrap-' + safeId + '" style="display:none;"><a href="https://x.com/intent/follow?screen_name=' + escHtml(a.account) + '" target="_blank" style="font-family:\'Share Tech Mono\',monospace;font-size:.65rem;color:#1da1f2;border:1px solid rgba(29,161,242,.35);padding:4px 10px;border-radius:3px;text-decoration:none;background:rgba(29,161,242,.08);">X フォロー →</a></span>' +
          '</div>' +
        '</div>' +
        '<div class="akws">' + kwHtml + '</div>' +
      '</div>';
  }
  area.innerHTML = html;
}
function renderTimeline(data) {
  var kwHtml = '';
  for (var i = 0; i < data.keywords.length; i++) {
    var kw = data.keywords[i];
    kwHtml += '<a href="' + cmsUrl(kw, '') + '" target="_blank" class="kwtag-link">#' + escHtml(kw) + '</a>';
  }
  g('my-kwlist').innerHTML = kwHtml;
  var area = g('timeline-area');
  if (!data.items || data.items.length === 0) {
    area.innerHTML = '<div class="empty">考察データなし<br><small>AIKnowledgeCMSでキーワードを生成してください</small></div>';
    return;
  }
  var html = '';
  for (var i = 0; i < data.items.length; i++) {
    var item  = data.items[i];
    var safeKw = item.keyword.replace(/[^a-zA-Z0-9\u3040-\u30ff\u4e00-\u9fff]/g, '_');
    html +=
      '<div class="tl-item" id="tl-' + safeKw + '">' +
        '<div class="tl-kw-row">' +
          '<a href="' + escHtml(item.cms_url) + '" target="_blank" class="tl-kwtag">#' + escHtml(item.keyword) + '</a>' +
          '<span class="tl-date">' + escHtml(item.date) + '</span>' +
        '</div>' +
        '<div class="tl-body" id="tlbody-' + safeKw + '">' + escHtml(item.analysis) + '</div>' +
        '<div class="tl-footer">' +
          '<button class="expand-btn" id="tlbtn-' + safeKw + '" onclick="toggleExpand(\'' + safeKw + '\')">▼ 続きを読む</button>' +
          '<a href="' + escHtml(item.cms_url) + '" target="_blank" class="cms-link">→ AIKnowledgeCMS</a>' +
        '</div>' +
      '</div>';
  }
  area.innerHTML = html;
}

function buildAssocUrl(url, assocId) {
  if (!assocId) { return url; }
  return url + (url.indexOf('?') !== -1 ? '&' : '?') + 'tag=' + encodeURIComponent(assocId);
}
function restoreLikedButtons() {
  for (var toAccount in likedAccounts) {
    var safeId = toAccount.replace(/[^a-zA-Z0-9]/g, '_');
    var btn = document.getElementById('likebtn-' + safeId);
    if (btn) { btn.classList.add('liked'); btn.innerHTML = '<span class="heart">♥</span><span>いいね済み</span>'; }
    var followWrap = document.getElementById('followwrap-' + safeId);
    if (followWrap) { followWrap.style.display = 'inline-block'; }
  }
}

xhrGet('?action=my_likes&account=' + encodeURIComponent(MY_ACCOUNT), function(err, data) {
  if (!err && data && data.ok) { renderLikeNotify(data); }
});
xhrGet('?action=sent_likes&account=' + encodeURIComponent(MY_ACCOUNT), function(err, data) {
  if (!err && data && data.ok && data.sent) {
    for (var i = 0; i < data.sent.length; i++) { likedAccounts[data.sent[i]] = true; }
  }
  xhrGet('?action=recommended&account=' + encodeURIComponent(MY_ACCOUNT), function(err2, data2) {
    if (err2 || !data2 || !data2.ok) {
      g('recommended-area').innerHTML = '<div class="empty">取得失敗</div>';
      return;
    }
    renderRecommended(data2);
    restoreLikedButtons();
  });
});
xhrGet('?action=timeline&account=' + encodeURIComponent(MY_ACCOUNT), function(err, data) {
  if (err || !data || !data.ok) {
    g('timeline-area').innerHTML = '<div class="empty">取得失敗</div>';
    return;
  }
  renderTimeline(data);
});

// adwidget.php JSONP で広告表示（マイページ）
function aigmAdMy(data) {
  if (!data || !data.html) { return; }
  var div = document.createElement('div');
  div.innerHTML = data.html;
  var topNav = document.querySelector('.top-nav');
  if (topNav) {
    topNav.parentNode.insertBefore(div, topNav.nextSibling);
    aigmExecScripts(div);
  }
}
(function() {
  var kw = (typeof MY_KEYWORDS !== 'undefined' && MY_KEYWORDS.length > 0) ? MY_KEYWORDS[0] : '';
  var s = document.createElement('script');
  s.src = 'https://aiknowledgecms.exbridge.jp/adwidget.php?callback=aigmAdMy&slot=mypage&limit=4&kw=' + encodeURIComponent(kw);
  document.body.appendChild(s);
})();
</script>
  <?php endif; ?>
</div>
<footer class="site-footer">
  当サイトはAmazonアソシエイト・プログラムに参加しています。商品リンクにはアフィリエイトリンクが含まれる場合があります。
</footer>
</body>
</html>
