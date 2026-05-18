<?php
date_default_timezone_set("Asia/Tokyo");

function h($s){
    return htmlspecialchars($s, ENT_QUOTES, "UTF-8");
}

$keyword_file = __DIR__ . "/keyword.json";
$keywords_by_date = array();

if (file_exists($keyword_file)) {

    $json = json_decode(file_get_contents($keyword_file), true);

    if (isset($json["keywords"]) && is_array($json["keywords"])) {

        foreach ($json["keywords"] as $kw => $v) {

            if (!isset($v["created"])) continue;

            $date = $v["created"];

            if (!preg_match("/^\d{4}-\d{2}-\d{2}$/", $date)) continue;

            $ym   = date("Ym", strtotime($date));
            $file = __DIR__ . "/data/" . $ym . "/" . $date . "_" . $kw . ".json";
            if (!file_exists($file)) {
                $file = __DIR__ . "/data/" . $date . "_" . $kw . ".json";
            }

            if (!file_exists($file)) continue;

            $data = json_decode(file_get_contents($file), true);

            if (
                !isset($data["news"]) ||
                !is_array($data["news"]) ||
                count($data["news"]) === 0
            ) continue;

            if (!isset($keywords_by_date[$date])) {
                $keywords_by_date[$date] = array();
            }

            $keywords_by_date[$date][] = $kw;
        }

    }
}

krsort($keywords_by_date);
$dates = array_keys($keywords_by_date);

/* =========================
   AJAX処理
========================= */
if(isset($_GET["ajax"])){

    $offset = isset($_GET["offset"]) ? intval($_GET["offset"]) : 0;
    $limit  = 10;

    $slice = array_slice($dates, $offset, $limit);

    if (count($slice) === 0) {
        echo "END";
        exit;
    }

    foreach ($slice as $date){

        echo '<div class="date-block">';
        echo '<div class="date-title">'.h($date).'</div>';
        echo '<div class="keyword-list">';

        foreach ($keywords_by_date[$date] as $kw){

            echo '<a href="./aiknowledgecms.php?kw='.urlencode($kw).'">';
            echo h($kw);
            echo '</a>';
        }

        echo '</div>';
        echo '</div>';
    }

    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title>AIキーワード登録履歴｜日別トレンド一覧 | AIKnowledgeCMS</title>
<meta name="description" content="AIKnowledgeCMSに登録されたキーワードを日別で一覧表示。生成AI・LLM・AI企業動向などのトレンド履歴を時系列で確認できます。">
<meta name="robots" content="index,follow">
<link rel="canonical" href="https://aiknowledgecms.exbridge.jp/new_keywords.php">

<meta property="og:type" content="website">
<meta property="og:title" content="AIキーワード登録履歴｜日別トレンド一覧">
<meta property="og:description" content="AIキーワードの時系列履歴ページ">
<meta property="og:url" content="https://aiknowledgecms.exbridge.jp/new_keywords.php">
<meta property="og:image" content="https://aiknowledgecms.exbridge.jp/images/aiknowledgecms_logo.png">

<meta name="twitter:card" content="summary_large_image">



<style>
body{ background:#f1f5f9;color:#1e293b;font-family:'Inter',sans-serif;padding:24px 20px; }
h1{ color:#1e293b;font-size:22px;font-weight:700;margin-bottom:8px; }
p{ color:#64748b;margin-bottom:20px; }
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
.date-block{
    margin-bottom:28px;
    background:#fff;border:1px solid #e2e8f0;border-radius:8px;
    padding:16px 18px;box-shadow:0 1px 3px rgba(0,0,0,.04);
}
.date-title{ font-size:15px;font-weight:600;margin-bottom:12px;color:#334155; }
.keyword-list{ display:flex;flex-wrap:wrap;gap:8px; }
.keyword-list a{
    padding:5px 12px;border-radius:999px;
    background:#f8fafc;border:1px solid #e2e8f0;
    color:#475569;text-decoration:none;transition:all .2s ease;font-size:12px;
}
.keyword-list a:hover{ background:rgba(124,58,237,.08);color:#6d28d9;border-color:#7c3aed; }
#loading{ text-align:center;color:#94a3b8;margin:30px 0;font-size:13px; }
@media (max-width:600px){
  .header-bar{ flex-direction:column;align-items:flex-start; }
}
</style>

<script type="application/ld+json">
{
 "@context": "https://schema.org",
 "@type": "ItemList",
 "name": "日別AIキーワード一覧"
}
</script>

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

<h1>AIキーワード登録履歴｜日別トレンド一覧</h1>
<p>AIKnowledgeCMSで日々追加されたキーワードを時系列で表示します。</p>

<div id="container">
<?php
$initial = 10;
$count = 0;

foreach ($dates as $date) {

    if ($count >= $initial) break;

    echo '<div class="date-block">';
    echo '<div class="date-title">'.h($date).'</div>';
    echo '<div class="keyword-list">';

    foreach ($keywords_by_date[$date] as $kw) {

        echo '<a href="./aiknowledgecms.php?kw='.urlencode($kw).'">';
        echo h($kw);
        echo '</a>';
    }

    echo '</div>';
    echo '</div>';

    $count++;
}
?>
</div>

<div id="loading">スクロールで読み込み</div>

<script>

let offset = 10;
let loading = false;
let finished = false;

window.addEventListener("scroll", function(){

    if(loading || finished) return;

    if(window.innerHeight + window.scrollY >= document.body.offsetHeight - 200){

        loading = true;

        fetch("newkeywords.php?ajax=1&offset=" + offset)
        .then(r => r.text())
        .then(html => {

            if(html.trim() === "END"){
                document.getElementById("loading").innerText = "これ以上ありません";
                finished = true;
                return;
            }

            document.getElementById("container")
                .insertAdjacentHTML("beforeend", html);

            offset += 10;
            loading = false;
        })
        .catch(() => {
            loading = false;
        });
    }
});

</script>

</body>
</html>
