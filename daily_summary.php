<?php
date_default_timezone_set("Asia/Tokyo");

/* =====================
   設定
===================== */
define("OLLAMA_URL", "https://exbridge.ddns.net/api/generate");
define("OLLAMA_MODEL", "gemma3:12b");

$data_dir = __DIR__ . "/data";

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

function http_post_json($url, $payload, $timeout = 120) {
    $ch = curl_init($url);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => array(
            "Content-Type: application/json",
        ),
    ));
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($res === false) return array(false, null);
    $data = json_decode($res, true);
    return array(($code >= 200 && $code < 300), $data);
}

/* =====================
   保存処理（手動編集）
===================== */
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
}

/* =====================
   既存サマリー読込
===================== */
$summary_text = "";

if (file_exists($summary_file)) {

    $json = json_decode(file_get_contents($summary_file), true);
    if (is_array($json) && isset($json["summary_text"])) {
        $summary_text = $json["summary_text"];
    }

} else {

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

            if (isset($data["radio_script"]) && trim($data["radio_script"]) !== "") {
                $knowledge_texts[] = trim($data["radio_script"]);
            }
        }
    }

    /* =====================
       Ollama 要約生成
    ===================== */
    if (count($knowledge_texts) > 0) {

        $joined = implode("\n\n", $knowledge_texts);

        $prompt = "
あなたは
「日次研究ノートをまとめる分析者」です。
あなたは文章を評価してはいけません。

以下は同一日の複数の知識レポートです。
それぞれ異なるキーワード・テーマを扱っています。

あなたの仕事は、
これらすべてのキーワードについて、
それぞれの背景・意味・影響を整理し、
同一日の出来事として並列に日本語で考察することです。

重要：
- 代表的なテーマを選んではいけません
- 特定のキーワードを中心に据えてはいけません
- すべてのキーワードを必ず個別に扱ってください
- 分量は均等である必要はありませんが、
  どのキーワードも「考察」が成立している必要があります
- 日本語で出力してください 

# 出力条件
- 600〜1200文字
- 見出し・箇条書き・URL禁止
- 主観的な感想は禁止
- ニュースの羅列は禁止
- 各キーワードごとに文脈的な整理と意味付けを行う
- 最後に「この日全体として何が読み取れるか」を
  短くまとめてください


# 知識レポート一覧
{$joined}
";

        list($ok, $res) = http_post_json(
            OLLAMA_URL,
            array(
                "model" => OLLAMA_MODEL,
                "prompt" => $prompt,
                "stream" => false,
                "options" => array(
                    "temperature" => 0.4
                )
            )
        );

        if ($ok && isset($res["response"])) {

            $summary_text = trim($res["response"]);

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
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{background:#020617;color:#e5e7eb;font-family:sans-serif;padding:16px}
textarea{width:100%;height:320px;background:#020617;color:#e5e7eb;border:1px solid #334155;border-radius:12px;padding:12px}
button{margin-top:12px;padding:10px 16px;border-radius:10px;border:0;background:#2563eb;color:#fff}
.date{color:#94a3b8;margin-bottom:12px}
.nav{margin-bottom:16px}
.nav a{color:#38bdf8;margin-right:12px}
.audio{margin:16px 0}
</style>
</head>
<body>
<img src="./images/aiknowledgecms_logo.png" width="30%" height="30%">

<div class="nav">
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

<?php if (file_exists($audio_file)): ?>
<div class="audio">
    <audio controls src="<?php echo h($audio_url); ?>"></audio>
</div>
<?php endif; ?>

<form method="post">
    <textarea name="summary_text"><?php echo h($summary_text); ?></textarea>
    <button type="submit" name="save_summary" value="1">保存</button>
</form>

</body>
</html>

