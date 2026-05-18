<?php
date_default_timezone_set("Asia/Tokyo");

$debug_info = array();

/* =====================
   設定
===================== */
define("DAILY_SUMMARY_API", "http://exbridge.ddns.net:8003/daily_summary");
define("AIKNOWLEDGE_TOKEN", "秘密の文字列");

/* =====================
   adminモード判定
===================== */
$is_admin = isset($_GET["admin"]) && $_GET["admin"] === AIKNOWLEDGE_TOKEN;

$data_dir     = __DIR__ . "/data";
$keyword_file = __DIR__ . "/keyword.json";

/* =====================
   基準日
===================== */
$today = date("Y-m-d");
$yesterday = date("Y-m-d", strtotime("-1 day"));
$base_date = date("Y-m-d", strtotime("-1 day"));

if (!empty($_GET["date"]) && preg_match("/^\d{4}-\d{2}-\d{2}$/", $_GET["date"])) {
    $base_date = $_GET["date"];
}

/* =====================
   日次サマリーファイル
===================== */
$summary_file = $data_dir . "/" . $base_date . "_daily_summary.json";
$audio_file   = $data_dir . "/" . $base_date . "_daily_summary.wav";
$audio_url    = "./data/" . $base_date . "_daily_summary.wav";

if(isset($_GET["api_get_daily_summary"])){

    header("Content-Type: application/json; charset=UTF-8");

    if(!isset($_GET["token"]) || $_GET["token"] !== AIKNOWLEDGE_TOKEN){
        echo json_encode(array());
        exit;
    }

    $date = isset($_GET["date"]) ? $_GET["date"] : date("Y-m-d", strtotime("-1 day"));

    $file = $data_dir . "/" . $date . "_daily_summary.json";

    if(!file_exists($file)){
        echo json_encode(array());
        exit;
    }

    echo file_get_contents($file);
    exit;
}

/* =====================
   Utility
===================== */
function h($s) {
    return htmlspecialchars($s, ENT_QUOTES, "UTF-8");
}

function http_post_json($url, $payload, $timeout = 180) {

    $ch = curl_init($url);

    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => array(
            "Content-Type: application/json",
        ),
    ));

    $res = curl_exec($ch);
    curl_close($ch);

    if ($res === false) return null;

    return json_decode($res, true);
}

/* =====================
   保存処理（手動編集）
===================== */
$saved = false;
if (isset($_POST["save_summary"]) && $is_admin) {

    $text = isset($_POST["summary_text"])
        ? trim($_POST["summary_text"])
        : "";

    file_put_contents(
        $summary_file,
        json_encode(
            array(
                "date" => $base_date,
                "edited_at" => date("Y-m-d H:i:s"),
                "summary_text" => $text
            ),
            JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
        )
    );

    $summary_text = $text;
    $saved = true;
}

/* =====================
   削除処理
===================== */
if (isset($_POST["delete_summary"]) && $is_admin) {

    if ($base_date < $today) {

        if (file_exists($summary_file)) {
            unlink($summary_file);
        }

        if (file_exists($audio_file)) {
            unlink($audio_file);
        }

        // 画面リロード
        header("Location: ?date=" . $base_date . ($is_admin ? "&admin=" . urlencode(AIKNOWLEDGE_TOKEN) : ""));
        exit;
    }
}


/* =====================
   既存サマリー読込
===================== */
$summary_text = isset($summary_text) ? $summary_text : "";

/* =====================
   テスト用：毎回削除
===================== */
if (file_exists($summary_file)) {
    //unlink($summary_file);
}




