<?php
$is_public = isset($is_public) ? $is_public : false;

date_default_timezone_set("Asia/Tokyo");

/* ★ FastAPI 版 getkeyword */
define("KEYWORD_SEED_API", "http://exbridge.ddns.net:8003/getkeyword");
define("NEWS_ANALYSIS_API", "http://exbridge.ddns.net:8003/news_analysis");

define("DATA_DIR", __DIR__ . "/data");
define("KEYWORD_JSON", __DIR__ . "/keyword.json");
define("NEWS_LIMIT", 5);
define("AIKNOWLEDGE_TOKEN", "秘密の文字列");

/* =========================================================
   adminモード判定（ここで一度だけ定義）
========================================================= */
$is_admin = isset($_GET["admin"]) && $_GET["admin"] === AIKNOWLEDGE_TOKEN;

/* =========================================================
   API : JSON GENERATION (for worker)
========================================================= */
/* =========================================================
   API : LIST JSON FILES (for worker)
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

    // 保存先
    $wav_name = str_replace(".json", ".wav", $json_file);
    $wav_path = DATA_DIR . "/" . $wav_name;
    $json_path = DATA_DIR . "/" . $json_file;

    // wav ダウンロード
    $wav_data = @file_get_contents($audio_url);
    if ($wav_data === false) {
        echo json_encode(array("status"=>"fail","reason"=>"audio download failed"));
        exit;
    }

    if (file_put_contents($wav_path, $wav_data) === false) {
        echo json_encode(array("status"=>"fail","reason"=>"audio save failed"));
        exit;
    }

    // json 更新（audio 情報を追記）
    if (file_exists($json_path)) {

        $json = json_decode(file_get_contents($json_path), true);
        if (!is_array($json)) {
            $json = array();
        }

        $json["audio_file"] = $wav_name;
        $json["audio_url"]  = "./data/" . $wav_name;
        $json["audio_generated_at"] = date("Y-m-d H:i:s");

        file_put_contents(
            $json_path,
            json_encode($json, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    echo json_encode(array(
        "status" => "ok",
        "file"   => $wav_name
    ));
    exit;
}

if (isset($_GET["list_json"])) {

    header("Content-Type: application/json; charset=utf-8");

    if (
        !isset($_GET["token"]) ||
        $_GET["token"] !== AIKNOWLEDGE_TOKEN
    ) {
        echo json_encode(array(), JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!file_exists(DATA_DIR)) {
        echo json_encode(array(), JSON_UNESCAPED_UNICODE);
        exit;
    }

    $files = array();

    foreach (scandir(DATA_DIR) as $f) {

        if ($f === "." || $f === "..") {
            continue;
        }

        // JSON 以外は除外
        if (substr($f, -5) !== ".json") {
            continue;
        }

        // 管理用メタJSONは除外
        if ($f === "keyword.json") {
            continue;
        }

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
            foreach($json["keywords"] as $k => $v){
                $valid_keywords[$k] = true;
            }
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

            if(
                strpos($ua, "bot") !== false ||
                strpos($ua, "crawler") !== false ||
                strpos($ua, "spider") !== false ||
                strpos($ua, "gptbot") !== false
            ){
                continue;
            }

            if(preg_match('/kw=([^&]+)/', $referrer, $m)){
                $kw = urldecode($m[1]);
                $kw = trim($kw);

                if(isset($valid_keywords[$kw])){
                    if(!isset($counts[$kw])){
                        $counts[$kw] = 0;
                    }
                    $counts[$kw]++;
                }
            }
        }
    }

    arsort($counts);

    return array_slice($counts, 0, $limit, true);
}


/* =========================
   API: Liveトレンド取得（aitrendと同一ロジック）
========================= */
if(isset($_GET["api_get_trend_keywords"])){

    header("Content-Type: application/json; charset=UTF-8");

    $log_file = __DIR__ . "/access.log";
    $keyword_file = __DIR__ . "/keyword.json";

    $valid_keywords = array();

    if(file_exists($keyword_file)){
        $json = json_decode(file_get_contents($keyword_file), true);

        if(isset($json["keywords"]) && is_array($json["keywords"])){
            foreach($json["keywords"] as $k => $v){
                $valid_keywords[$k] = true;
            }
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

            if(
                strpos($ua, "bot") !== false ||
                strpos($ua, "crawler") !== false ||
                strpos($ua, "spider") !== false ||
                strpos($ua, "gptbot") !== false
            ){
                continue;
            }

            if(preg_match('/kw=([^&]+)/', $referrer, $m)){
                $kw = urldecode($m[1]);
                $kw = trim($kw);

                if(isset($valid_keywords[$kw])){
                    if(!isset($counts[$kw])){
                        $counts[$kw] = 0;
                    }
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

    $token   = isset($_GET["token"]) ? $_GET["token"] : "";
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

        $desc = isset($json["keywords"][$keyword]["description"])
            ? $json["keywords"][$keyword]["description"]
            : "";

        echo json_encode(array(
            "keyword"     => $keyword,
            "description" => $desc
        ));

    }else{

        echo json_encode(array(
            "keyword"     => $keyword,
            "description" => ""
        ));
    }

    exit;
}



if (isset($_POST["api_update_keyword_description"])) {

    header("Content-Type: application/json; charset=utf-8");

    if (
        !isset($_POST["token"]) ||
        $_POST["token"] !== AIKNOWLEDGE_TOKEN
    ) {
        echo json_encode(array("status"=>"fail","reason"=>"invalid token"));
        exit;
    }

    if (
        !isset($_POST["keyword"]) ||
        !isset($_POST["description"])
    ) {
        echo json_encode(array("status"=>"fail","reason"=>"invalid payload"));
        exit;
    }

    $keyword = trim($_POST["keyword"]);
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

    file_put_contents(
        KEYWORD_JSON,
        json_encode(
            array("keywords"=>$data["keywords"]),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        )
    );

    echo json_encode(array("status"=>"ok"));
    exit;
}

if (isset($_POST["api_cleanup_keywords"])) {

    header("Content-Type: application/json; charset=utf-8");

    if (
        !isset($_POST["token"]) ||
        $_POST["token"] !== AIKNOWLEDGE_TOKEN
    ) {
        echo json_encode(array("status"=>"fail","reason"=>"invalid token"));
        exit;
    }

    $data = load_keyword_json_safe();
    $keywords = $data["keywords"];

    $deleted = array();
    $today = date("Y-m-d");

    /* ===== まとめて一般語判定 ===== */

    $kw_list = array_keys($keywords);

    $api_payload = json_encode(
        array("keywords"=>$kw_list),
        JSON_UNESCAPED_UNICODE
    );

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

        if (
            is_array($res) &&
            isset($res["results"]) &&
            is_array($res["results"])
        ) {
            foreach ($res["results"] as $row) {

                if (
                    isset($row["keyword"]) &&
                    isset($row["type"])
                ) {

                    $k = trim($row["keyword"]);

                    if ($k !== "") {
                        $type_map[$k] = $row["type"];
                    }
                }
            }
        }
    }

    foreach ($keywords as $kw => $info) {

        $created = isset($info["created"]) ? $info["created"] : "";

        /* ===== 当日生成は削除しない ===== */

        if ($created === $today) {
            continue;
        }

        if (
            isset($type_map[$kw]) &&
            $type_map[$kw] === "general"
        ) {
            $deleted[] = $kw;
            unset($keywords[$kw]);
            continue;
        }

        $count = isset($info["count"]) ? (int)$info["count"] : 0;
        $views = isset($info["views"]) ? (int)$info["views"] : 0;

        if ($count === 0 && $views === 0) {
            $deleted[] = $kw;
            unset($keywords[$kw]);
        }
    }

    file_put_contents(
        KEYWORD_JSON,
        json_encode(
            array("keywords"=>$keywords),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        )
    );

    echo json_encode(array(
        "status"=>"ok",
        "deleted"=>$deleted
    ));

    exit;
}



if (isset($_POST["api_cleanup_keywords_all"])) {

    header("Content-Type: application/json; charset=utf-8");

    if (
        !isset($_POST["token"]) ||
        $_POST["token"] !== AIKNOWLEDGE_TOKEN
    ) {
        echo json_encode(array("status"=>"fail","reason"=>"invalid token"));
        exit;
    }

    $data = load_keyword_json_safe();
    $keywords = $data["keywords"];

    $deleted = array();

    /* ===== まとめて一般語判定 ===== */

    $kw_list = array_keys($keywords);

    $api_payload = json_encode(
        array("keywords"=>$kw_list),
        JSON_UNESCAPED_UNICODE
    );

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

        if (
            is_array($res) &&
            isset($res["results"]) &&
            is_array($res["results"])
        ) {
            foreach ($res["results"] as $row) {

                if (
                    isset($row["keyword"]) &&
                    isset($row["type"])
                ) {

                    $k = trim($row["keyword"]);

                    if ($k !== "") {
                        $type_map[$k] = $row["type"];
                    }
                }
            }
        }
    }


    foreach ($keywords as $kw => $info) {

        if (
            isset($type_map[$kw]) &&
            $type_map[$kw] === "general"
        ) {
            $deleted[] = $kw;
            unset($keywords[$kw]);
            continue;
        }

        $count = isset($info["count"]) ? (int)$info["count"] : 0;
        $views = isset($info["views"]) ? (int)$info["views"] : 0;

        if ($count === 0 && $views === 0) {
            $deleted[] = $kw;
            unset($keywords[$kw]);
        }
    }

    file_put_contents(
        KEYWORD_JSON,
        json_encode(
            array("keywords"=>$keywords),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        )
    );

    echo json_encode(array(
        "status"=>"ok",
        "deleted"=>$deleted
    ));

    exit;


}


if (isset($_POST["api_generate_daily"])) {

    header("Content-Type: application/json; charset=utf-8");

    if (
        !isset($_POST["token"]) ||
        $_POST["token"] !== AIKNOWLEDGE_TOKEN
    ) {
        echo json_encode(array("status"=>"fail","reason"=>"invalid token"));
        exit;
    }

    $keyword = isset($_POST["keyword"]) ? trim($_POST["keyword"]) : "";

    if ($keyword === "") {
        echo json_encode(array("status"=>"fail","reason"=>"empty keyword"));
        exit;
    }

    // $today = date("Y-m-d");
    // ★ 当日ではなく前日を対象日にする
    $today = date("Y-m-d", strtotime("-1 day"));
    $json_file = DATA_DIR . "/" . $today . "_" . $keyword . ".json";

    // すでに存在するなら何もしない
    if (file_exists($json_file)) {
        echo json_encode(array(
            "status" => "skip",
            "reason" => "already exists"
        ));
        exit;
    }

    // ★ 日次JSON生成のみ
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

    if (
        !isset($_POST["token"]) ||
        $_POST["token"] !== AIKNOWLEDGE_TOKEN
    ) {
        echo json_encode(array("status"=>"fail","reason"=>"invalid token"));
        exit;
    }

    $base = isset($_POST["keyword"]) ? trim($_POST["keyword"]) : "";
    if ($base === "") {
        echo json_encode(array("status"=>"fail","reason"=>"empty keyword"));
        exit;
    }

    $result = seed_keyword_core($base, "worker");

    echo json_encode(
        array(
            "status" => "ok",
            "base"   => $base,
            "added"  => isset($result["added"]) ? $result["added"] : array()
        ),
        JSON_UNESCAPED_UNICODE
    );
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

    // ★ グローバル上限チェック
    if (count($state["added"]) >= 10) {
        return buildResult($state);
    }

    $baseAddedCount = processBaseKeyword($base, $state);

    // ★ 深さ1を超えたら派生キーワード処理をスキップ
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
    if ($state !== null) {
        return $state;
    }

    $data = load_keyword_json_safe();

    // ★既存キーワードにviewsがなければ初期化
    $needs_save = false;

    foreach ($data["keywords"] as $kw => $kw_data) {
        if (!isset($kw_data["views"])) {
            $data["keywords"][$kw]["views"] = 0;
            $needs_save = true;
        }
    }

    if ($needs_save) {
        file_put_contents(
            KEYWORD_JSON,
            json_encode(
                ["keywords" => $data["keywords"]],
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
            )
        );
    }

    return [
        "keywords"     => $data["keywords"],
        "added"        => [],
        "tried_seeds"  => [],
        "tried_map"    => [],
        "today"        => date("Y-m-d")
    ];
}


function processBaseKeyword($base, &$state) {
    if (isset($state["tried_map"][$base])) {
        return 0;
    }

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
    if (isset($state["keywords"][$keyword])) {
        return false;
    }

    $state["keywords"][$keyword] = [
        "created" => $state["today"],
        "count"   => 0,
        "views"   => 0  // ★ 追加
    ];
    generate_daily_json_on_seed($keyword, $state["today"]);
    $state["added"][] = $keyword;

    return true;
}

function fetchDerivedKeywords($base) {
    $response = http_post_json(
        KEYWORD_SEED_API,
        [
            "keyword"   => $base,
            "max_seeds" => 2  // ★ 2個まで
        ]
    );

    if (!isValidResponse($response)) {
        return [];
    }

    $keywords = [];
    foreach ($response["results"] as $row) {
        if (isset($row["keyword"]) && is_string($row["keyword"])) {
            $keywords[] = $row["keyword"];
        }
    }

    return array_values(array_unique($keywords));
}

function isValidResponse($response) {
    return is_array($response)
        && isset($response["results"])
        && is_array($response["results"]);
}

function processDerivedKeywords($keywords, $base, $mode, $depth, &$state, &$baseAddedCount) {
    shuffle($keywords);

    foreach ($keywords as $keyword) {
        // ★ グローバル上限
        if (count($state["added"]) >= 10) {
            break;
        }

        if (isset($state["tried_map"][$keyword])) {
            continue;
        }

        if (addKeywordIfNew($keyword, $state)) {
            $baseAddedCount++;
        }

        // ★ depth+1 で再帰（最大depth=1まで）
        seed_keyword_core($keyword, $mode, $depth + 1, $state);
    }
}

function saveResults($base, $baseAddedCount, &$state) {
    if (isset($state["keywords"][$base])) {
        $state["keywords"][$base]["count"] = $baseAddedCount;
    }

    file_put_contents(
        KEYWORD_JSON,
        json_encode(
            ["keywords" => $state["keywords"]],
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        )
    );
}

function buildResult($state) {
    return [
        "added"       => array_values(array_unique($state["added"])),
        "tried_seeds" => array_values(array_unique($state["tried_seeds"]))
    ];
}


/* =========================================================
   VIEW KEYWORD (GET)
========================================================= */
$view_keyword = "";
if (isset($_GET["kw"])) {
    $view_keyword = trim($_GET["kw"]);

    // ★ アクセス数をカウント
    if ($view_keyword !== "") {
        incrementKeywordViews($view_keyword);
    }
}

/* =========================================================
   アクセス数カウント関数（新規追加）
========================================================= */
function incrementKeywordViews($keyword) {
    $data = load_keyword_json_safe();

    // キーワードが存在する場合のみカウント
    if (isset($data["keywords"][$keyword])) {
        // views フィールドがなければ初期化
        if (!isset($data["keywords"][$keyword]["views"])) {
            $data["keywords"][$keyword]["views"] = 0;
        }

        // インクリメント
        $data["keywords"][$keyword]["views"]++;

        // 保存
        file_put_contents(
            KEYWORD_JSON,
            json_encode(
                ["keywords" => $data["keywords"]],
                JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
            )
        );
    }
}

/* =========================================================
   UTILITY
========================================================= */
function load_keyword_json_safe(){

    if (!file_exists(KEYWORD_JSON)) {
        return array(
            "keywords" => array()
        );
    }

    $json = json_decode(file_get_contents(KEYWORD_JSON), true);

    if (!is_array($json)) {
        return array(
            "keywords" => array()
        );
    }

    if (!isset($json["keywords"]) || !is_array($json["keywords"])) {
        $json["keywords"] = array();
    }

    return $json;
}

function count_today_created($created, $today){

    $count = 0;

    foreach ($created as $d) {
        if ($d === $today) {
            $count++;
        }
    }

    return $count;
}

function limit_seed_keywords($seeds, $limit = 2){

    if (!is_array($seeds)) {
        return array();
    }

    return array_slice($seeds, 0, $limit);
}


function h($s){
    return htmlspecialchars($s, ENT_QUOTES, "UTF-8");
}

function http_post_json($url, $payload, $timeout = 180){

    $ch = curl_init($url);

    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => array(
            "Content-Type: application/json"
        ),
    ));

    $res = curl_exec($ch);
    curl_close($ch);

    if ($res === false) return null;

    return json_decode($res, true);
}

