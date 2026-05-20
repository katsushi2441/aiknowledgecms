<?php
date_default_timezone_set("Asia/Tokyo");

$logfile = __DIR__ . "/access.log";

function st_is_bot_ua($ua){
    $ua = strtolower(trim((string)$ua));
    if($ua === "") return true;
    $bot_words = array(
        "bot", "crawler", "spider", "slurp", "crawl", "mediapartners",
        "curl", "wget", "python", "httpclient", "scrapy", "headless",
        "phantom", "selenium", "playwright", "puppeteer",
        "facebookexternalhit", "meta-externalagent", "twitterbot", "slackbot", "discordbot",
        "linebot", "googlebot", "googleother", "google-read-aloud", "bingbot", "duckduckbot", "baiduspider",
        "yandexbot", "ahrefsbot", "semrushbot", "mj12bot", "petalbot",
        "bytespider", "claudebot", "gptbot", "oai-searchbot", "ccbot", "perplexitybot",
        "applebot", "amazonbot"
    );
    foreach($bot_words as $word){
        if(strpos($ua, $word) !== false) return true;
    }
    return false;
}

/* =========================
   ダッシュボードモード
========================= */
if(isset($_GET["dashboard"])){

    clearstatcache();   // ← ここに追加
    if(!function_exists("h")){
        function h($value){
            return htmlspecialchars((string)$value, ENT_QUOTES, "UTF-8");
        }
    }

    if(!file_exists($logfile)){
        die("log not found");
    }

    $range = isset($_GET["range"]) ? $_GET["range"] : "all";
    $range_days = array("1d" => 1, "7d" => 7, "30d" => 30, "90d" => 90);
    if(!isset($range_days[$range]) && $range !== "all"){
        $range = "all";
    }
    $range_start_ts = null;
    if($range !== "all"){
        $range_start_ts = ($range === "1d") ? strtotime("-24 hours") : strtotime("-" . ($range_days[$range] - 1) . " days 00:00:00");
    }
    $range_labels = array(
        "1d" => "直近24時間",
        "7d" => "直近1週間",
        "30d" => "直近30日",
        "90d" => "直近3か月",
        "all" => "すべて",
    );

    $lines = file($logfile);


    $pv_per_day = array();
    $url_count = array();
    $ref_count = array();
    $php_page_count = array();

    foreach($lines as $line){

        $parts = explode(" | ", trim($line));
        if(count($parts) < 5) continue;

        $date = substr($parts[0],0,10);
        $url  = $parts[2];
        $ref  = $parts[3];
        $ua   = isset($parts[4]) ? $parts[4] : "";
        if(st_is_bot_ua($ua)){
            continue;
        }
        $ts = strtotime($parts[0]);
        if($range_start_ts !== null && (!$ts || $ts < $range_start_ts)){
            continue;
        }

        if(!isset($pv_per_day[$date])) $pv_per_day[$date] = 0;
        $pv_per_day[$date]++;

        if($url !== ""){
            if(!isset($url_count[$url])) $url_count[$url] = 0;
            $url_count[$url]++;

            $parsed = parse_url($url);
            $host = isset($parsed["host"]) ? $parsed["host"] : "";
            $path = isset($parsed["path"]) ? $parsed["path"] : "";
            if($host === "aiknowledgecms.exbridge.jp" && preg_match('/\.php$/', $path)){
                $page = "https://" . $host . $path;
                if(strpos($page, "admin") === false){
                    if(!isset($php_page_count[$page])) $php_page_count[$page] = 0;
                    $php_page_count[$page]++;
                }
            }
        }

        if($ref !== ""){
            if(!isset($ref_count[$ref])) $ref_count[$ref] = 0;
            $ref_count[$ref]++;
        }
    }

    ksort($pv_per_day);
    arsort($url_count);
    arsort($ref_count);
    arsort($php_page_count);

    $filtered_urls = array();
    foreach($url_count as $u => $c){

        if(strpos($u, "admin") !== false){
            continue;
        }

        $filtered_urls[$u] = $c;
    }

    $filtered_refs = array();
    foreach($ref_count as $r => $c){

        $allow = true;

        if(strpos($r, "admin") !== false){
            $allow = false;
        }

        if($allow && strpos($r, "exbridge.jp") !== false){
            if(strpos($r, "kw=") === false){
                $allow = false;
            }
        }

        if($allow){
            $filtered_refs[$r] = $c;
        }
    }

    $top_urls = array_slice($filtered_urls, 0, 20, true);
    $top_refs = array_slice($filtered_refs, 0, 20, true);
    $top_php_pages = array_slice($php_page_count, 0, 30, true);

    $all_urls_array = array();
    foreach($filtered_urls as $u => $c){
        $all_urls_array[] = array(
            "url" => urldecode($u),
            "pv"  => $c
        );
    }
    $all_urls = json_encode($all_urls_array, JSON_UNESCAPED_UNICODE);

    $php_pages_array = array();
    foreach($php_page_count as $u => $c){
        $php_pages_array[] = array(
            "url" => urldecode($u),
            "pv"  => $c
        );
    }
    $php_pages = json_encode($php_pages_array, JSON_UNESCAPED_UNICODE);

    $dates      = json_encode(array_keys($pv_per_day));
    $pv_counts  = json_encode(array_values($pv_per_day));

    $url_labels = json_encode(array_map('urldecode', array_keys($top_urls)), JSON_UNESCAPED_UNICODE);
    $url_counts = json_encode(array_values($top_urls));

    $ref_labels = json_encode(array_map('urldecode', array_keys($top_refs)), JSON_UNESCAPED_UNICODE);
    $ref_counts = json_encode(array_values($top_refs));

    $php_labels = json_encode(array_map('urldecode', array_keys($top_php_pages)), JSON_UNESCAPED_UNICODE);
    $php_counts = json_encode(array_values($top_php_pages));
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>AI Web Analytics</title>
<link rel="stylesheet" href="./style.css">
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<style>
body{
    font-family:Arial;
    background:#0f172a;
    color:#fff;
    padding:40px;
}
h1{margin-bottom:40px;}
.range-nav{display:flex;gap:8px;flex-wrap:wrap;margin:-20px 0 28px}
.range-nav a{display:inline-flex;align-items:center;justify-content:center;min-height:34px;padding:6px 12px;border:1px solid #334155;border-radius:999px;color:#cbd5e1;text-decoration:none;background:#111827;font-size:13px;font-weight:700}
.range-nav a.active{background:#38bdf8;color:#082f49;border-color:#38bdf8}
.range-note{color:#cbd5e1;font-size:13px;margin:-16px 0 28px}
.canvasBox{
    background:#1e293b;
    padding:20px;
    border-radius:12px;
    margin-bottom:50px;
}
table{
    width:100%;
    border-collapse:collapse;
}
th,td{
    border:1px solid #334155;
    padding:6px;
    font-size:13px;
    word-break:break-all;
}
th{
    background:#111827;
}
canvas{
    background:#111827;
    padding:10px;
    border-radius:8px;
}
</style>
</head>
<body>
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
</div>

<h1>AI Web Analytics Dashboard</h1>
<div class="range-nav">
  <?php foreach($range_labels as $key => $label): ?>
    <a class="<?php echo $range === $key ? 'active' : ''; ?>" href="./simpletrack.php?dashboard=1&range=<?php echo h($key); ?>"><?php echo h($label); ?></a>
  <?php endforeach; ?>
</div>
<div class="range-note">表示期間: <?php echo h($range_labels[$range]); ?></div>

<div class="canvasBox">
<h2>? Daily PV</h2>
<canvas id="pvChart"></canvas>
</div>

<div class="canvasBox">
<h2>? Top URLs (アクセスされたページ)</h2>
<canvas id="urlChart"></canvas>
</div>

<div class="canvasBox">
<h2>? PHP Pages (URL2AIページ別PV)</h2>
<canvas id="phpPageChart"></canvas>
</div>

<div class="canvasBox">
<h2>? PHPページ 詳細リスト</h2>
<table>
<thead>
<tr><th>#</th><th>PHP Page</th><th>PV</th></tr>
</thead>
<tbody id="phpPageBody"></tbody>
</table>
</div>

<div class="canvasBox">
<h2>? Top Referrers (流入元フルURL)</h2>
<canvas id="refChart"></canvas>
</div>

<div class="canvasBox">
<h2>? アクセスページ 詳細リスト</h2>
<table>
<thead>
<tr><th>#</th><th>URL</th><th>PV</th></tr>
</thead>
<tbody id="detailBody"></tbody>
</table>
</div>

<script>
const allData = <?php echo $all_urls; ?>;
const phpPageData = <?php echo $php_pages; ?>;

let rendered = 0;
const tbody = document.getElementById("detailBody");
const phpPageBody = document.getElementById("phpPageBody");

phpPageData.forEach(function(item, i){
    const tr = document.createElement("tr");
    tr.innerHTML =
        "<td>"+(i+1)+"</td>" +
        "<td>"+item.url+"</td>" +
        "<td>"+item.pv+"</td>";
    phpPageBody.appendChild(tr);
});

function renderRows(){

    const next = Math.min(rendered + 50, allData.length);

    for(let i = rendered; i < next; i++){

        const tr = document.createElement("tr");

        tr.innerHTML =
            "<td>"+(i+1)+"</td>" +
            "<td>"+allData[i].url+"</td>" +
            "<td>"+allData[i].pv+"</td>";

        tbody.appendChild(tr);
    }

    rendered = next;
}

renderRows();

window.addEventListener("scroll", function(){

    if(
        window.innerHeight + window.scrollY >=
        document.body.offsetHeight - 200
    ){
        if(rendered < allData.length){
            renderRows();
        }
    }
});

Chart.defaults.color = '#ffffff';
Chart.defaults.borderColor = '#334155';

new Chart(document.getElementById('pvChart'),{
    type:'line',
    data:{
        labels: <?php echo $dates; ?>,
        datasets:[{
            label:'Daily PV',
            data: <?php echo $pv_counts; ?>,
            borderColor:'#38bdf8',
            backgroundColor:'rgba(56,189,248,0.2)',
            tension:0.3,
            fill:true
        }]
    },
    options:{responsive:true,scales:{y:{beginAtZero:true}}}
});

new Chart(document.getElementById('urlChart'),{
    type:'bar',
    data:{
        labels: <?php echo $url_labels; ?>,
        datasets:[{
            label:'PV',
            data: <?php echo $url_counts; ?>,
            backgroundColor:'#a78bfa'
        }]
    },
    options:{indexAxis:'y',responsive:true,scales:{x:{beginAtZero:true}}}
});

new Chart(document.getElementById('phpPageChart'),{
    type:'bar',
    data:{
        labels: <?php echo $php_labels; ?>,
        datasets:[{
            label:'PV',
            data: <?php echo $php_counts; ?>,
            backgroundColor:'#34d399'
        }]
    },
    options:{indexAxis:'y',responsive:true,scales:{x:{beginAtZero:true}}}
});

new Chart(document.getElementById('refChart'),{
    type:'bar',
    data:{
        labels: <?php echo $ref_labels; ?>,
        datasets:[{
            label:'流入数',
            data: <?php echo $ref_counts; ?>,
            backgroundColor:'#f59e0b'
        }]
    },
    options:{indexAxis:'y',responsive:true,scales:{x:{beginAtZero:true}}}
});
</script>

</body>
</html>
<?php
exit;
}

/* =========================
   通常トラッキングモード
========================= */

// ---- 1. URLの取得 ----
if(isset($_GET["url"]) && $_GET["url"] !== ""){
    $url = filter_var($_GET["url"], FILTER_SANITIZE_URL);
    if(!preg_match('#^https?://#i', $url)){
        $url = "";
    }
} else {
    $url = isset($_SERVER["HTTP_HOST"])
        ? "https://" . $_SERVER["HTTP_HOST"] . strtok($_SERVER["REQUEST_URI"], "?")
        : "";
}

// ---- 2. リファラーの取得 ----
if(isset($_GET["ref"]) && $_GET["ref"] !== ""){
    $ref = filter_var($_GET["ref"], FILTER_SANITIZE_URL);
    if(!preg_match('#^https?://#i', $ref)){
        $ref = "";
    }
} else {
    $ref = isset($_SERVER["HTTP_REFERER"]) ? $_SERVER["HTTP_REFERER"] : "";
}

// ---- 3. IP・UA ----
$ip = isset($_SERVER["REMOTE_ADDR"]) ? $_SERVER["REMOTE_ADDR"] : "";

function sanitize_field($value){
    return str_replace(array("|", "\n", "\r"), array("", "", ""), trim($value));
}

$ua  = sanitize_field(isset($_SERVER["HTTP_USER_AGENT"]) ? $_SERVER["HTTP_USER_AGENT"] : "");
$ref = sanitize_field($ref);
$url = sanitize_field($url);

if(st_is_bot_ua($ua)){
    header("Content-Type: application/javascript");
    echo "// ignored";
    exit;
}

// ---- 4. ログ書き込み ----
$line = date("Y-m-d H:i:s") . " | "
      . $ip  . " | "
      . $url . " | "
      . $ref . " | "
      . $ua  . "\n";

file_put_contents($logfile, $line, FILE_APPEND | LOCK_EX);

// ---- 5. レスポンス ----
header("Content-Type: application/javascript");
echo "// tracked";
exit;