if (!$saved && file_exists($summary_file)) {

    $json = json_decode(file_get_contents($summary_file), true);
    if (is_array($json) && isset($json["summary_text"])) {
        $summary_text = $json["summary_text"];
    }

} elseif (!$saved) {

    /* =====================
       当日分 知識JSON収集
    ===================== */
    $knowledge_texts = array();

    // ★ keyword.json から統計取得
    $keyword_master = array();
    if (file_exists($keyword_file)) {
        $tmp = json_decode(file_get_contents($keyword_file), true);
        if (isset($tmp["keywords"]) && is_array($tmp["keywords"])) {
            $keyword_master = $tmp["keywords"];
        }
    }

    $files = glob($data_dir . "/" . $base_date . "_*.json");
    if ($files !== false) {

        $limit_count = 0;

        // ★ まず対象ファイルを created_at 付きで配列化
        $targets = array();

        foreach ($files as $f) {

            if (preg_match("/_daily_summary\.json$/", $f)) {
                continue;
            }

            if (!preg_match("/\d{4}-\d{2}-\d{2}_(.+)\.json$/", $f, $m)) {
                continue;
            }

            $kw = $m[1];

            if (!isset($keyword_master[$kw])) {
                continue;
            }

            $created = isset($keyword_master[$kw]["created_at"])
                ? $keyword_master[$kw]["created_at"]
                : "1970-01-01 00:00:00";

            $targets[] = array(
                "file" => $f,
                "kw"   => $kw,
                "created_at" => $created
            );
        }

        // ★ created_at の新しい順に並び替え
        usort($targets, function($a, $b){
            return strcmp($b["created_at"], $a["created_at"]);
        });

        // ★ ソート後に処理
        foreach ($targets as $item) {

            if ($limit_count >= 5) {
                break;
            }

            $f  = $item["file"];
            $kw = $item["kw"];

            // ★ views > 0 && count > 0 条件
            if (
                !isset($keyword_master[$kw]["views"]) ||
                !isset($keyword_master[$kw]["count"]) ||
                intval($keyword_master[$kw]["views"]) <= 0 ||
                intval($keyword_master[$kw]["count"]) <= 0
            ) {
                continue;
            }

            $data = json_decode(file_get_contents($f), true);
            if (!is_array($data)) continue;

            if (isset($data["analysis"]) && trim($data["analysis"]) !== "") {
                $knowledge_texts[] = trim($data["analysis"]);
                $limit_count++;
            }
        }
    }
    /* =====================
       API 要約生成
    ===================== */
    if (count($knowledge_texts) > 0 && $base_date < $today) {
        $debug_info[] = "analysis count = " . count($knowledge_texts);

        $joined = implode("\n\n", $knowledge_texts);

        $prompt = "
以下の知識レポートを、そのまま簡潔に日本語で要約してください。
評価・感想・提案・改善点は絶対に出力しません。

【厳守ルール】
・要約本文のみ出力すること
・感想・評価・コメント・提案は一切不要
・箇条書きや見出しは使わず、文章でまとめること
・日本語のみ使用すること
・文字数：600〜2200文字

日付：{$base_date}

知識レポート：
{$joined}
";
        $res = http_post_json(
            DAILY_SUMMARY_API,
            array(
                "prompt" => $prompt
            )
        );

        if (!is_array($res)) {
            $debug_info[] = "API response = not array (null or invalid JSON)";
        } elseif (!isset($res["summary"])) {
            $debug_info[] = "API response OK but summary key missing";
        } else {
            $debug_info[] = "API response OK, summary length = " . strlen($res["summary"]);
        }

        if (is_array($res) && isset($res["summary"])) {

            $summary_text = trim($res["summary"]);

            file_put_contents(
                $summary_file,
                json_encode(
                    array(
                        "date" => $base_date,
                        "generated_at" => date("Y-m-d H:i:s"),
                        "summary_text" => $summary_text
                    ),
                    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
                )
            );
        }
    }
}

/* =====================
   キーワード読込（jsonのみ）
===================== */
/* =====================
   キーワード読込（jsonのみ）
===================== */
$keywords = array();

if (file_exists($keyword_file)) {

    $json = json_decode(file_get_contents($keyword_file), true);

    if (isset($json["keywords"]) && is_array($json["keywords"])) {

        // ★ キーワードは連想配列のキーとして取得
        foreach ($json["keywords"] as $kw => $kw_data) {
            $kw = trim($kw);
            if ($kw === "") continue;

            // ★ 該当日のこのキーワードのjsonが存在するか確認
            $pattern = $data_dir . "/" . $base_date . "_" . $kw . ".json";

            if (file_exists($pattern)) {
                $keywords[] = $kw;
            }
        }
    }
}

if (count($keywords) > 20) {
    $keywords = array_slice($keywords, 0, 20);
}

