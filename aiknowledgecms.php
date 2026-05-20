<?php
date_default_timezone_set("Asia/Tokyo");

/* ★ FastAPI 版 getkeyword */
define("KEYWORD_SEED_API", "http://exbridge.ddns.net:8003/getkeyword");
define("NEWS_ANALYSIS_API", "http://exbridge.ddns.net:8003/news_analysis");

define("DATA_DIR", __DIR__ . "/data");
define("KEYWORD_JSON", __DIR__ . "/keyword.json");
define("VIEWS_JSON",   __DIR__ . "/views.json");
define("NEWS_LIMIT", 5);
define("TOP_KEYWORD_LIMIT", 15);
define("DEFAULT_RESULT_LIMIT", 15);
define("DEFAULT_DAY_LIMIT", 5);
define("DETAIL_DAY_LIMIT", 10);
define("NEWS_DISPLAY_LIMIT", 3);
define("AIKNOWLEDGE_TOKEN", "秘密の文字列");

/* 日付(Y-m-d)から ./data/yyyymm/ ディレクトリパスを返す（なければ作成） */
function get_data_dir_for_date($date) {
    $ym = date("Ym", strtotime($date));
    $dir = DATA_DIR . "/" . $ym;
    if (!file_exists($dir)) { mkdir($dir, 0755, true); }
    return $dir;
}

/* 日付とキーワードからJSONファイルパスを返す
   yyyymmサブディレクトリを優先、なければdata直下にフォールバック（読み取り時）
   どちらも存在しない場合は新パス（書き込み先）を返す */
function get_json_path_for_date($date, $keyword) {
    $new_path = get_data_dir_for_date($date) . "/" . $date . "_" . $keyword . ".json";
    if (file_exists($new_path)) { return $new_path; }
    $old_path = DATA_DIR . "/" . $date . "_" . $keyword . ".json";
    if (file_exists($old_path)) { return $old_path; }
    return $new_path;
}

/* =========================================================
   OAuth2 PKCE（Xログイン）
========================================================= */
if (session_status() === PHP_SESSION_NONE) { session_start(); }

$x_keys_file = __DIR__ . '/x_api_keys.sh';
$x_keys = array();
if (file_exists($x_keys_file)) {
    $lines = file($x_keys_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (preg_match('/(?:export\s+)?(\w+)=["\']?([^"\'#\r\n]*)["\']?/', $line, $m)) {
            $x_keys[trim($m[1])] = trim($m[2]);
        }
    }
}
$x_client_id     = isset($x_keys['X_API_KEY'])    ? $x_keys['X_API_KEY']    : '';
$x_client_secret = isset($x_keys['X_API_SECRET']) ? $x_keys['X_API_SECRET'] : '';
$x_redirect_uri  = 'https://aiknowledgecms.exbridge.jp/aiknowledgecms.php';

