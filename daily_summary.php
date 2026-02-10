<?php
date_default_timezone_set("Asia/Tokyo");

$debug_info = array();

/* =====================
   設定
===================== */
define("DAILY_SUMMARY_API", "http://exbridge.ddns.net:8003/daily_summary");

$data_dir     = __DIR__ . "/data";
$keyword_file = __DIR__ . "/keyword.json";

/* =====================
   基準日
===================== */
$today = date("Y-m-d");
$base_date = $today;

if (isset($_GET["date"])) {
    if (preg_match("/^\d{4}-\d{2}-\d{2}$/", $_GET["date"])) {
        $base_date = $_GET["date"];
    }
}

/* =====================
   日次サマリーファイル
===================== */
$summary_file = $data_dir . "/" . $base_date . "_daily_summary.json";
$audio_file   = $data_dir . "/" . $base_date . "_daily_summary.wav";
$audio_url    = "./data/" . $base_date . "_daily_summary.wav";

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

if (isset($_POST["save_summary"])) {

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
   既存サマリー読込
===================== */
$summary_text = isset($summary_text) ? $summary_text : "";

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

    $files = glob($data_dir . "/" . $base_date . "_*.json");
    if ($files !== false) {

        foreach ($files as $f) {

            if (preg_match("/_daily_summary\.json$/", $f)) {
                continue;
            }

            $data = json_decode(file_get_contents($f), true);
            if (!is_array($data)) continue;


            if (isset($data["analysis"]) && trim($data["analysis"]) !== "") {
                $knowledge_texts[] = trim($data["analysis"]);
            }

        }
    }

    /* =====================
       API 要約生成
    ===================== */
    if (count($knowledge_texts) > 0) {
        $debug_info[] = "analysis count = " . count($knowledge_texts);

        $res = http_post_json(
            DAILY_SUMMARY_API,
            array(
                "texts" => $knowledge_texts,
                "date"  => $base_date
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
$keywords = array();

if (file_exists($keyword_file)) {

    $json = json_decode(file_get_contents($keyword_file), true);

    if (isset($json["keywords"]) && is_array($json["keywords"])) {
        $json = $json["keywords"];
    }

    if (is_array($json)) {
        foreach ($json as $kw) {
            $kw = trim($kw);
            if ($kw === "") continue;

            // ★ 該当日のこのキーワードのjsonが存在するか確認
            $pattern = $data_dir . "/" . $base_date . "_*" . $kw . "*.json";
            $matched = glob($pattern);

            if ($matched !== false && count($matched) > 0) {
                $keywords[] = $kw;
            }
        }
    }
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
<style>
body{background:#020617;color:#e5e7eb;font-family:sans-serif;padding:16px}
textarea{width:100%;height:240px;background:#020617;color:#e5e7eb;border:1px solid #334155;border-radius:12px;padding:12px}
button{margin-top:12px;padding:10px 16px;border-radius:10px;border:0;background:#2563eb;color:#fff}
.date{color:#94a3b8;margin-bottom:12px}
.nav{margin-bottom:16px}
.nav a{color:#38bdf8;margin-right:12px}
.audio{margin:16px 0}
.keywords{margin:20px 0;display:flex;flex-wrap:wrap;gap:10px}
.keywords a{
    padding:6px 12px;
    border-radius:999px;
    background:#111827;
    border:1px solid #334155;
    color:#93c5fd;
    font-size:13px;
    text-decoration:none
}
.keywords a:hover{background:#1e293b}
.list{margin-top:20px}
.row{display:flex;align-items:center;gap:12px;margin-bottom:8px}
.playall{background:#16a34a}
audio{
    width:100%;
    max-width:100%;
}

.row{
    flex-wrap:wrap;
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
</head>
<body>
<div style="
    margin-bottom:16px;
    padding:12px;
    border-radius:10px;
    background:#1f2937;
    color:#fca5a5;
    font-size:13px;
    line-height:1.6;
">
<strong>DEBUG daily_summary</strong><br>
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

<img src="./images/aiknowledgecms_logo.png" width="30%" height="30%">

<div class="nav">
<?php if ($saved): ?>
<div style="
    margin-bottom:16px;
    padding:10px 14px;
    border-radius:10px;
    background:#064e3b;
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
<a href="?date=<?php echo h($prev); ?>">← 前日</a>
<span class="date"><?php echo h($base_date); ?></span>
<?php if ($next <= $today): ?>
<a href="?date=<?php echo h($next); ?>">翌日 →</a>
<?php endif; ?>
</div>

<?php if (count($keywords) > 0): ?>
<div class="keywords">
<?php foreach ($keywords as $kw): ?>
<a href="https://aiknowledgecms.exbridge.jp/aiknowledgecms.php?kw=<?php echo h(urlencode($kw)); ?>">
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

<form method="post">
    <textarea name="summary_text"><?php echo h($summary_text); ?></textarea>
    <button type="submit" name="save_summary" value="1">保存</button>
</form>

<?php if (count($daily_list) > 0): ?>
<button class="playall" onclick="playAll()">全部再生</button>

<div class="list">
<?php foreach ($daily_list as $d): ?>
<div class="row">
    <a href="?date=<?php echo h($d["date"]); ?>">
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