function generate_daily_json_on_seed($keyword, $date){

    $json_file = DATA_DIR . "/" . $date . "_" . $keyword . ".json";

    if (file_exists($json_file)) {
        $old = json_decode(file_get_contents($json_file), true);
        if (
            is_array($old) &&
            isset($old["analysis"]) &&
            trim($old["analysis"]) !== ""
        ) {
            return true;
        }
    }

    $news = fetch_google_news($keyword, $date);
    if (!$news) {
        return false;
    }

    $analysis_res = http_post_json(
        NEWS_ANALYSIS_API,
        array(
            "keyword" => $keyword,
            "news"    => $news
        )
    );

    $analysis_text = "";
    if (
        is_array($analysis_res) &&
        isset($analysis_res["analysis"]) &&
        is_string($analysis_res["analysis"])
    ) {
        $analysis_text = $analysis_res["analysis"];
    }


    return (bool)file_put_contents(
        $json_file,
        json_encode(
            array(
                "date"         => $date,
                "keyword"      => $keyword,
                "generated_at" => date("Y-m-d H:i:s"),
                "analysis"     => $analysis_text,
                "news"         => $news
            ),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        )
    );
}


/* =========================================================
   GOOGLE NEWS (TODAY ONLY)
========================================================= */
function fetch_google_news($keyword, $date){

    $rss = "https://news.google.com/rss/search?q="
         . urlencode($keyword)
         . "&hl=ja&gl=JP&ceid=JP:ja";

    $xml = @file_get_contents($rss);
    if ($xml === false) return array();

    libxml_use_internal_errors(true);
    $obj = simplexml_load_string($xml);
    if (!$obj || !isset($obj->channel->item)) return array();

    $tz_jst = new DateTimeZone("Asia/Tokyo");

    $start = new DateTime($date . " 00:00:00", $tz_jst);
    $end   = new DateTime($date . " 23:59:59", $tz_jst);

    $items = array();

    foreach ($obj->channel->item as $item) {

        $pub = new DateTime((string)$item->pubDate);
        $pub->setTimezone($tz_jst);

        if ($pub < $start || $pub > $end) continue;

        $items[] = array(
            "title"   => trim((string)$item->title),
            "link"    => trim((string)$item->link),
            "pubDate" => $pub->format("Y-m-d H:i:s")
        );

        if (count($items) >= NEWS_LIMIT) break;
    }

    return $items;
}


