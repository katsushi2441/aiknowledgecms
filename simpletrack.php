<?php
date_default_timezone_set("Asia/Tokyo");

$logfile = __DIR__ . "/access.log";

/* =========================
   ダッシュボードモード
========================= */
if(isset($_GET["dashboard"])){

    if(!file_exists($logfile)){
        die("log not found");
    }

    $lines = file($logfile);

    $pv_per_day = array();
    $url_count  = array();
    $ref_count  = array();

    foreach($lines as $line){

        $parts = explode(" | ", trim($line));
        if(count($parts) < 5) continue;

        $date = substr($parts[0],0,10);
        $url  = $parts[2];
        $ref  = $parts[3];

        if(!isset($pv_per_day[$date])) $pv_per_day[$date] = 0;
        $pv_per_day[$date]++;

        // Top URLs: 実際に見られたページ
        if($url !== ""){
            if(!isset($url_count[$url])) $url_count[$url] = 0;
            $url_count[$url]++;
        }

        // Top Referrers: どこから来たか（フルURL・パラメータ含む）
        if($ref !== ""){
            if(!isset($ref_count[$ref])) $ref_count[$ref] = 0;
            $ref_count[$ref]++;
        }
    }

    ksort($pv_per_day);
    arsort($url_count);
    arsort($ref_count);

    $decoded_urls = array();
    foreach(array_slice($url_count,0,10,true) as $u=>$c){
        $decoded_urls[urldecode($u)] = $c;
    }

    $decoded_refs = array();
    foreach(array_slice($ref_count,0,10,true) as $r=>$c){
        $decoded_refs[$r] = $c;
    }

    $dates      = json_encode(array_keys($pv_per_day));
    $pv_counts  = json_encode(array_values($pv_per_day));

    $url_labels = json_encode(array_keys($decoded_urls), JSON_UNESCAPED_UNICODE);
    $url_counts = json_encode(array_values($decoded_urls));

    $ref_labels = json_encode(array_keys($decoded_refs), JSON_UNESCAPED_UNICODE);
    $ref_counts = json_encode(array_values($decoded_refs));
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>AIWeb Analytics</title>
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

<h1>AIWeb Analytics Dashboard</h1>

<div class="canvasBox">
<h2>📊 Daily PV</h2>
<canvas id="pvChart"></canvas>
</div>

<div class="canvasBox">
<h2>🔥 Top URLs (アクセスされたページ)</h2>
<canvas id="urlChart"></canvas>
</div>

<div class="canvasBox">
<h2>🌍 Top Referrers (流入元フルURL)</h2>
<canvas id="refChart"></canvas>
</div>

<div class="canvasBox">
<h2>📋 アクセスページ 詳細リスト</h2>
<table>
<tr><th>#</th><th>URL</th><th>PV</th></tr>
<?php
$i = 1;
foreach(array_slice($decoded_urls,0,50,true) as $u=>$c){
    echo "<tr><td>".$i."</td><td>".htmlspecialchars($u)."</td><td>".$c."</td></tr>";
    $i++;
}
?>
</table>
</div>

<script>
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
// ?url= で実際のページURLを受け取る（埋め込み側JSから渡す）
if(isset($_GET["url"]) && $_GET["url"] !== ""){
    $url = filter_var($_GET["url"], FILTER_SANITIZE_URL);
    if(!preg_match('#^https?://#i', $url)){
        $url = "";
    }
} else {
    // 同一ドメイン利用時のフォールバック
    $url = isset($_SERVER["HTTP_HOST"])
        ? "https://" . $_SERVER["HTTP_HOST"] . strtok($_SERVER["REQUEST_URI"], "?")
        : "";
}

// ---- 2. リファラーの取得 ----
// ?ref= で document.referrer（どこから来たか）を受け取る
// 渡されていない場合は HTTP_REFERER にフォールバック
if(isset($_GET["ref"]) && $_GET["ref"] !== ""){
    $ref = filter_var($_GET["ref"], FILTER_SANITIZE_URL);
    if(!preg_match('#^https?://#i', $ref)){
        $ref = "";
    }
} else {
    $ref = isset($_SERVER["HTTP_REFERER"]) ? $_SERVER["HTTP_REFERER"] : "";
}

// ---- 3. IP・UAの取得とサニタイズ ----
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
