<?php
date_default_timezone_set("Asia/Tokyo");

/* ★ FastAPI 版 getkeyword */
define("KEYWORD_SEED_API", "http://exbridge.ddns.net:8003/getkeyword");
define("NEWS_ANALYSIS_API", "http://exbridge.ddns.net:8003/news_analysis");

define("DATA_DIR", __DIR__ . "/data");
define("KEYWORD_JSON", __DIR__ . "/keyword.json");
define("NEWS_LIMIT", 5);
define("AIKNOWLEDGE_TOKEN", "秘密の文字列");

/* =========================================================
   API : JSON GENERATION (for worker)
========================================================= */
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

function seed_keyword_core($base, $mode = "browser", $depth = 0, &$state = null){

    if ($state === null) {

        $data = load_keyword_json_safe();

        $state = array(
            "keywords"     => $data["keywords"],
            "created"      => $data["created"],
            "counts"       => isset($data["counts"]) ? $data["counts"] : array(),
            "added"        => array(),
            "tried_seeds"  => array(),
            "tried_map"    => array(),
            "today"        => date("Y-m-d")
        );
    }

    if ($depth > 0) {
        return array(
            "added" => $state["added"],
            "tried_seeds" => $state["tried_seeds"]
        );
    }

    /* ★ この base が生んだ数だけを数える */
    $base_added_count = 0;

    /* ===== base 処理 ===== */
    if (!isset($state["tried_map"][$base])) {

        $state["tried_map"][$base] = true;

        $base_news = fetch_google_news($base, $state["today"]);
        if ($base_news) {

            if (!in_array($base, $state["keywords"], true)) {
                $state["keywords"][] = $base;
                $state["created"][$base] = $state["today"];
                if (!isset($state["counts"][$base])) {
                    $state["counts"][$base] = 0;
                }
                generate_daily_json_on_seed($base, $state["today"]);
                $state["added"][] = $base;
            }
        } else {
            $state["tried_seeds"][] = $base;
        }
    }

    /* ===== 派生キーワード取得（FastAPI）===== */
    $res = http_post_json(
        KEYWORD_SEED_API,
        array(
            "keyword"   => $base,
            "max_seeds" => 10
        )
    );

    if (
        !is_array($res) ||
        !isset($res["results"]) ||
        !is_array($res["results"])
    ) {
        goto SAVE_AND_RETURN;
    }

    $seeds = array();

    foreach ($res["results"] as $row) {
        if (isset($row["keyword"]) && is_string($row["keyword"])) {
            $seeds[] = $row["keyword"];
        }
    }

    $seeds = array_values(array_unique($seeds));

    if ($mode === "browser") {
        goto SAVE_AND_RETURN;
    }

    shuffle($seeds);

    foreach ($seeds as $s) {

        if (count($state["added"]) >= 10) break;
        if (isset($state["tried_map"][$s])) continue;

        /* ★ この base 由来で新規追加されたかだけを見る */
        if (!in_array($s, $state["keywords"], true)) {

            $state["keywords"][] = $s;
            $state["created"][$s] = $state["today"];
            if (!isset($state["counts"][$s])) {
                $state["counts"][$s] = 0;
            }

            generate_daily_json_on_seed($s, $state["today"]);

            $state["added"][] = $s;
            $base_added_count++;
        }

        seed_keyword_core($s, $mode, $depth + 1, $state);
    }

SAVE_AND_RETURN:

    /* ★ base が生んだ数をここで確定 */
    $state["counts"][$base] = $base_added_count;

    file_put_contents(
        KEYWORD_JSON,
        json_encode(
            array(
                "keywords" => array_values(array_unique($state["keywords"])),
                "created"  => $state["created"],
                "counts"   => $state["counts"]
            ),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        )
    );

    return array(
        "added" => array_values(array_unique($state["added"])),
        "tried_seeds" => array_values(array_unique($state["tried_seeds"]))
    );
}