/* =========================================================
   LOAD KEYWORDS
========================================================= */
$data = load_keyword_json_safe();

$keywords_data = $data["keywords"];

$keywords = array_keys($data["keywords"]);

/* =========================================================
   POST : SEED KEYWORD（adminのみ実行可能）
========================================================= */
$today = date("Y-m-d");

$base_date = $today;
if (isset($_GET["base_date"]) && preg_match("/^\d{4}-\d{2}-\d{2}$/", $_GET["base_date"])) {
    $base_date = $_GET["base_date"];
}

if (isset($_POST["seed_keyword"]) && $is_admin) {

    $base = trim($_POST["seed_keyword"]);
    if ($base !== "") {
        seed_keyword_core($base);
    }

    header("Location: ?kw=" . urlencode($base) . "&admin=" . urlencode(AIKNOWLEDGE_TOKEN));
    exit;
}

/* =========================================================
   VIEW KEYWORD (GET)
========================================================= */
$view_keyword = "";
if (isset($_GET["kw"])) {
    $view_keyword = trim($_GET["kw"]);
}

function build_results_by_date($keywords, $base_date, $view_keyword = "") {

    $results = array();

    foreach ($keywords as $kw) {

        if ($view_keyword !== "" && $kw !== $view_keyword) continue;

        // ★ 該当日があるキーワードだけ対象
        $today_file = DATA_DIR . "/" . $base_date . "_" . $kw . ".json";

        if (!file_exists($today_file)) {
            continue;
        }

        $results[$kw] = array();

        // ★ 過去10日分を収集
        for ($i = 0; $i < 10; $i++) {

            $ts = strtotime("-".$i." day", strtotime($base_date));
            $d  = date("Y-m-d", $ts);

            $json_file = DATA_DIR . "/" . $d . "_" . $kw . ".json";

            if (file_exists($json_file)) {
                $results[$kw][] = $json_file;
            }
        }
    }

    return $results;
}