/* =====================
   全日付サマリー一覧
===================== */
$daily_list = array();
$files = glob($data_dir . "/*_daily_summary.json");
if ($files !== false) {
    foreach ($files as $f) {
        if (preg_match("/(\d{4}-\d{2}-\d{2})_daily_summary\.json$/", $f, $m)) {
            $date = $m[1];
            $wav  = $data_dir . "/" . $date . "_daily_summary.wav";
            $daily_list[] = array(
                "date"  => $date,
                "audio" => file_exists($wav) ? "./data/" . $date . "_daily_summary.wav" : null
            );
        }
    }
}
usort($daily_list, function($a, $b) {
    return strcmp($b["date"], $a["date"]);
});
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<title><?php echo h($base_date); ?>｜AIニュース要約・生成AI動向まとめ</title>
<meta name="description" content="<?php echo h($base_date); ?>のAIニュースをAIが分析・要約。生成AI・LLM・企業動向などを横断的にまとめた日次レポート。">
<link rel="canonical" href="./daily_summary.php?date=<?php echo h($base_date); ?>">
<meta name="robots" content="index,follow">
<style>
.aitrend-link {
    display: flex;
    align-items: center;
    gap: 8px;
    text-decoration: none;
}

.aitrend-link img {
    width: 32px;
    height: auto;
}
/* Thinking Overlay */
#thinking-overlay{
    position:fixed;
    inset:0;
    background:rgba(241,245,249,0.95);
    display:none;
    align-items:center;
    justify-content:center;
    z-index:9999
}
#thinking-box{
    background:#ffffff;
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
    color:#64748b
}
#loading-indicator {
    text-align: center;
    padding: 20px;
    color: #94a3b8;
    display: none;
}

