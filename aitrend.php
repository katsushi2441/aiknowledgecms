<?php
date_default_timezone_set("Asia/Tokyo");

/* =========================
   設定
========================= */
$log_file = __DIR__ . "/access.log";
$keyword_file = __DIR__ . "/keyword.json";

/* =========================
   共通：Liveトレンド取得関数
========================= */
function get_live_trend_keywords($limit = 20){

    global $log_file, $keyword_file;

    $valid_keywords = array();
    $descriptions   = array();

    if(file_exists($keyword_file)){
        $json = json_decode(file_get_contents($keyword_file), true);

        if(isset($json["keywords"]) && is_array($json["keywords"])){
            foreach($json["keywords"] as $k => $v){
                $valid_keywords[$k] = true;
                $descriptions[$k]  = isset($v["description"]) ? $v["description"] : "";
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

    $top = array_slice($counts, 0, $limit, true);

    return array(
        "counts"       => $top,
        "descriptions" => $descriptions
    );
}

/* =========================
   API: 共通トレンド取得
========================= */
if(isset($_GET["api_get_trend_keywords"])){
    header("Content-Type: application/json; charset=UTF-8");

    $data = get_live_trend_keywords(20);

    echo json_encode(array_keys($data["counts"]));
    exit;
}

/* =========================
   表示用データ取得
========================= */
$data = get_live_trend_keywords(20);
$top  = $data["counts"];
$descriptions = $data["descriptions"];
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>AI Trend Intelligence</title>

<style>
body{
    margin:0;
    background:radial-gradient(circle at top left,#0f172a,#020617);
    color:#e2e8f0;
    font-family: "Inter", sans-serif;
    padding:50px;
}

h1{
    font-size:28px;
    font-weight:600;
    margin-bottom:30px;
    background:linear-gradient(90deg,#38bdf8,#818cf8,#f472b6);
    -webkit-background-clip:text;
    -webkit-text-fill-color:transparent;
}


.card{
    background:rgba(15,23,42,0.7);
    border:1px solid rgba(148,163,184,0.15);
    border-radius:18px;
    padding:22px;
    backdrop-filter:blur(12px);
    transition:all 0.25s ease;
    position:relative;
    overflow:hidden;
}

.card:hover{
    transform:translateY(-4px);
    border-color:#38bdf8;
    box-shadow:0 10px 25px rgba(56,189,248,0.15);
}

.keyword{
    font-size:18px;
    font-weight:600;
}

.count{
    position:absolute;
    top:18px;
    right:22px;
    font-size:13px;
    padding:6px 10px;
    border-radius:999px;
    background:linear-gradient(90deg,#38bdf8,#818cf8);
    color:#020617;
    font-weight:600;
}

.desc{
    margin-top:12px;
    font-size:13px;
    line-height:1.5em;
    opacity:0.8;
}

.empty{
    opacity:0.6;
}
.trend-container{
    display:grid;
    grid-template-columns: repeat(3, 1fr);
    gap:20px;
}

@media (max-width: 1024px){
    body{
        padding:20px;
    }

    .trend-container{
        grid-template-columns: 1fr;
    }
}

</style>
</head>
<body>
<style>
.header-bar{
  display:flex;
  align-items:center;
  justify-content:space-between;
  flex-wrap:wrap;
  gap:12px;
}

.cms-logo img{
  width:160px;
  height:auto;
}

.aitrend-link{
  display:flex;
  align-items:center;
  gap:8px;
  text-decoration:none;
}

.aitrend-link img{
  width:32px;
  height:auto;
}

.aitrend-text{
  font-size:16px;
  font-weight:700;
  letter-spacing:0.5px;
  background:linear-gradient(90deg,#38bdf8,#22c55e);
  -webkit-background-clip:text;
  -webkit-text-fill-color:transparent;
}

/* ===== スマホ最適化 ===== */
@media (max-width:600px){

  .header-bar{
    flex-direction:column;
    align-items:flex-start;
  }

  .cms-logo img{
    width:140px;
  }

  .aitrend-text{
    font-size:15px;
  }

}
</style>

<div class="header-bar">

  <a href="./aiknowledgecms.php" class="cms-logo">
    <img src="./images/aiknowledgecms_logo.png">
  </a>

  <a href="./aitrend.php" class="aitrend-link">
    <img src="./images/aitrend_logo.png">
    <span class="aitrend-text">AIトレンドキーワード</span>
  </a>

</div>
<h1>🚀 Live AI Trend Intelligence</h1>

<div class="trend-container">
<?php
if(empty($top)){
    echo "<div class='empty'>No trend data yet.</div>";
}else{
    foreach($top as $kw => $cnt){

        $desc = isset($descriptions[$kw]) ? $descriptions[$kw] : "";

        echo '<div class="card">';
        echo '<div class="keyword">'.htmlspecialchars($kw).'</div>';
        echo '<div class="count">+'.$cnt.'</div>';

        if($desc !== ""){
            echo '<div class="desc">'.htmlspecialchars($desc).'</div>';
        }

        echo '</div>';
    }
}
?>
</div>

</body>
</html>