/* =========================================================
   JSON GENERATION
========================================================= */

$results = build_results_by_date($keywords, $base_date, $view_keyword);

// ★ キーワード指定がない場合、閲覧数でソート
if ($view_keyword === "") {
    uksort($results, function($a, $b) use ($data) {
        $views_a = isset($data["keywords"][$a]["views"]) ? $data["keywords"][$a]["views"] : 0;
        $views_b = isset($data["keywords"][$b]["views"]) ? $data["keywords"][$b]["views"] : 0;
        return $views_b - $views_a;  // 降順（多い順）
    });
}

/* ===================== SEO 用変数 ===================== */
$page_title = "AI Knowledge CMS｜AIが毎日ニュースを分析・蓄積する知識メディア";
if ($view_keyword !== "") {
    $page_title = "「".$view_keyword."」の最新ニュース分析｜AI Knowledge CMS";
}

$description = "AI Knowledge CMS は、AIが毎日ニュースを収集・要約・分析し、知識 として蓄積する自律型ナレッジメディアです。";
if ($view_keyword !== "") {
    $description = "「".$view_keyword."」に関する最新ニュースをAIが要約・分析。 日付別に蓄積された知識を閲覧できます。";
}

$canonical = "https://aiknowledgecms.exbridge.jp/aiknowledgecms.php";
if ($view_keyword !== "") {
    $canonical .= "?kw=" . urlencode($view_keyword) . "&base_date=" . $base_date;
}
?>
<?php if (!$is_public): ?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title><?php echo h($page_title); ?></title>
<meta name="description" content="<?php echo h($description); ?>">
<meta name="robots" content="index,follow">
<link rel="canonical" href="<?php echo h($canonical); ?>">

