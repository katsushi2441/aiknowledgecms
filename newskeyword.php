<?php
date_default_timezone_set("Asia/Tokyo");

define("DATA_DIR", __DIR__ . "/data");

function h($s){
    return htmlspecialchars($s, ENT_QUOTES, "UTF-8");
}

/* =========================
   keyword.json 読み込み
========================= */
$keyword_data = array();
$keyword_file = __DIR__ . "/keyword.json";

if (file_exists($keyword_file)) {
    $tmp = json_decode(file_get_contents($keyword_file), true);
    if (isset($tmp["keywords"]) && is_array($tmp["keywords"])) {
        $keyword_data = $tmp["keywords"];
    }
}

/* =========================
   mode取得
========================= */
$mode = "";
if (isset($_GET["mode"])) {
    $mode = $_GET["mode"]; // new / popular
}

/* =========================
   日付処理
========================= */
$today = date("Y-m-d");

$base_date = $today;
if (isset($_GET["base_date"]) && preg_match("/^\d{4}-\d{2}-\d{2}$/", $_GET["base_date"])) {
    $base_date = $_GET["base_date"];
}

$prev_date = date("Y-m-d", strtotime($base_date . " -1 day"));
$next_date = date("Y-m-d", strtotime($base_date . " +1 day"));
$can_next  = ($next_date <= $today);

/* =========================
   指定日のJSON取得
========================= */
$keywords = array();
$news_list = array();

if (file_exists(DATA_DIR)) {

    /* yyyymmサブディレクトリを優先、なければdata直下 */
    $ym = date("Ym", strtotime($base_date));
    $sub_dir = DATA_DIR . "/" . $ym;
    $scan_dir = file_exists($sub_dir) ? $sub_dir : DATA_DIR;

    foreach (scandir($scan_dir) as $f) {

        if ($f === "." || $f === "..") continue;
        if (substr($f, -5) !== ".json") continue;
        if (strpos($f, $base_date . "_") !== 0) continue;

        $json = json_decode(file_get_contents($scan_dir . "/" . $f), true);
        if (!is_array($json)) continue;
        if (!isset($json["keyword"])) continue;

        $kw = $json["keyword"];
        $keywords[$kw] = true;

        if (isset($json["news"]) && is_array($json["news"])) {
            foreach ($json["news"] as $n) {
                if (!isset($n["title"])) continue;

                $news_list[] = array(
                    "keyword" => $kw,
                    "title"   => $n["title"],
                    "link"    => isset($n["link"]) ? $n["link"] : "",
                    "pubDate" => isset($n["pubDate"]) ? $n["pubDate"] : ""
                );
            }
        }
    }
}

/* =========================
   フィルター処理
========================= */
$filtered_keywords = array();

foreach ($keywords as $kw => $_) {

    if (!isset($keyword_data[$kw])) continue;

    if ($mode === "new") {

        if (
            isset($keyword_data[$kw]["created"]) &&
            $keyword_data[$kw]["created"] === $base_date
        ) {
            $filtered_keywords[$kw] = true;
        }

    }
    elseif ($mode === "popular") {

        if (
            isset($keyword_data[$kw]["views"]) &&
            (int)$keyword_data[$kw]["views"] >= 2
        ) {
            $filtered_keywords[$kw] = true;
        }

    }
    else {
        $filtered_keywords[$kw] = true;
    }
}

ksort($filtered_keywords);
?>

<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title><?php echo h($base_date); ?>｜ニュース＆キーワード一覧</title>
<link rel="stylesheet" href="./style.css">
<meta name="description" content="AIニュースとトレンドキーワードを日付別に一覧表示。生成AI・LLM・最新AI技術ニュースを自動収集し、キーワードとともに整理しています。">
<meta name="robots" content="index,follow">
<link rel="canonical" href="https://aiknowledgecms.exbridge.jp/newskeyword.php?base_date=<?php echo h($base_date); ?>">

<!-- Open Graph -->
<meta property="og:type" content="website">
<meta property="og:title" content="<?php echo h($base_date); ?>｜AIニュース＆キーワード一覧">
<meta property="og:description" content="AIニュースとトレンドキーワードを日付別に整理・可視化">
<meta property="og:url" content="https://aiknowledgecms.exbridge.jp/newskeyword.php?base_date=<?php echo h($base_date); ?>">
<meta property="og:image" content="https://aiknowledgecms.exbridge.jp/images/newskeyword_logo.png">

<!-- Twitter -->
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="AIニュース＆キーワード">
<meta name="twitter:description" content="AI関連ニュースをキーワードとともに一覧表示">
<meta name="twitter:image" content="https://aiknowledgecms.exbridge.jp/images/newskeyword_logo.png">