function cms_base64url($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function cms_gen_verifier() {
    $bytes = '';
    for ($i = 0; $i < 32; $i++) { $bytes .= chr(mt_rand(0, 255)); }
    return cms_base64url($bytes);
}
function cms_gen_challenge($verifier) {
    return cms_base64url(hash('sha256', $verifier, true));
}
function cms_x_post($url, $post_data, $headers) {
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
function cms_x_get($url, $params, $token) {
    $full = count($params) ? $url . '?' . http_build_query($params) : $url;
    $opts = array('http' => array(
        'method'        => 'GET',
        'header'        => "Authorization: Bearer $token\r\nUser-Agent: AIKnowledgeCMS/1.0\r\n",
        'timeout'       => 12,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents($full, false, stream_context_create($opts));
    if (!$res) { $res = '{}'; }
    return json_decode($res, true);
}

if (isset($_GET['cms_logout'])) {
    session_destroy();
    header('Location: ' . $x_redirect_uri);
    exit;
}

if (isset($_GET['cms_login'])) {
    $verifier  = cms_gen_verifier();
    $challenge = cms_gen_challenge($verifier);
    $state     = md5(uniqid('', true));
    $_SESSION['cms_code_verifier'] = $verifier;
    $_SESSION['cms_oauth_state']   = $state;
    $params = array(
        'response_type'         => 'code',
        'client_id'             => $x_client_id,
        'redirect_uri'          => $x_redirect_uri,
        'scope'                 => 'tweet.read users.read offline.access',
        'state'                 => $state,
        'code_challenge'        => $challenge,
        'code_challenge_method' => 'S256',
    );
    header('Location: https://twitter.com/i/oauth2/authorize?' . http_build_query($params));
    exit;
}

if (isset($_GET['code']) && isset($_GET['state']) && isset($_SESSION['cms_oauth_state'])) {
    $saved_state    = $_SESSION['cms_oauth_state'];
    $saved_verifier = isset($_SESSION['cms_code_verifier']) ? $_SESSION['cms_code_verifier'] : '';
    if ($_GET['state'] === $saved_state) {
        $post = http_build_query(array(
            'grant_type'    => 'authorization_code',
            'code'          => $_GET['code'],
            'redirect_uri'  => $x_redirect_uri,
            'code_verifier' => $saved_verifier,
            'client_id'     => $x_client_id,
        ));
        $cred = base64_encode($x_client_id . ':' . $x_client_secret);
        $data = cms_x_post('https://api.twitter.com/2/oauth2/token', $post, array(
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . $cred,
        ));
        if (isset($data['access_token'])) {
            $_SESSION['cms_access_token'] = $data['access_token'];
            unset($_SESSION['cms_oauth_state'], $_SESSION['cms_code_verifier']);
            $me = cms_x_get('https://api.twitter.com/2/users/me', array(), $data['access_token']);
            if (isset($me['data']['username'])) {
                $_SESSION['cms_username'] = $me['data']['username'];
            }
        }
    }
    header('Location: ' . $x_redirect_uri);
    exit;
}

$cms_logged_in = isset($_SESSION['cms_access_token']) && $_SESSION['cms_access_token'] !== '';
$cms_username  = isset($_SESSION['cms_username']) ? $_SESSION['cms_username'] : '';

function cms_fetch_aixec_books_api($url) {
    $json = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json'));
        curl_setopt($ch, CURLOPT_USERAGENT, 'AIKnowledgeCMS/1.0');
        $json = curl_exec($ch);
        curl_close($ch);
    }
    if (!$json) {
        $ctx = stream_context_create(array(
            'http' => array(
                'method' => 'GET',
                'timeout' => 8,
                'header' => "User-Agent: AIKnowledgeCMS/1.0\r\nAccept: application/json\r\n",
                'ignore_errors' => true
            )
        ));
        $json = @file_get_contents($url, false, $ctx);
    }
    if (!$json) { return array(); }
    $data = json_decode($json, true);
    if (!is_array($data) || empty($data['items']) || !is_array($data['items'])) { return array(); }
    return $data['items'];
}

function cms_fetch_aixec_affiliate_rankings($limit) {
    $limit = max(1, min(10, (int)$limit));
    $fetch_limit = max(20, $limit * 4);
    $clicked = cms_fetch_aixec_books_api('https://aixec.exbridge.jp/books_ranking_api.php?limit=' . $fetch_limit);
    $items = array();
    $seen = array();
    foreach ($clicked as $item) {
        if (!isset($item['clicks']) || $item['clicks'] === null) { continue; }
        $key = !empty($item['isbn']) ? preg_replace('/\D/', '', $item['isbn']) : (isset($item['title']) ? $item['title'] : '');
        if ($key === '' || isset($seen[$key])) { continue; }
        $seen[$key] = true;
        $items[] = $item;
        if (count($items) >= $limit) { return $items; }
    }
    $fallback = cms_fetch_aixec_books_api('https://aixec.exbridge.jp/books_ranking_api.php?tab=ai&limit=' . $limit);
    foreach ($fallback as $item) {
        $key = !empty($item['isbn']) ? preg_replace('/\D/', '', $item['isbn']) : (isset($item['title']) ? $item['title'] : '');
        if ($key === '' || isset($seen[$key])) { continue; }
        $seen[$key] = true;
        $items[] = $item;
        if (count($items) >= $limit) { break; }
    }
    return $items;
}

function cms_fetch_new_ai_books($limit) {
    $limit = max(1, min(10, (int)$limit));
    $url = 'https://aixec.exbridge.jp/api.php?' . http_build_query(array(
        'path' => 'books/ranking',
        'genre_id' => '001005',
        'keyword' => 'AI',
        'hits' => $limit,
        'sort' => '-releaseDate',
    ));
    $json = false;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 8);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json'));
        curl_setopt($ch, CURLOPT_USERAGENT, 'AIKnowledgeCMS/1.0');
        $json = curl_exec($ch);
        curl_close($ch);
    }
    if (!$json) {
        $ctx = stream_context_create(array(
            'http' => array(
                'method' => 'GET',
                'timeout' => 8,
                'header' => "User-Agent: AIKnowledgeCMS/1.0\r\nAccept: application/json\r\n",
                'ignore_errors' => true
            )
        ));
        $json = @file_get_contents($url, false, $ctx);
    }
    if (!$json) { return array(); }
    $data = json_decode($json, true);
    $books = isset($data['result']['items']) && is_array($data['result']['items']) ? $data['result']['items'] : array();
    $items = array();
    foreach ($books as $i => $book) {
        $title = isset($book['title']) ? $book['title'] : '';
        if ($title === '') { continue; }
        $params = array('to' => 'rakuten', 'kw' => $title);
        if (!empty($book['isbn'])) { $params['jan'] = preg_replace('/\D/', '', $book['isbn']); }
        $direct_url = !empty($book['affiliate_url']) ? $book['affiliate_url'] : (!empty($book['item_url']) ? $book['item_url'] : '');
        if ($direct_url !== '') { $params['url'] = $direct_url; }
        $items[] = array(
            'rank' => $i + 1,
            'clicks' => null,
            'title' => $title,
            'maker' => isset($book['publisher_name']) ? $book['publisher_name'] : (isset($book['author']) ? $book['author'] : ''),
            'isbn' => isset($book['isbn']) ? $book['isbn'] : '',
            'price' => isset($book['item_price']) ? (int)$book['item_price'] : 0,
            'sales_date' => isset($book['sales_date']) ? $book['sales_date'] : '',
            'image_url' => isset($book['image_url']) ? $book['image_url'] : '',
            'affiliate_url' => 'https://aixec.exbridge.jp/go.php?' . http_build_query($params),
            'product_url' => isset($book['item_url']) ? $book['item_url'] : '',
        );
        if (count($items) >= $limit) { break; }
    }
    return $items;
}

function cms_render_aixec_affiliate_list($items) {
    if (empty($items) || !is_array($items)) { return; }
?>
  <div class="aixec-affiliate-list">
    <?php foreach ($items as $i => $item):
        $title = isset($item['title']) ? $item['title'] : '';
        $url   = !empty($item['affiliate_url']) ? $item['affiliate_url'] : (isset($item['product_url']) ? $item['product_url'] : '#');
        $image = isset($item['image_url']) ? $item['image_url'] : '';
        $maker = isset($item['maker']) ? $item['maker'] : '';
        $price = isset($item['price']) ? (int)$item['price'] : 0;
        $rank  = isset($item['rank']) ? (int)$item['rank'] : ($i + 1);
        $clicks = isset($item['clicks']) ? $item['clicks'] : null;
        $sales_date = isset($item['sales_date']) ? $item['sales_date'] : '';
    ?>
    <a class="aixec-affiliate-item" href="<?php echo h($url); ?>" target="_blank" rel="nofollow sponsored noopener">
      <?php if ($image): ?>
      <img src="<?php echo h($image); ?>" alt="<?php echo h($title); ?>" loading="lazy">
      <?php else: ?>
      <span class="aixec-affiliate-noimage"></span>
      <?php endif; ?>
      <span class="aixec-affiliate-body">
        <span class="aixec-affiliate-rank">#<?php echo $rank; ?><?php if ($clicks !== null): ?> / <?php echo (int)$clicks; ?> clicks<?php endif; ?></span>
        <span class="aixec-affiliate-name"><?php echo h($title); ?></span>
        <?php if ($maker): ?><span class="aixec-affiliate-maker"><?php echo h($maker); ?></span><?php endif; ?>
        <?php if ($sales_date): ?><span class="aixec-affiliate-maker">発売: <?php echo h($sales_date); ?></span><?php endif; ?>
        <?php if ($price > 0): ?><span class="aixec-affiliate-price"><?php echo number_format($price); ?>円</span><?php endif; ?>
      </span>
    </a>
    <?php endforeach; ?>
  </div>
<?php
}

function cms_render_aixec_affiliate($clicked_items, $new_items) {
    if ((empty($clicked_items) || !is_array($clicked_items)) && (empty($new_items) || !is_array($new_items))) { return; }
?>
<aside class="aixec-affiliate" aria-label="AIxEC アフィリエイト広告">
  <div class="aixec-affiliate-head">
    <div>
      <div class="aixec-affiliate-kicker">AIxEC Affiliate</div>
      <div class="aixec-affiliate-title">クリックが多い商品</div>
    </div>
    <a href="https://aixec.exbridge.jp/books_ranking.php" target="_blank" rel="noopener">一覧</a>
  </div>
  <?php cms_render_aixec_affiliate_list($clicked_items); ?>
  <?php if (!empty($new_items)): ?>
  <div class="aixec-affiliate-section-title">新着AI書籍</div>
  <?php cms_render_aixec_affiliate_list($new_items); ?>
  <?php endif; ?>
</aside>
<?php
}

/* =========================================================
   adminモード判定（セッションベース）
========================================================= */
$is_admin = ($cms_username === 'xb_bittensor');
$aixec_affiliate_items = cms_fetch_aixec_affiliate_rankings(6);
$aixec_new_ai_books = cms_fetch_new_ai_books(6);

/* =========================================================
   API : JSON GENERATION (for worker)
========================================================= */
if (isset($_GET["upload_audio"])) {

    header("Content-Type: application/json; charset=utf-8");

    if (
        !isset($_GET["token"]) ||
        $_GET["token"] !== AIKNOWLEDGE_TOKEN
    ) {
        echo json_encode(array("status"=>"fail","reason"=>"invalid token"));
        exit;
    }

    $raw = file_get_contents("php://input");
    $post = json_decode($raw, true);

    if (
        !is_array($post) ||
        !isset($post["audio_url"]) ||
        !isset($post["json_file"])
    ) {
        echo json_encode(array("status"=>"fail","reason"=>"invalid payload"));
        exit;
    }

    $audio_url = trim($post["audio_url"]);
    $json_file = basename($post["json_file"]);

    if ($audio_url === "" || $json_file === "") {
        echo json_encode(array("status"=>"fail","reason"=>"empty value"));
        exit;
    }

    $wav_name = str_replace(".json", ".wav", $json_file);
    $date_part = substr($json_file, 0, 10); /* YYYY-MM-DD */
    $sub_dir   = get_data_dir_for_date($date_part);
    $wav_path  = $sub_dir . "/" . $wav_name;
    $json_path = get_json_path_for_date($date_part, substr($json_file, 11, -5));

    $wav_data = @file_get_contents($audio_url);
    if ($wav_data === false) {
        echo json_encode(array("status"=>"fail","reason"=>"audio download failed"));
        exit;
    }

    if (file_put_contents($wav_path, $wav_data) === false) {
        echo json_encode(array("status"=>"fail","reason"=>"audio save failed"));
        exit;
    }

    if (file_exists($json_path)) {
        $json = json_decode(file_get_contents($json_path), true);
        if (!is_array($json)) { $json = array(); }
        $json["audio_file"] = $wav_name;
        $json["audio_url"]  = "./data/" . $wav_name;
        $json["audio_generated_at"] = date("Y-m-d H:i:s");
        file_put_contents($json_path, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    echo json_encode(array("status" => "ok", "file" => $wav_name));
    exit;
}

if (isset($_GET["list_json"])) {

    header("Content-Type: application/json; charset=utf-8");

    if (!isset($_GET["token"]) || $_GET["token"] !== AIKNOWLEDGE_TOKEN) {
        echo json_encode(array(), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!file_exists(DATA_DIR)) {
        echo json_encode(array(), JSON_UNESCAPED_UNICODE);
        exit;
    }

    $files = array();
    foreach (scandir(DATA_DIR) as $f) {
        if ($f === "." || $f === "..") { continue; }
        if (substr($f, -5) !== ".json") { continue; }
        if ($f === "keyword.json") { continue; }
        $files[] = $f;
    }
    sort($files);
    echo json_encode($files, JSON_UNESCAPED_UNICODE);
    exit;
}

/* =========================
   共通：Liveトレンド取得
========================= */
function get_live_trend_keywords($limit = 20){

    global $log_file, $keyword_file;
    $valid_keywords = array();

    if(file_exists($keyword_file)){
        $json = json_decode(file_get_contents($keyword_file), true);
        if(isset($json["keywords"]) && is_array($json["keywords"])){
            foreach($json["keywords"] as $k => $v){ $valid_keywords[$k] = true; }
        }
    }

    $counts = array();
    if(file_exists($log_file)){
        $lines = file($log_file);
        foreach($lines as $line){
            if(strpos($line, "kw=") === false) continue;
            $parts = explode(" | ", trim($line));
            if(count($parts) < 5) continue;
            $referrer = $parts[3];
            $ua       = strtolower($parts[4]);
            if(strpos($ua, "bot") !== false || strpos($ua, "crawler") !== false ||
               strpos($ua, "spider") !== false || strpos($ua, "gptbot") !== false){ continue; }
            if(preg_match('/kw=([^&]+)/', $referrer, $m)){
                $kw = urldecode($m[1]);
                $kw = trim($kw);
                if(isset($valid_keywords[$kw])){
                    if(!isset($counts[$kw])){ $counts[$kw] = 0; }
                    $counts[$kw]++;
                }
            }
        }
    }
    arsort($counts);
    return array_slice($counts, 0, $limit, true);
}

/* =========================
   API: Liveトレンド取得
========================= */
if(isset($_GET["api_get_trend_keywords"])){

    header("Content-Type: application/json; charset=UTF-8");
    $log_file     = __DIR__ . "/access.log";
    $keyword_file = __DIR__ . "/keyword.json";

    $valid_keywords = array();
    if(file_exists($keyword_file)){
        $json = json_decode(file_get_contents($keyword_file), true);
        if(isset($json["keywords"]) && is_array($json["keywords"])){
            foreach($json["keywords"] as $k => $v){ $valid_keywords[$k] = true; }
        }
    }

    $counts = array();
    if(file_exists($log_file)){
        $lines = file($log_file);
        foreach($lines as $line){
            if(strpos($line, "kw=") === false) continue;
            $parts = explode(" | ", trim($line));
            if(count($parts) < 5) continue;
            $referrer = $parts[3];
            $ua       = strtolower($parts[4]);
            if(strpos($ua, "bot") !== false || strpos($ua, "crawler") !== false ||
               strpos($ua, "spider") !== false || strpos($ua, "gptbot") !== false){ continue; }
            if(preg_match('/kw=([^&]+)/', $referrer, $m)){
                $kw = urldecode($m[1]);
                $kw = trim($kw);
                if(isset($valid_keywords[$kw])){
                    if(!isset($counts[$kw])){ $counts[$kw] = 0; }
                    $counts[$kw]++;
                }
            }
        }
    }
    arsort($counts);
    $top = array_slice($counts, 0, 20, true);
    echo json_encode(array_keys($top));
    exit;
}

/* =========================
   API: keyword info 取得
========================= */
if(isset($_GET["api_get_keyword_info"])){

    header("Content-Type: application/json; charset=UTF-8");
    $token   = isset($_GET["token"])   ? $_GET["token"]   : "";
    $keyword = isset($_GET["keyword"]) ? $_GET["keyword"] : "";

    if($token !== "秘密の文字列"){
        echo json_encode(array("error"=>"invalid_token"));
        exit;
    }
    if($keyword === ""){
        echo json_encode(array("error"=>"empty_keyword"));
        exit;
    }

    $file = __DIR__ . "/keyword.json";
    if(!file_exists($file)){
        echo json_encode(array("keyword"=>$keyword,"description"=>""));
        exit;
    }

    $json = json_decode(file_get_contents($file), true);
    if(!$json || !isset($json["keywords"])){
        echo json_encode(array("keyword"=>$keyword,"description"=>""));
        exit;
    }

    if(isset($json["keywords"][$keyword])){
        $desc = isset($json["keywords"][$keyword]["description"]) ? $json["keywords"][$keyword]["description"] : "";
        echo json_encode(array("keyword" => $keyword, "description" => $desc));
    } else {
        echo json_encode(array("keyword" => $keyword, "description" => ""));
    }
    exit;
}

if (isset($_POST["api_update_keyword_description"])) {

    header("Content-Type: application/json; charset=utf-8");

    if (!isset($_POST["token"]) || $_POST["token"] !== AIKNOWLEDGE_TOKEN) {
        echo json_encode(array("status"=>"fail","reason"=>"invalid token"));
        exit;
    }
    if (!isset($_POST["keyword"]) || !isset($_POST["description"])) {
        echo json_encode(array("status"=>"fail","reason"=>"invalid payload"));
        exit;
    }

    $keyword     = trim($_POST["keyword"]);
    $description = trim($_POST["description"]);

    if ($keyword === "" || $description === "") {
        echo json_encode(array("status"=>"fail","reason"=>"empty value"));
        exit;
    }

    $data = load_keyword_json_safe();
    if (!isset($data["keywords"][$keyword])) {
        echo json_encode(array("status"=>"fail","reason"=>"keyword not found"));
        exit;
    }

    $data["keywords"][$keyword]["description"] = $description;
    $data["keywords"][$keyword]["description_updated_at"] = date("Y-m-d H:i:s");
    save_keyword_json_safe($data["keywords"]);
    echo json_encode(array("status"=>"ok"));
    exit;
}

if (isset($_POST["api_cleanup_keywords"])) {

    header("Content-Type: application/json; charset=utf-8");

    if (!isset($_POST["token"]) || $_POST["token"] !== AIKNOWLEDGE_TOKEN) {
        echo json_encode(array("status"=>"fail","reason"=>"invalid token"));
        exit;
    }

    $data     = load_keyword_json_safe();
    $keywords = $data["keywords"];
    $kw_views = load_views_safe();
    $deleted  = array();
    $today    = date("Y-m-d");

    $kw_list     = array_keys($keywords);
    $api_payload = json_encode(array("keywords"=>$kw_list), JSON_UNESCAPED_UNICODE);

    $ch = curl_init("http://exbridge.ddns.net:8003/keyword_type_batch");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $api_payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));
    $response = curl_exec($ch);
    curl_close($ch);

    $type_map = array();
    if ($response !== false) {
        $res = json_decode($response, true);
        if (is_array($res) && isset($res["results"]) && is_array($res["results"])) {
            foreach ($res["results"] as $row) {
                if (isset($row["keyword"]) && isset($row["type"])) {
                    $k = trim($row["keyword"]);
                    if ($k !== "") { $type_map[$k] = $row["type"]; }
                }
            }
        }
    }

    foreach ($keywords as $kw => $info) {
        $created = isset($info["created"]) ? $info["created"] : "";
        if ($created === $today) { continue; }
        if (isset($type_map[$kw]) && $type_map[$kw] === "general") {
            $deleted[] = $kw;
            unset($keywords[$kw]);
            continue;
        }
        $count = isset($info["count"]) ? (int)$info["count"] : 0;
        $views = isset($kw_views[$kw]) ? (int)$kw_views[$kw] : 0;
        if ($count === 0 && $views === 0) {
            $deleted[] = $kw;
            unset($keywords[$kw]);
        }
    }

    save_keyword_json_safe($keywords);
    echo json_encode(array("status"=>"ok","deleted"=>$deleted));
    exit;
}

if (isset($_POST["api_cleanup_keywords_all"])) {

    header("Content-Type: application/json; charset=utf-8");

    if (!isset($_POST["token"]) || $_POST["token"] !== AIKNOWLEDGE_TOKEN) {
        echo json_encode(array("status"=>"fail","reason"=>"invalid token"));
        exit;
    }

    $data     = load_keyword_json_safe();
    $keywords = $data["keywords"];
    $kw_views = load_views_safe();
    $deleted  = array();

    $kw_list     = array_keys($keywords);
    $api_payload = json_encode(array("keywords"=>$kw_list), JSON_UNESCAPED_UNICODE);

    $ch = curl_init("http://exbridge.ddns.net:8003/keyword_type_batch");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $api_payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));
    $response = curl_exec($ch);
    curl_close($ch);

    $type_map = array();
    if ($response !== false) {
        $res = json_decode($response, true);
        if (is_array($res) && isset($res["results"]) && is_array($res["results"])) {
            foreach ($res["results"] as $row) {
                if (isset($row["keyword"]) && isset($row["type"])) {
                    $k = trim($row["keyword"]);
                    if ($k !== "") { $type_map[$k] = $row["type"]; }
                }
            }
        }
    }

    foreach ($keywords as $kw => $info) {
        if (isset($type_map[$kw]) && $type_map[$kw] === "general") {
            $deleted[] = $kw;
            unset($keywords[$kw]);
            continue;
        }
        $count = isset($info["count"]) ? (int)$info["count"] : 0;
        $views = isset($kw_views[$kw]) ? (int)$kw_views[$kw] : 0;
        if ($count === 0 && $views === 0) {
            $deleted[] = $kw;
            unset($keywords[$kw]);
        }
    }

    save_keyword_json_safe($keywords);
    echo json_encode(array("status"=>"ok","deleted"=>$deleted));
    exit;
}