<link rel="stylesheet" href="./style.css">
<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-BP0650KDFR"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());
  gtag('config', 'G-BP0650KDFR');
</script>
</head>
<body data-view-keyword="<?php echo h($view_keyword); ?>">
<?php endif; ?>

<div class="header-bar">

  <a href="./aiknowledgecms.php<?php echo $is_admin ? '?admin=' . urlencode(AIKNOWLEDGE_TOKEN) : ''; ?>" class="cms-logo">
 <img src="./images/aiknowledgecms_logo.png"></a>

  <a href="./aitrend.php" class="aitrend-link">
    <img src="./images/aitrend_logo.png">
    <span class="aitrend-text">AIトレンドキーワード</span>
  </a>

</div>

<h1>AI Knowledge CMS｜AIが毎日ニュースを分析・蓄積する知識メディア</h1>

<div id="thinking-overlay">
    <div id="thinking-box">
        <div id="thinking-title">Thinking…</div>
        <div id="thinking-sub">AI が考えています。しばらくお待ちください。</div>
    </div>
</div>

<?php if ($is_admin): ?>
<h3>入力したキーワードで本日のニュースを検索し要約します</h3>
<form method="post" onsubmit="showThinking()">
    <input
        type="text"
        name="seed_keyword"
        placeholder="キーワードを入力"
        style="width:260px"
    >
    <button type="submit">生成</button>