<style>
body{ background:#f1f5f9 !important; color:#1e293b !important; }
.app{ background:#f1f5f9; }
.header-bar{ background:#fff !important; border:1px solid #e2e8f0 !important; border-radius:8px; padding:12px 16px; box-shadow:0 1px 3px rgba(0,0,0,.06); }
h1,h2{ color:#1e293b !important; }
.news-card{
    border:1px solid #e2e8f0;
    padding:18px;
    margin-bottom:14px;
    border-radius:10px;
    background:#ffffff;
    box-shadow:0 1px 3px rgba(0,0,0,.06);
    transition:all .2s ease;
}
.news-card:hover{
    transform:translateY(-2px);
    border-color:#7c3aed;
    box-shadow:0 6px 16px rgba(124,58,237,.1);
}
.news-card .kw a{
    display:inline-block;
    padding:3px 10px;
    border-radius:999px;
    background:rgba(124,58,237,.1);
    color:#6d28d9;
    font-weight:500;
    text-decoration:none;
    transition:all .2s ease;
}
.news-card .kw a:hover{ background:rgba(124,58,237,.2); }
.news-card .title{ color:#1e293b; }
.news-card .date{ color:#64748b; font-size:12px; }
.news-card a{ color:#2563eb; }
.keywords a, .keyword-list a{
    display:inline-block;
    padding:5px 12px;
    border-radius:999px;
    background:#fff;
    border:1px solid #e2e8f0;
    color:#475569;
    text-decoration:none;
    transition:all .2s ease;
    font-size:12px;
}
.keywords a:hover, .keyword-list a:hover{ background:rgba(124,58,237,.08);color:#6d28d9;border-color:#7c3aed; }
.date-nav{ color:#1e293b; }
.date-nav a{ display:inline-block;white-space:nowrap;word-break:keep-all;color:#475569;border:1px solid #e2e8f0;padding:4px 12px;border-radius:4px;background:#fff; }
.date-nav a:hover{ color:#6d28d9;border-color:#7c3aed; }
.date-nav strong{ color:#1e293b; }
.header-bar a,.header-bar span{ white-space:nowrap;word-break:keep-all; }
.aitrend-text{ color:#64748b !important; -webkit-text-fill-color:#64748b !important; }
.aitrend-link:hover .aitrend-text{ color:#6d28d9 !important; -webkit-text-fill-color:#6d28d9 !important; }
</style>
<script type="application/ld+json">
{
 "@context": "https://schema.org",
 "@type": "NewsArticle",
 "headline": "<?php echo h($base_date); ?>のAIニュース一覧",
 "datePublished": "<?php echo h($base_date); ?>",
 "publisher": {
   "@type": "Organization",
   "name": "AIKnowledgeCMS"
 }
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

<h1><?php echo h($base_date); ?>のAIニュースとトレンドキーワード一覧</h1>
<p>
本ページでは<?php echo h($base_date); ?>に収集されたAI関連ニュースを、
キーワード別に整理しています。
生成AI、LLM、AI企業動向などの最新情報を横断的に把握できます。
</p>
<hr>

<!-- 日付ナビ -->
<div class="date-nav" style="display:flex;gap:14px;align-items:center;margin-bottom:20px;flex-wrap:wrap">

    <a href="?base_date=<?php echo h($prev_date); ?>">←</a>
    <strong><?php echo h($base_date); ?></strong>

    <?php if ($can_next): ?>
        <a href="?base_date=<?php echo h($next_date); ?>">→</a>
    <?php else: ?>
        <span style="opacity:.3">→</span>
    <?php endif; ?>

    <span style="margin-left:20px;"></span>

    <a href="?base_date=<?php echo h($base_date); ?>&mode=new">新着キーワード</a>
    <a href="?base_date=<?php echo h($base_date); ?>&mode=popular">人気キーワード</a>

    <?php if ($mode !== ""): ?>
        <a href="?base_date=<?php echo h($base_date); ?>">すべて</a>
    <?php endif; ?>

</div>

<!-- キーワード一覧 -->
<h2>キーワード</h2>

<div class="keywords keyword-list">
<?php foreach ($filtered_keywords as $kw => $_): ?>
<a href="#kw-<?php echo h(urlencode($kw)); ?>">
<?php echo h($kw); ?>
</a>
<?php endforeach; ?>
</div>

<hr>

<!-- ニュース一覧 -->
<h2>ニュース</h2>

<?php
$displayed = false;

foreach ($news_list as $n):
    if (!isset($filtered_keywords[$n["keyword"]])) continue;
    $displayed = true;
?>
<div class="news-card" id="kw-<?php echo h(urlencode($n["keyword"])); ?>">

    <div class="kw">
        キーワード：
        <a href="./aiknowledgecms.php?kw=<?php echo h(urlencode($n["keyword"])); ?>&base_date=<?php echo h($base_date); ?>">
            <?php echo h($n["keyword"]); ?>
        </a>
    </div>

    <div class="title"><?php echo h($n["title"]); ?></div>

    <div class="date"><?php echo h($n["pubDate"]); ?></div>

    <?php if ($n["link"] !== ""): ?>
    <a href="<?php echo h($n["link"]); ?>" target="_blank" rel="nofollow noopener">
        Googleニュースを開く
    </a>
    <?php endif; ?>

</div>
<?php endforeach; ?>

<?php if (!$displayed): ?>
<p>該当するキーワードはありません。</p>
<?php endif; ?>

</body>
</html>