/* =========================================================
   UTILITY
========================================================= */
function load_keyword_json_safe(){

    if (!file_exists(KEYWORD_JSON)) {
        return array(
            "keywords" => array(),
            "created"  => array(),
            "counts"   => array()
        );
    }

    $json = json_decode(file_get_contents(KEYWORD_JSON), true);

    if (!is_array($json)) {
        return array(
            "keywords" => array(),
            "created"  => array(),
            "counts"   => array()
        );
    }

    if (!isset($json["keywords"]) || !is_array($json["keywords"])) {
        $json["keywords"] = array();
    }

    if (!isset($json["created"]) || !is_array($json["created"])) {
        $json["created"] = array();
    }

    if (!isset($json["counts"]) || !is_array($json["counts"])) {
        $json["counts"] = array();
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

    $start = strtotime($date . " 00:00:00");
    $end   = strtotime($date . " 23:59:59");

    $items = array();

    foreach ($obj->channel->item as $item) {

        $pub = strtotime((string)$item->pubDate);
        if ($pub < $start || $pub > $end) continue;

        $items[] = array(
            "title"   => trim((string)$item->title),
            "link"    => trim((string)$item->link),
            "pubDate" => trim((string)$item->pubDate)
        );

        if (count($items) >= NEWS_LIMIT) break;
    }

    return $items;
}

/* =========================================================
   LOAD KEYWORDS
========================================================= */
$data = load_keyword_json_safe();

$keywords = $data["keywords"];
$created  = $data["created"];

$keyword_set = array();
foreach ($keywords as $k) {
    $keyword_set[$k] = true;
}

/* =========================================================
   POST : SEED KEYWORD
========================================================= */
$today = date("Y-m-d");

$base_date = $today;
if (isset($_GET["base_date"]) && preg_match("/^\d{4}-\d{2}-\d{2}$/", $_GET["base_date"])) {
    $base_date = $_GET["base_date"];
}

if (isset($_POST["seed_keyword"])) {

    $base = trim($_POST["seed_keyword"]);
    if ($base !== "") {
        seed_keyword_core($base);
    }

    header("Location: ?kw=" . urlencode($base));
    exit;
}

/* =========================================================
   VIEW KEYWORD (GET)
========================================================= */
$view_keyword = "";
if (isset($_GET["kw"])) {
    $view_keyword = trim($_GET["kw"]);
}

/* =========================================================
   JSON GENERATION
========================================================= */

$results = array();


foreach ($keywords as $kw) {

    if ($view_keyword !== "" && $kw !== $view_keyword) continue;

    $results[$kw] = array();

    for ($i = 0; $i < 10; $i++) {

        $d = date("Y-m-d", strtotime($base_date . " -".$i." day"));

        $json_file = DATA_DIR . "/" . $d . "_" . $kw . ".json";

        if (file_exists($json_file)) {
            $results[$kw][] = $json_file;
        }
    }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<style>
body{
    background:#020617;
    color:#e5e7eb;
    font-family:sans-serif;
    padding:16px
}
.keyword{
    margin-bottom:40px
}
.scroll{
    display:flex;
    gap:12px;
    overflow-x:auto;
    scroll-snap-type:x mandatory;
    flex-wrap:nowrap;
}

.card{
    width:420px;
    flex:0 0 420px;
    background:#111827;
    padding:14px;
    border-radius:12px;
    scroll-snap-align:start;
}

@media(max-width:600px){
    .card{
        min-width:100%;
    }
}
textarea{
    width:100%;
    height:220px;
    background:#020617;
    color:#e5e7eb;
    border:1px solid #334155;
    border-radius:10px;
    padding:10px
}
.title{
    font-weight:700
}
.muted{
    font-size:13px;
    color:#94a3b8
}
a{
    color:#38bdf8
}

/* Thinking Overlay */
#thinking-overlay{
    position:fixed;
    inset:0;
    background:rgba(2,6,23,0.85);
    display:none;
    align-items:center;
    justify-content:center;
    z-index:9999
}
#thinking-box{
    background:#111827;
    border:1px solid #334155;
    border-radius:14px;
    padding:24px 32px;
    text-align:center
}
#thinking-title{
    font-size:18px;
    font-weight:700;
    margin-bottom:8px
}
#thinking-sub{
    font-size:13px;
    color:#94a3b8
}
</style>

</head>
<body>

<a href="./aiknowledgecms.php"><img src="./images/aiknowledgecms_logo.png" width="30%"></a><br><br>

<div id="thinking-overlay">
    <div id="thinking-box">
        <div id="thinking-title">Thinking…</div>
        <div id="thinking-sub">AI が考えています。しばらくお待ちください。</div>
    </div>
</div>

<h2>AI Thinking Media</h2>

<form method="post" onsubmit="showThinking()">
    <input
        type="text"
        name="seed_keyword"
        placeholder="キーワードを入力"
        style="width:260px"
    >
    <button type="submit">生成</button>
</form>

<hr>
<?php
$prev_date = date("Y-m-d", strtotime($base_date . " -1 day"));
$next_date = date("Y-m-d", strtotime($base_date . " +1 day"));
$can_next  = ($next_date <= $today);
?>

<div style="display:flex;gap:12px;align-items:center;margin-bottom:16px">
    <a href="?base_date=<?php echo h($prev_date); ?><?php if($view_keyword) echo "&kw=".h($view_keyword); ?>">←</a>
    <strong><?php echo h($base_date); ?></strong>
    <?php if ($can_next): ?>
        <a href="?base_date=<?php echo h($next_date); ?><?php if($view_keyword) echo "&kw=".h($view_keyword); ?>">→</a>
    <?php else: ?>
        <span style="opacity:.3">→</span>
    <?php endif; ?>
</div>


<?php foreach ($results as $keyword => $files): ?>
<?php if (empty($files)) continue; ?>

<div class="keyword">

<h3>
  <a href="?kw=<?php echo h($keyword); ?>&base_date=<?php echo h($base_date); ?>">
    <?php echo h($keyword); ?>
  </a>
</h3>

<div class="scroll">

<?php foreach ($files as $file): ?>
<?php $data = json_decode(file_get_contents($file), true); ?>

<div class="card">

<textarea readonly><?php
echo h(isset($data["analysis"]) ? $data["analysis"] : "");
?></textarea>

<?php foreach ($data["news"] as $n): ?>
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

<script>
function showThinking(){

    var overlay = document.getElementById("thinking-overlay");
    if(overlay){
        overlay.style.display = "flex";
    }

    // ★ submit を邪魔しないように、無効化は次のイベントループで行う
    setTimeout(function(){
        var els = document.querySelectorAll("input, button, textarea");
        els.forEach(function(e){
            e.disabled = true;
        });
    }, 0);

    return true;
}
</script>

</body>
</html>