</form>
<?php endif; ?>

<div class="keywords">
<?php

$today_keywords = [];

// ★ 該当日JSONがあるキーワードだけ抽出
foreach ($keywords as $kw) {

    $today_file = DATA_DIR . "/" . $base_date . "_" . $kw . ".json";

    if (file_exists($today_file)) {
        $today_keywords[] = $kw;
    }
}

// ★ 閲覧数順にソート
usort($today_keywords, function($a, $b) use ($data) {
    $views_a = isset($data["keywords"][$a]["views"]) ? $data["keywords"][$a]["views"] : 0;
    $views_b = isset($data["keywords"][$b]["views"]) ? $data["keywords"][$b]["views"] : 0;
    return $views_b - $views_a;
});

// ★ 上位20件
$today_keywords = array_slice($today_keywords, 0, 30);

foreach ($today_keywords as $kw):
?>
<a href="#keyword-<?php echo h(urlencode($kw)); ?>">
<?php echo h($kw); ?>
</a>
<?php endforeach; ?>

</div>


<hr>
<?php
$prev_date = date("Y-m-d", strtotime($base_date . " -1 day"));
$next_date = date("Y-m-d", strtotime($base_date . " +1 day"));
$can_next  = ($next_date <= $today);
?>

<div style="display:flex;gap:12px;align-items:center;margin-bottom:16px">
    <a href="?base_date=<?php echo h($prev_date); ?><?php if($is_admin) echo "&admin=".h(AIKNOWLEDGE_TOKEN); ?>">←</a>
    <strong><?php echo h($base_date); ?></strong>
    <?php if ($can_next): ?>
        <a href="?base_date=<?php echo h($next_date); ?><?php if($is_admin) echo "&admin=".h(AIKNOWLEDGE_TOKEN); ?>">→</a> <a href="./daily_summary.php">サマリー</a>
    <?php else: ?>
        <span style="opacity:.3">→</span>
    <?php endif; ?>