#loading-indicator.show {
    display: block;
}
/* H1：ページタイトル（普通サイズ） */
h1{
  font-size: 18px;
  font-weight: 600;
  line-height: 1.4;
  margin: 24px 0 16px;
}
body{background:#f1f5f9;color:#e5e7eb;font-family:sans-serif;padding:16px}
textarea{width:100%;height:240px;background:#f1f5f9;color:#e5e7eb;border:1px solid #334155;border-radius:12px;padding:12px}
button{margin-top:12px;padding:10px 16px;border-radius:10px;border:0;background:#6d28d9;color:#1e293b}
.date{color:#64748b;margin-bottom:12px}
.nav{margin-bottom:16px}
.nav a{color:#38bdf8;margin-right:12px}
.audio{margin:16px 0}
.keywords{margin:20px 0;display:flex;flex-wrap:wrap;gap:10px}
.keywords a{
    padding:6px 12px;
    border-radius:999px;
    background:#ffffff;
    border:1px solid #334155;
    color:#93c5fd;
    font-size:13px;
    text-decoration:none
}
.keywords a:hover{background:#f8fafc}
.list{margin-top:20px}
.row{display:flex;align-items:center;gap:12px;margin-bottom:8px}
.playall{background:#059669}
audio{
    width:100%;
    max-width:600px;
}

.row{
    flex-wrap:wrap;
    display:flex;
    flex-direction:column;
    align-items:stretch;
}

.row a{
    width:100%;
    margin-bottom:6px;
}

/* スマホ用 */
@media (max-width:600px){
    .row{
        flex-direction:column;
        align-items:stretch;
    }
}

</style>

<script type="application/ld+json">
{
 "@context": "https://schema.org",
 "@type": "Article",
 "headline": "<?php echo h($base_date); ?>のAIニュース要約",
 "datePublished": "<?php echo h($base_date); ?>",
 "author": {
   "@type": "Organization",
   "name": "AIKnowledgeCMS"
 },
 "publisher": {
   "@type": "Organization",
   "name": "AIKnowledgeCMS"
 }
}
</script>
</head>
<body>
<!--
<div style="
    margin-bottom:16px;
    padding:12px;
    border-radius:10px;
    background:#f8fafc;
    color:#fca5a5;
    font-size:13px;
    line-height:1.6;
">
<?php
if (empty($debug_info)) {
    echo "no debug info";
} else {
    foreach ($debug_info as $d) {
        echo h($d) . "<br>";
    }
}
?>
</div>
-->

<div class="header-bar">
<a href="?date=<?php echo h($yesterday); ?><?php if($is_admin) echo "&admin=".h(AIKNOWLEDGE_TOKEN); ?>" class="cms-logo">
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

<h1><?php echo h($base_date); ?>のAIニュース要約レポート</h1>
<p>
本ページは<?php echo h($base_date); ?>に収集されたAI関連ニュースを、
AIが分析し要約した日次レポートです。
生成AI、LLM、AI企業動向などを横断的に把握できます。
</p>

<div id="thinking-overlay">
    <div id="thinking-box">
        <div id="thinking-title">Thinking </div>
        <div id="thinking-sub">AI が考えています。しばらくお待ちください。</div>
    </div>
</div>

<div class="nav">
<?php if ($saved): ?>
<div style="
    margin-bottom:16px;
    padding:10px 14px;
    border-radius:10px;
    background:#d1fae5;
    color:#6ee7b7;
    font-size:14px;
">
    保存しました
</div>
<?php endif; ?>

<?php
$prev = date("Y-m-d", strtotime($base_date." -1 day"));
$next = date("Y-m-d", strtotime($base_date." +1 day"));
?>
<a href="?date=<?php echo h($prev); ?><?php if($is_admin) echo "&admin=".h(AIKNOWLEDGE_TOKEN); ?>">← 前日</a>
<span class="date"><?php echo h($base_date); ?></span>
<?php if ($next <= $today): ?>
<a href="?date=<?php echo h($next); ?><?php if($is_admin) echo "&admin=".h(AIKNOWLEDGE_TOKEN); ?>">翌日 →</a>
<?php endif; ?>
</div>

<?php if (count($keywords) > 0): ?>
<div class="keywords">
<?php foreach ($keywords as $kw): ?>
<a href="./aiknowledgecms.php?base_date=<?php echo h($base_date); ?>&kw=<?php echo h(urlencode($kw)); ?>">
<?php echo h($kw); ?>
</a>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (file_exists($audio_file)): ?>
<div class="audio">
    <audio controls src="<?php echo h($audio_url); ?>"></audio>
</div>
<?php endif; ?>

<?php if ($is_admin): ?>
<form method="post" onsubmit="showThinking()">
<div class="summary-text">
<?php echo nl2br(h($summary_text)); ?>
</div>    
<button type="submit" name="save_summary" value="1">保存</button>

<?php if ($base_date < $today): ?>
    <button type="submit" name="delete_summary" value="1"
    style="background:#b91c1c;margin-left:10px;"
    onclick="return confirm('本当に削除しますか？');">
    削除
    </button>
<?php endif; ?>

</form>
<?php else: ?>
<textarea readonly><?php echo h($summary_text); ?></textarea>
<?php endif; ?>


<?php if (count($daily_list) > 0): ?>
<button class="playall" onclick="playAll()">全部再生</button>

<div class="list">
<?php foreach ($daily_list as $d): ?>
<div class="row">
    <a href="?date=<?php echo h($d["date"]); ?><?php if($is_admin) echo "&admin=".h(AIKNOWLEDGE_TOKEN); ?>">
        <?php echo h($d["date"]); ?>
    </a>

    <?php if ($d["audio"]): ?>
    <audio
        controls
        src="<?php echo h($d["audio"]); ?>">
    </audio>
    <?php endif; ?>

<?php endforeach; ?>
</div>
<?php endif; ?>
</div>
<script>
(function(){
    var s = document.createElement('script');
    s.src = 'https://aiknowledgecms.exbridge.jp/simpletrack.php'
          + '?url=' + encodeURIComponent(location.href)
          + '&ref=' + encodeURIComponent(document.referrer);
    document.head.appendChild(s);
})();
</script>
<script>
function showThinking(){
    // 既存のコード
    var overlay = document.getElementById("thinking-overlay");
    if(overlay){
        overlay.style.display = "flex";
    }

    setTimeout(function(){
        var els = document.querySelectorAll("input, button, textarea");
        els.forEach(function(e){
            e.disabled = true;
        });
    }, 0);

    return true;
}
function playAll() {
    const audios = document.querySelectorAll(".list audio");
    let i = 0;
    function next() {
        if (i >= audios.length) return;
        audios[i].currentTime = 0;
        audios[i].play();
        audios[i].onended = function () {
            i++;
            next();
        };
    }
    next();
}
</script>

</body>
</html>