if (isset($_POST["api_generate_daily"])) {

    header("Content-Type: application/json; charset=utf-8");

    if (!isset($_POST["token"]) || $_POST["token"] !== AIKNOWLEDGE_TOKEN) {
        echo json_encode(array("status"=>"fail","reason"=>"invalid token"));
        exit;
    }

    $keyword = isset($_POST["keyword"]) ? trim($_POST["keyword"]) : "";
    if ($keyword === "") {
        echo json_encode(array("status"=>"fail","reason"=>"empty keyword"));
        exit;
    }

    $today     = date("Y-m-d", strtotime("-1 day"));
    $json_file = get_json_path_for_date($today, $keyword);

    if (file_exists($json_file)) {
        echo json_encode(array("status" => "skip", "reason" => "already exists"));
        exit;
    }

    $ok = generate_daily_json_on_seed($keyword, $today);
    if ($ok) {
        echo json_encode(array("status"=>"ok"));
    } else {
        echo json_encode(array("status"=>"fail","reason"=>"no news"));
    }
    exit;
}

if (isset($_POST["api_seed"])) {

    header("Content-Type: application/json; charset=utf-8");

    if (!isset($_POST["token"]) || $_POST["token"] !== AIKNOWLEDGE_TOKEN) {
        echo json_encode(array("status"=>"fail","reason"=>"invalid token"));
        exit;
    }

    $base = isset($_POST["keyword"]) ? trim($_POST["keyword"]) : "";
    if ($base === "") {
        echo json_encode(array("status"=>"fail","reason"=>"empty keyword"));
        exit;
    }

    $result = seed_keyword_core($base, "worker");
    echo json_encode(array(
        "status" => "ok",
        "base"   => $base,
        "added"  => isset($result["added"]) ? $result["added"] : array()
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

/* =========================================================
   API: keyword.json 診断（tokenが一致すれば誰でも確認可）
========================================================= */
if (isset($_GET["api_check_keyword_json"])) {
    header("Content-Type: application/json; charset=utf-8");
    if (!isset($_GET["token"]) || $_GET["token"] !== AIKNOWLEDGE_TOKEN) {
        echo json_encode(array("status"=>"fail","reason"=>"invalid token"));
        exit;
    }
    $exists = file_exists(KEYWORD_JSON);
    $size   = $exists ? filesize(KEYWORD_JSON) : 0;
    $raw    = $exists ? file_get_contents(KEYWORD_JSON) : "";
    $parsed = json_decode($raw, true);
    $valid  = is_array($parsed) && isset($parsed["keywords"]);
    $count  = $valid ? count($parsed["keywords"]) : 0;
    echo json_encode(array(
        "exists"   => $exists,
        "size"     => $size,
        "valid"    => $valid,
        "keywords" => $count,
        "json_error" => $valid ? null : json_last_error_msg()
    ), JSON_UNESCAPED_UNICODE);
    exit;
}

/* =========================================================
   CONFIG
========================================================= */
if (!file_exists(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
}

function seed_keyword_core($base, $mode = "browser", $depth = 0, &$state = null) {
    $state = initializeStateIfNeeded($state);
    if (count($state["added"]) >= 10) { return buildResult($state); }
    $baseAddedCount = processBaseKeyword($base, $state);
    if ($depth > 1) {
        saveResults($base, $baseAddedCount, $state);
        return buildResult($state);
    }
    $derivedKeywords = fetchDerivedKeywords($base);
    if ($mode !== "browser" && !empty($derivedKeywords)) {
        processDerivedKeywords($derivedKeywords, $base, $mode, $depth, $state, $baseAddedCount);
    }
    saveResults($base, $baseAddedCount, $state);
    return buildResult($state);
}

function initializeStateIfNeeded($state) {
    if ($state !== null) { return $state; }
    $data = load_keyword_json_safe();
    return array(
        "keywords"    => $data["keywords"],
        "added"       => array(),
        "tried_seeds" => array(),
        "tried_map"   => array(),
        "today"       => date("Y-m-d")
    );
}

function processBaseKeyword($base, &$state) {
    if (isset($state["tried_map"][$base])) { return 0; }
    $state["tried_map"][$base] = true;
    $baseNews = fetch_google_news($base, $state["today"]);
    if ($baseNews) {
        addKeywordIfNew($base, $state);
    } else {
        $state["tried_seeds"][] = $base;
    }
    return 0;
}

function addKeywordIfNew($keyword, &$state) {
    if (isset($state["keywords"][$keyword])) { return false; }
    $state["keywords"][$keyword] = array("created" => $state["today"], "count" => 0);
    generate_daily_json_on_seed($keyword, $state["today"]);
    $state["added"][] = $keyword;
    return true;
}

function fetchDerivedKeywords($base) {
    $response = http_post_json(KEYWORD_SEED_API, array("keyword" => $base, "max_seeds" => 2));
    if (!isValidResponse($response)) { return array(); }
    $keywords = array();
    foreach ($response["results"] as $row) {
        if (isset($row["keyword"]) && is_string($row["keyword"])) { $keywords[] = $row["keyword"]; }
    }
    return array_values(array_unique($keywords));
}

function isValidResponse($response) {
    return is_array($response) && isset($response["results"]) && is_array($response["results"]);
}

function processDerivedKeywords($keywords, $base, $mode, $depth, &$state, &$baseAddedCount) {
    shuffle($keywords);
    foreach ($keywords as $keyword) {
        if (count($state["added"]) >= 10) { break; }
        if (isset($state["tried_map"][$keyword])) { continue; }
        if (addKeywordIfNew($keyword, $state)) { $baseAddedCount++; }
        seed_keyword_core($keyword, $mode, $depth + 1, $state);
    }
}

function saveResults($base, $baseAddedCount, &$state) {
    if (isset($state["keywords"][$base])) { $state["keywords"][$base]["count"] = $baseAddedCount; }
    save_keyword_json_safe($state["keywords"]);
}

function buildResult($state) {
    return array(
        "added"       => array_values(array_unique($state["added"])),
        "tried_seeds" => array_values(array_unique($state["tried_seeds"]))
    );
}

/* =========================================================
   VIEW KEYWORD (GET)
========================================================= */
$view_keyword = "";
if (isset($_GET["kw"])) {
    $view_keyword = trim($_GET["kw"]);
    if ($view_keyword !== "") { incrementKeywordViews($view_keyword); }
}

function incrementKeywordViews($keyword) {
    $views = load_views_safe();
    $views[$keyword] = isset($views[$keyword]) ? $views[$keyword] + 1 : 1;
    save_views_safe($views);
}

/* =========================================================
   UTILITY
========================================================= */
function load_keyword_json_safe(){
    if (!file_exists(KEYWORD_JSON)) { return array("keywords" => array()); }
    $raw = @file_get_contents(KEYWORD_JSON);
    if ($raw === false || trim($raw) === "") { return array("keywords" => array()); }
    $json = json_decode($raw, true);
    if (!is_array($json)) { return array("keywords" => array()); }
    if (!isset($json["keywords"]) || !is_array($json["keywords"])) { $json["keywords"] = array(); }
    return $json;
}

/* アトミック書き込み: tmpファイルに書いてrenameで入れ替え（読み込み中に壊れない） */
function save_keyword_json_safe($keywords){
    $str = json_encode(array("keywords" => $keywords), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($str === false) { return false; }
    $tmp = KEYWORD_JSON . ".tmp." . getmypid() . "." . mt_rand();
    if (file_put_contents($tmp, $str, LOCK_EX) === false) { @unlink($tmp); return false; }
    return rename($tmp, KEYWORD_JSON);
}

/* ビューカウント専用（keyword.jsonとは別ファイル → 書き込み頻度を分離） */
function load_views_safe(){
    if (!file_exists(VIEWS_JSON)) { return array(); }
    $raw = @file_get_contents(VIEWS_JSON);
    if (!$raw) { return array(); }
    $d = json_decode($raw, true);
    return is_array($d) ? $d : array();
}
function save_views_safe($views){
    $str = json_encode($views, JSON_UNESCAPED_UNICODE);
    if ($str === false) { return false; }
    $tmp = VIEWS_JSON . ".tmp." . getmypid() . "." . mt_rand();
    if (file_put_contents($tmp, $str, LOCK_EX) === false) { @unlink($tmp); return false; }
    return rename($tmp, VIEWS_JSON);
}

function h($s){
    return htmlspecialchars($s, ENT_QUOTES, "UTF-8");
}

function http_post_json($url, $payload, $timeout = 180){
    $ch = curl_init($url);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => array("Content-Type: application/json"),
    ));
    $res = curl_exec($ch);
    curl_close($ch);
    if ($res === false) { return null; }
    return json_decode($res, true);
}

function generate_daily_json_on_seed($keyword, $date){
    $json_file = get_json_path_for_date($date, $keyword);
    if (file_exists($json_file)) {
        $old = json_decode(file_get_contents($json_file), true);
        if (is_array($old) && isset($old["analysis"]) && trim($old["analysis"]) !== "") { return true; }
    }
    $news = fetch_google_news($keyword, $date);
    if (!$news) { return false; }
    $analysis_res  = http_post_json(NEWS_ANALYSIS_API, array("keyword" => $keyword, "news" => $news));
    $analysis_text = "";
    if (is_array($analysis_res) && isset($analysis_res["analysis"]) && is_string($analysis_res["analysis"])) {
        $analysis_text = $analysis_res["analysis"];
    }
    return (bool)file_put_contents($json_file, json_encode(array(
        "date"         => $date,
        "keyword"      => $keyword,
        "generated_at" => date("Y-m-d H:i:s"),
        "analysis"     => $analysis_text,
        "news"         => $news
    ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

/* =========================================================
   GOOGLE NEWS (TODAY ONLY)
========================================================= */
function fetch_google_news($keyword, $date){
    $rss = "https://news.google.com/rss/search?q=" . urlencode($keyword) . "&hl=ja&gl=JP&ceid=JP:ja";
    $xml = @file_get_contents($rss);
    if ($xml === false) { return array(); }
    libxml_use_internal_errors(true);
    $obj = simplexml_load_string($xml);
    if (!$obj || !isset($obj->channel->item)) { return array(); }
    $tz_jst = new DateTimeZone("Asia/Tokyo");
    $start  = new DateTime($date . " 00:00:00", $tz_jst);
    $end    = new DateTime($date . " 23:59:59", $tz_jst);
    $items  = array();
    foreach ($obj->channel->item as $item) {
        $pub = new DateTime((string)$item->pubDate);
        $pub->setTimezone($tz_jst);
        if ($pub < $start || $pub > $end) { continue; }
        $items[] = array(
            "title"   => trim((string)$item->title),
            "link"    => trim((string)$item->link),
            "pubDate" => $pub->format("Y-m-d H:i:s")
        );
        if (count($items) >= NEWS_LIMIT) { break; }
    }
    return $items;
}

/* =========================================================
   LOAD KEYWORDS
========================================================= */
$data          = load_keyword_json_safe();
$keywords_data = $data["keywords"];
$keywords      = array_keys($data["keywords"]);
$views_data    = load_views_safe();

/* =========================================================
   POST : SEED KEYWORD（adminのみ）
========================================================= */
$today     = date("Y-m-d");
$base_date = date("Y-m-d", strtotime("-1 day"));
if (isset($_GET["base_date"]) && preg_match("/^\d{4}-\d{2}-\d{2}$/", $_GET["base_date"])) {
    $base_date = $_GET["base_date"];
}

if (isset($_POST["seed_keyword"]) && $is_admin) {
    $base = trim($_POST["seed_keyword"]);
    if ($base !== "") { seed_keyword_core($base); }
    header("Location: ?kw=" . urlencode($base));
    exit;
}

$view_keyword = "";
if (isset($_GET["kw"])) { $view_keyword = trim($_GET["kw"]); }

function build_results_by_date($keywords, $base_date, $view_keyword = "") {
    $results = array();
    $day_limit = $view_keyword !== "" ? DETAIL_DAY_LIMIT : DEFAULT_DAY_LIMIT;
    foreach ($keywords as $kw) {
        if ($view_keyword !== "" && $kw !== $view_keyword) { continue; }
        $_ym1 = date("Ym", strtotime($base_date));
        $today_file = DATA_DIR . "/" . $_ym1 . "/" . $base_date . "_" . $kw . ".json";
        if (!file_exists($today_file)) { $today_file = DATA_DIR . "/" . $base_date . "_" . $kw . ".json"; }
        if (!file_exists($today_file)) { continue; }
        $results[$kw] = array();
        for ($i = 0; $i < $day_limit; $i++) {
            $ts        = strtotime("-".$i." day", strtotime($base_date));
            $d         = date("Y-m-d", $ts);
            $_ym2 = date("Ym", strtotime($d));
            $json_file = DATA_DIR . "/" . $_ym2 . "/" . $d . "_" . $kw . ".json";
            if (!file_exists($json_file)) { $json_file = DATA_DIR . "/" . $d . "_" . $kw . ".json"; }
            if (file_exists($json_file)) { $results[$kw][] = $json_file; }
        }
    }
    return $results;
}

$results = build_results_by_date($keywords, $base_date, $view_keyword);

if ($view_keyword === "") {
    uksort($results, function($a, $b) use ($views_data) {
        $views_a = isset($views_data[$a]) ? $views_data[$a] : 0;
        $views_b = isset($views_data[$b]) ? $views_data[$b] : 0;
        return $views_b - $views_a;
    });
    $results = array_slice($results, 0, DEFAULT_RESULT_LIMIT, true);
}

/* ===================== SEO 用変数 ===================== */
$page_title = "AI Knowledge CMS｜AIが毎日ニュースを分析・蓄積する知識メディア";
if ($view_keyword !== "") {
    $page_title = "「".$view_keyword."」の最新ニュース分析｜AI Knowledge CMS";
}
$description = "AI Knowledge CMS は、AIが毎日ニュースを収集・要約・分析し、知識として蓄積する自律型ナレッジメディアです。";
if ($view_keyword !== "") {
    $description = "「".$view_keyword."」に関する最新ニュースをAIが要約・分析。日付別に蓄積された知識を閲覧できます。";
}
$canonical = "https://aiknowledgecms.exbridge.jp/aiknowledgecms.php";
if ($view_keyword !== "") {
    $canonical .= "?kw=" . urlencode($view_keyword) . "&base_date=" . $base_date;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo h($page_title); ?></title>
<meta name="description" content="<?php echo h($description); ?>">
<meta name="robots" content="index,follow">
<link rel="canonical" href="<?php echo h($canonical); ?>">
<link href="https://fonts.googleapis.com/css2?family=Share+Tech+Mono&family=Exo+2:wght@300;400;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="./aiknowledgecms.css">
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-BP0650KDFR"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'G-BP0650KDFR');
</script>
</head>
<body>
<div class="app">

<div class="header-bar">
  <a href="./aiknowledgecms.php" class="cms-logo">
    <img src="./images/aiknowledgecms_logo.png">
  </a>
  <a href="./newskeyword.php" class="aitrend-link">
    <img src="./images/newskeyword_logo.png">
    <span class="aitrend-text">AI思考のキーワード＆ニュース</span>
  </a>
  <a href="./aitrend.php" class="aitrend-link">
    <img src="./images/aitrend_logo.png">
    <span class="aitrend-text">AIトレンドキーワード辞典</span>
  </a>
  <a href="./simpletrack.php?dashboard=1" class="aitrend-link">
    <img src="./images/aiwebanalytics_logo.png">
    <span class="aitrend-text">AI Web Analytics</span>
  </a>
  <div class="cms-loginbar">
    <?php if ($cms_logged_in): ?>
      <span class="user">● @<?php echo h($cms_username); ?></span>
      <a href="?cms_logout=1">logout</a>
    <?php else: ?>
      <a href="?cms_login=1">X でログイン</a>
    <?php endif; ?>
  </div>
</div>

<section class="vwork-promo" aria-label="バイブコーディング導線">
  <div class="vwork-promo-copy">
    <div class="vwork-promo-kicker">Built with Vibe Coding</div>
    <div class="vwork-promo-title">AIKnowledgeCMSは、バイブコーディングで育てている知識メディアです。</div>
  </div>
  <div class="vwork-promo-links">
    <a class="primary" href="https://exbridge.jp/seminar.html" target="_blank" rel="noopener">バイブコーディングセミナー</a>
    <a href="https://exbridge.jp/vwork.html" target="_blank" rel="noopener">VWork</a>
    <a href="https://katsushi2441.github.io/vwork/" target="_blank" rel="noopener">VWorkブログ</a>
  </div>
</section>

<div class="content-shell">
<main class="content-main">

<h1>AI Knowledge CMS｜AIが毎日ニュースを分析・蓄積する知識メディア</h1>

<div id="thinking-overlay">
  <div id="thinking-box">
    <div id="thinking-title">Thinking…</div>
    <div id="thinking-sub">AI が考えています。しばらくお待ちください。</div>
  </div>
</div>

<?php if ($is_admin): ?>
<div class="seed-form">
  <div class="seed-form-title">◯ ADMIN — キーワード追加</div>
  <form method="post" onsubmit="document.getElementById('thinking-overlay').classList.add('show')">
    <input type="text" name="seed_keyword" placeholder="キーワードを入力">
    <button type="submit">生成</button>
  </form>
</div>
<?php endif; ?>

<div class="keywords">
<?php
$today_keywords = array();
foreach ($keywords as $kw) {
    $_ym3 = date("Ym", strtotime($base_date));
    $today_file = DATA_DIR . "/" . $_ym3 . "/" . $base_date . "_" . $kw . ".json";
    if (!file_exists($today_file)) { $today_file = DATA_DIR . "/" . $base_date . "_" . $kw . ".json"; }
    if (file_exists($today_file)) { $today_keywords[] = $kw; }
}
usort($today_keywords, function($a, $b) use ($views_data) {
    $views_a = isset($views_data[$a]) ? $views_data[$a] : 0;
    $views_b = isset($views_data[$b]) ? $views_data[$b] : 0;
    return $views_b - $views_a;
});
$today_keywords = array_slice($today_keywords, 0, TOP_KEYWORD_LIMIT);
foreach ($today_keywords as $kw):
?>
<a href="#keyword-<?php echo h(urlencode($kw)); ?>"><?php echo h($kw); ?></a>
<?php endforeach; ?>
</div>

<hr style="border:none;border-top:1px solid rgba(0,255,136,.1);margin-bottom:16px;">

<?php
$prev_date = date("Y-m-d", strtotime($base_date . " -1 day"));
$next_date = date("Y-m-d", strtotime($base_date . " +1 day"));
$can_next  = ($next_date <= $today);
?>
<div class="date-nav">
  <a href="?base_date=<?php echo h($prev_date); ?>">←</a>
  <strong><?php echo h($base_date); ?></strong>
  <?php if ($can_next): ?>
    <a href="?base_date=<?php echo h($next_date); ?>">→</a>
    <a href="./daily_summary.php" class="summary-link">サマリー</a>
  <?php else: ?>
    <span style="opacity:.2;font-family:'Share Tech Mono',monospace;">→</span>
  <?php endif; ?>
</div>

<?php foreach ($results as $keyword => $files): ?>
<?php if (empty($files)) { continue; } ?>

<div class="keyword" id="keyword-<?php echo h(urlencode($keyword)); ?>">
  <h3>
    <a href="?kw=<?php echo h($keyword); ?>&base_date=<?php echo h($base_date); ?>"><?php echo h($keyword); ?></a>
    <span class="kw-views">(閲覧: <?php echo isset($views_data[$keyword]) ? $views_data[$keyword] : 0; ?>回)</span>
  </h3>
  <div class="scroll">
  <?php foreach ($files as $file): ?>
  <?php $daily = json_decode(file_get_contents($file), true); ?>
  <div class="card">
    <textarea readonly rows="5"><?php echo h(isset($daily["analysis"]) ? $daily["analysis"] : ""); ?></textarea>
    <?php foreach (array_slice(isset($daily["news"]) && is_array($daily["news"]) ? $daily["news"] : array(), 0, NEWS_DISPLAY_LIMIT) as $n): ?>
    <hr>
    <div class="title"><?php echo h($n["title"]); ?></div>
    <div class="muted"><?php echo h($n["pubDate"]); ?></div>
    <a href="<?php echo h($n["link"]); ?>" target="_blank">Googleニュースを開く</a>
    <?php endforeach; ?>
  </div>
  <?php endforeach; ?>
  </div>
</div>

<?php endforeach; ?>

<?php if ($view_keyword === ""): ?>
<div id="loading-indicator">◎ loading...</div>
<?php endif; ?>

</main>
<?php cms_render_aixec_affiliate($aixec_affiliate_items, $aixec_new_ai_books); ?>
</div>

</div><!-- /.app -->

<footer class="site-footer">
  AI Knowledge CMS — AIが毎日ニュースを分析・蓄積する知識メディア
</footer>

<script src="./script.js"></script>
</body>
</html>