</div>

<?php foreach ($results as $keyword => $files): ?>
<?php if (empty($files)) continue; ?>

<div class="keyword" id="keyword-<?php echo h(urlencode($keyword)); ?>">

<h3>
  <a href="?kw=<?php echo h($keyword); ?>&base_date=<?php echo h($base_date); ?><?php if($is_admin) echo "&admin=".h(AIKNOWLEDGE_TOKEN); ?>">
    <?php echo h($keyword); ?>
  </a>
  <span style="font-size:13px;color:#94a3b8;font-weight:normal;">
    (閲覧: <?php echo isset($data["keywords"][$keyword]["views"]) ? $data["keywords"][$keyword]["views"] : 0; ?>回)
  </span>
</h3>

<div class="scroll">

<?php foreach ($files as $file): ?>
<?php $daily = json_decode(file_get_contents($file), true); ?>

<div class="card">

<textarea readonly><?php
echo h(isset($daily["analysis"]) ? $daily["analysis"] : "");
?></textarea>

<?php foreach ($daily["news"] as $n): ?>
<hr>
<div class="title"><?php echo h($n["title"]); ?></div>
<div class="muted"><?php echo h($n["pubDate"]); ?></div>
<a href="<?php echo h($n["link"]); ?>" target="_blank">
Googleニュースを開く
</a>
<?php endforeach; ?>

</div>

<?php endforeach; ?>

</div>
</div>

<?php endforeach; ?>

<?php if ($view_keyword === ""): ?>
<div id="loading-indicator">
    読み込み中...
</div>
<?php endif; ?>


<script src="./script.js"></script>
<?php if (!$is_public): ?>
</body>
</html>
<?php endif; ?>

