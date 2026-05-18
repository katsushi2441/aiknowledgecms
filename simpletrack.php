<?php
date_default_timezone_set("Asia/Tokyo");

$logfile = __DIR__ . "/access.log";

/* =========================
   ダッシュボードモード
========================= */
if(isset($_GET["dashboard"])){

    clearstatcache();   // ← ここに追加

    if(!file_exists($logfile)){
        die("log not found");
    }

    $lines = file($logfile);


    foreach($lines as $line){

        $parts = explode(" | ", trim($line));
        if(count($parts) < 5) continue;

        $date = substr($parts[0],0,10);
        $url  = $parts[2];
        $ref  = $parts[3];

        if(!isset($pv_per_day[$date])) $pv_per_day[$date] = 0;
        $pv_per_day[$date]++;

        if($url !== ""){
            if(!isset($url_count[$url])) $url_count[$url] = 0;
            $url_count[$url]++;
        }

        if($ref !== ""){
            if(!isset($ref_count[$ref])) $ref_count[$ref] = 0;
            $ref_count[$ref]++;
        }
    }

    ksort($pv_per_day);
    arsort($url_count);
    arsort($ref_count);

    $filtered_urls = array();
    foreach($url_count as $u => $c){

        if(strpos($u, "kw=") === false){
            continue;
        }

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

    $all_urls_array = array();
    foreach($filtered_urls as $u => $c){
        $all_urls_array[] = array(
            "url" => urldecode($u),
            "pv"  => $c
        );
    }
    $all_urls = json_encode($all_urls_array, JSON_UNESCAPED_UNICODE);

    $dates      = json_encode(array_keys($pv_per_day));
    $pv_counts  = json_encode(array_values($pv_per_day));

    $url_labels = json_encode(array_map('urldecode', array_keys($top_urls)), JSON_UNESCAPED_UNICODE);
    $url_counts = json_encode(array_values($top_urls));

    $ref_labels = json_encode(array_map('urldecode', array_keys($top_refs)), JSON_UNESCAPED_UNICODE);
    $ref_counts = json_encode(array_values($top_refs));
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

<div class="canvasBox">
<h2>? Daily PV</h2>
<canvas id="pvChart"></canvas>
</div>

<div class="canvasBox">
<h2>? Top URLs (アクセスされたページ)</h2>
<canvas id="urlChart"></canvas>
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

let rendered = 0;
const tbody = document.getElementById("detailBody");

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
$ip = $_SERVER["REMOTE_ADDR"];

function sanitize_field($value){
    return str_replace(array("|", "\n", "\r"), array("", "", ""), trim($value));
}

$ua  = sanitize_field(isset($_SERVER["HTTP_USER_AGENT"]) ? $_SERVER["HTTP_USER_AGENT"] : "");
$ref = sanitize_field($ref);
$url = sanitize_field($url);

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
