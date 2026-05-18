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
function get_live_trend_keywords($limit = 50){

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

    $data = get_live_trend_keywords(50);

    echo json_encode(array_keys($data["counts"]));
    exit;
}

/* =========================
   表示用データ取得
========================= */
$data = get_live_trend_keywords(50);
$top  = $data["counts"];
$descriptions = $data["descriptions"];
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>AI Trend Intelligence</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="AIトレンドキーワード辞典。AIニュースや検索流入データを元に、今注目されているAI関連キーワードをリアルタイム集計・可視化します。">
<meta name="robots" content="index,follow">
<link rel="canonical" href="https://aiknowledgecms.exbridge.jp/aitrend.php">

<!-- Open Graph -->
<meta property="og:type" content="website">
<meta property="og:title" content="AIトレンドキーワード辞典 | AIKnowledgeCMS">
<meta property="og:description" content="リアルタイムAIトレンドキーワードを自動集計。検索流入ベースの実データ辞典。">
<meta property="og:url" content="https://aiknowledgecms.exbridge.jp/aitrend.php">
<meta property="og:image" content="https://aiknowledgecms.exbridge.jp/images/aitrend_logo.png">

<!-- Twitter Card -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="AIトレンドキーワード辞典">
<meta name="twitter:description" content="AIトレンドをリアルタイム可視化">
<meta name="twitter:image" content="https://aiknowledgecms.exbridge.jp/images/aitrend_logo.png">


<style>
body{
    margin:0;
    background:#f1f5f9;
    color:#1e293b;
    font-family: "Inter", sans-serif;
    padding:50px;
}
h1{
    font-size:24px;
    font-weight:700;
    margin-bottom:30px;
    color:#6d28d9;
}
.card{
    background:#ffffff;
    border:1px solid #e2e8f0;
    border-radius:10px;
    padding:22px;
    transition:all 0.2s ease;
    position:relative;
    overflow:hidden;
    box-shadow:0 1px 3px rgba(0,0,0,.06);
}
.card:hover{
    transform:translateY(-3px);
    border-color:#7c3aed;
    box-shadow:0 8px 20px rgba(124,58,237,.1);
}
.keyword{
    font-size:16px;
    font-weight:600;
    color:#1e293b;
}
.count{
    position:absolute;
    top:18px;
    right:22px;
    font-size:12px;
    padding:4px 10px;
    border-radius:999px;
    background:#7c3aed;
    color:#fff;
    font-weight:600;
}
.desc{
    margin-top:10px;
    font-size:13px;
    line-height:1.6;
    color:#64748b;
}
.empty{ opacity:0.5; }
.trend-container{
    display:grid;
    grid-template-columns: repeat(3, 1fr);
    gap:16px;
}
@media (max-width: 1024px){
    body{ padding:20px; }
    .trend-container{ grid-template-columns: 1fr; }
}
</style>
<script type="application/ld+json">
{
 "@context": "https://schema.org",
 "@type": "ItemList",
 "name": "AIトレンドキーワード一覧",
 "itemListElement": [
<?php
$i=1;
foreach($top as $kw => $cnt){
    echo '{
      "@type": "ListItem",
      "position": '.$i.',
      "name": "'.htmlspecialchars($kw).'"
    },';
    $i++;
}
?>
 ]
}
</script>
</head>
<body>
<style>
.header-bar{
  display:flex;align-items:center;justify-content:space-between;
  flex-wrap:wrap;gap:12px;background:#fff;border:1px solid #e2e8f0;
  border-radius:8px;padding:12px 16px;margin-bottom:20px;
  box-shadow:0 1px 3px rgba(0,0,0,.06);
}
.cms-logo img{ width:160px;height:auto; }
.aitrend-link{ display:flex;align-items:center;gap:8px;text-decoration:none; }
.aitrend-link img{ width:32px;height:auto; }
.aitrend-text{ font-size:14px;font-weight:600;color:#64748b; }
.aitrend-link:hover .aitrend-text{ color:#6d28d9; }
@media (max-width:600px){
  .header-bar{ flex-direction:column;align-items:flex-start; }
  .cms-logo img{ width:140px; }
}
</style>

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
<h1>🚀 AIトレンドキーワード辞典 ｜ 最新AIニュース・検索流入分析</h1>
<p>
AIトレンドキーワード辞典は、実際の検索流入データとアクセスログを元に、
今リアルタイムで注目されているAI関連キーワードを可視化するページです。
AIニュース・生成AI・LLM・最新技術トピックを横断的に把握できます。
</p>

<div class="trend-container">
<?php
if(empty($top)){
    echo "<div class='empty'>No trend data yet.</div>";
}else{
    foreach($top as $kw => $cnt){

        $desc = isset($descriptions[$kw]) ? $descriptions[$kw] : "";

        echo '<div class="card">';
        echo '<div class="keyword"><a href="./aiknowledgecms.php?kw='.urlencode($kw).'" style="text-decoration:none;color:inherit;">'.htmlspecialchars($kw).'</a></div>';
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

