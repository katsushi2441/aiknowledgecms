<?php
date_default_timezone_set("Asia/Tokyo");

/* =====================
   設定・定数
===================== */
define("OLLAMA_URL", "https://exbridge.ddns.net/api/generate");
define("OLLAMA_MODEL", "gemma3:12b");
define("NEWS_LIMIT", 5);

$keyword_file = __DIR__ . "/keyword.txt";
$data_dir     = __DIR__ . "/data";

if (!file_exists($data_dir)) {
    mkdir($data_dir, 0755, true);
}
if (!file_exists($keyword_file)) {
    echo "keyword.txt not found";
    exit;
}

/* ★FIX: 日付は「日」基準で統一（ファイル名・比較用） */
$today = date("Y-m-d");

/* ★FIX: 基準日（base_date） */
$base_date = $today;
if (isset($_GET["base_date"])) {
    if (preg_match("/^\d{4}-\d{2}-\d{2}$/", $_GET["base_date"])) {
        $base_date = $_GET["base_date"];
    }
}

/* =====================
   JSON list API
===================== */
if (isset($_GET["list_json"]) && $_GET["list_json"] === "1") {

    if (!isset($_GET["token"]) || $_GET["token"] !== "秘密の文字列") {
        http_response_code(403);
        exit;
    }

    $files = glob($data_dir . "/*.json");
    if ($files === false) {
        echo json_encode([]);
        exit;
    }

    $list = [];
    foreach ($files as $f) {
        $list[] = basename($f);
    }

    sort($list);

    header("Content-Type: application/json; charset=UTF-8");
    echo json_encode($list, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

if (isset($_GET["upload_audio"]) && $_GET["upload_audio"] === "1") {

    if (!isset($_GET["token"]) || $_GET["token"] !== "秘密の文字列") {
        http_response_code(403);
        exit;
    }

    $raw = file_get_contents("php://input");
    $req = json_decode($raw, true);

    if (
        !is_array($req) ||
        empty($req["audio_url"]) ||
        empty($req["json_file"])
    ) {
        http_response_code(400);
        exit;
    }

    $json_file = basename($req["json_file"]);
    $audio_url = $req["audio_url"];

    $base = pathinfo($json_file, PATHINFO_FILENAME);
    $audio_name = $base . ".wav";
    $audio_path = $data_dir . "/" . $audio_name;

    $json_path = $data_dir . "/" . $json_file;
    if (!file_exists($json_path)) {
        http_response_code(404);
        exit;
    }

    $ch = curl_init($audio_url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 120,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $audio_bin = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($audio_bin === false || $status !== 200) {
        http_response_code(502);
        exit;
    }

    if (strlen($audio_bin) > 20 * 1024 * 1024) {
        http_response_code(413);
        exit;
    }

    file_put_contents($audio_path, $audio_bin);

    $data = json_decode(file_get_contents($json_path), true);
    if (!is_array($data)) {
        http_response_code(500);
        exit;
    }

    $data["audio_file"] = $audio_name;
    $data["audio_generated_at"] = date("Y-m-d H:i:s");

    file_put_contents(
        $json_path,
        json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );

    echo "OK";
    exit;
}

/* =====================
   Utility
===================== */
function h($s) {
    return htmlspecialchars($s, ENT_QUOTES, "UTF-8");
}

function http_post_json($url, $payload, $timeout = 120) {
    $ch = curl_init($url);
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/json",
        ],
    ]);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($res === false) return [false, null];
    $data = json_decode($res, true);
    return [($code >= 200 && $code < 300), $data];
}

/* =====================
   Google News
===================== */
function fetch_google_news($keyword, $target_date = null) {

    $rss = "https://news.google.com/rss/search?q="
         . urlencode($keyword)
         . "&hl=ja&gl=JP&ceid=JP:ja";

    $xml = @file_get_contents($rss);
    if ($xml === false) return [];

    libxml_use_internal_errors(true);
    $obj = simplexml_load_string($xml);
    if (!$obj || !isset($obj->channel->item)) return [];

    if ($target_date) {
        $target_ts = strtotime($target_date . " 23:59:59");
    } else {
        $target_ts = null;
    }

    $items = [];
    foreach ($obj->channel->item as $item) {

        $pub = trim((string)$item->pubDate);
        $pub_ts = strtotime($pub);

        if ($target_ts && $pub_ts > $target_ts) {
            continue;
        }

        $items[] = [
            "title" => trim((string)$item->title),
            "link"  => trim((string)$item->link),
            "pubDate" => $pub,
        ];

        if (count($items) >= NEWS_LIMIT) break;
    }

    return $items;
}

/* =====================
   Prompt / Ollama
===================== */
function build_prompt($keyword, $news_items) {

    /* ★FIX: グローバルの $today を明示 */
    global $today;

    $lines = [];
    $i = 1;
    foreach ($news_items as $n) {
        $lines[] = $i.". ".$n["title"]." (".$n["pubDate"].")";
        $lines[] = "   ".$n["link"];
        $i++;
    }

    $news_text = implode("\n", $lines);

    return "
あなたはプロのラジオ構成作家です。
以下のニュース一覧を参考に、日本語のラジオ原稿本文だけを作ってください。

# 今日の日時
{$today}

# ニュース一覧
{$news_text}

# 条件
- 尺は2〜3分、600〜900文字
- 説明文・挨拶・見出し・URL禁止
- 人がそのまま読む本文のみ

# 開始文（改変禁止）
- {$keyword}に関するニュースです。
";
}

function ollama_generate_script($prompt) {

    $payload = [
        "model" => OLLAMA_MODEL,
        "prompt" => $prompt,
        "stream" => false,
        "options" => [
            "temperature" => 0.7,
        ],
    ];

    list($ok, $data) = http_post_json(OLLAMA_URL, $payload);
    if (!$ok || !isset($data["response"])) return "";
    return trim($data["response"]);
}

/* =====================
   キーワード読込
===================== */
$keywords = file(
    $keyword_file,
    FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
);

/* =====================
   API / cron 用 生成モード
===================== */
if (isset($_GET["generate"]) && $_GET["generate"] === "1") {

    if (!isset($_GET["token"]) || $_GET["token"] !== "秘密の文字列") {
        http_response_code(403);
        exit;
    }

    $target_date = isset($_GET["date"])
        ? $_GET["date"]
        : $today;

    foreach ($keywords as $keyword) {

        $json_file = $data_dir . "/" . $target_date . "_" . $keyword . ".json";
        if (file_exists($json_file)) continue;

        $news = fetch_google_news($keyword, $target_date);
        if (!$news) continue;

        $script = ollama_generate_script(
            build_prompt($keyword, $news)
        );

        file_put_contents(
            $json_file,
            json_encode([
                "date" => $target_date,
                "keyword" => $keyword,
                "generated_at" => date("Y-m-d H:i:s"),
                "radio_script" => $script,
                "news" => $news,
            ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    echo "OK";
    exit;
}

/* =====================
   台本保存処理
===================== */
if (isset($_POST["save_script"])) {

    $file = basename($_POST["json_file"]);
    $script = isset($_POST["radio_script"])
        ? trim($_POST["radio_script"])
        : "";

    $path = $data_dir . "/" . $file;

    if ($file !== "" && file_exists($path)) {
        $data = json_decode(file_get_contents($path), true);
        if (is_array($data)) {
            $data["radio_script"] = $script;
            $data["edited_at"] = date("Y-m-d H:i:s");

            file_put_contents(
                $path,
                json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            );
        }
    }
}

/* =====================
   キーワード保存処理
===================== */
if (isset($_POST["save_keywords"])) {

    if (isset($_POST["keywords_text"])) {

        $text = str_replace("\r\n", "\n", $_POST["keywords_text"]);
        $text = trim($text);

        if ($text !== "") {
            file_put_contents($keyword_file, $text . "\n");
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
.keyword{margin-bottom:40px}
.scroll{display:flex;gap:12px;overflow-x:auto;scroll-snap-type:x mandatory}
.card{min-width:420px;background:#111827;padding:14px;border-radius:12px;scroll-snap-align:start}
@media(max-width:600px){.card{min-width:100%}}
textarea{width:100%;height:220px;background:#020617;color:#e5e7eb;border:1px solid #334155;border-radius:10px;padding:10px}
button{margin-top:8px;padding:8px 14px;border-radius:10px;border:0;background:#2563eb;color:#fff}
.playall{background:#16a34a;margin-bottom:12px}
.date{font-size:12px;color:#94a3b8;margin-bottom:6px}
.title{font-weight:700}
.muted{font-size:13px;color:#94a3b8}
a{color:#38bdf8}
</style>
</head>
<body>
<img src="./images/aiknowledgecms_logo.png" width="30%" height="30%">
<form method="post" style="margin-bottom:40px">
    <h3 style="margin-bottom:12px">Keywords</h3>

    <div style="
        display:flex;
        gap:16px;
        align-items:stretch;
        background:#020617;
        border:1px solid #334155;
        border-radius:14px;
        padding:16px;
    ">
        <textarea
            name="keywords_text"
            style="
                width:200px;
                height:40px;
                background:#020617;
                color:#e5e7eb;
                border:1px solid #334155;
                border-radius:10px;
                padding:8px;
                font-family:monospace;
                resize:vertical;
            "
        ><?php echo h(file_get_contents($keyword_file)); ?></textarea>

        <div style="
            display:flex;
            gap:6px;
            align-items:center;
        ">
            <button
                type="submit"
                name="save_keywords"
                value="1"
                style="
                    padding:4px 10px;
                    font-size:12px;
                    border-radius:6px;
                    border:1px solid #2563eb;
                    background:#2563eb;
                    color:#fff;
                "
            >
                保存
            </button>

            <button
                type="button"
                onclick="location.href = location.pathname"
                style="
                    padding:4px 10px;
                    font-size:12px;
                    border-radius:6px;
                    border:1px solid #334155;
                    background:#020617;
                    color:#e5e7eb;
                    background:#2563eb;
                "
            >
                再表
            </button>
        </div>
    </div>
</form>

<button id="playAllBtn"
  style="margin-bottom:16px;padding:10px 16px;border-radius:10px;border:0;background:#16a34a;color:#fff">
▶ 全部再生
</button>


<?php foreach ($keywords as $keyword): ?>
<div class="keyword">
    <h2><?php echo h($keyword); ?></h2>

    <?php
        $prev_date = date("Y-m-d", strtotime($base_date." -1 day"));
        $next_date = date("Y-m-d", strtotime($base_date." +1 day"));
        $can_next  = ($next_date <= $today);
    ?>
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:8px">
        <a href="?base_date=<?php echo h($prev_date); ?>">←</a>
        <span><?php echo h($base_date); ?></span>
        <?php if ($can_next): ?>
            <a href="?base_date=<?php echo h($next_date); ?>">→</a>
        <?php else: ?>
            <span style="opacity:.3">→</span>
        <?php endif; ?>
    </div>

    <button class="playall" onclick="playAll(this)">
        ▶ このキーワードを一括再生
    </button>

    <div class="scroll">
    <?php
        $today_file = $data_dir."/".$today."_".$keyword.".json";

        if (!file_exists($today_file)) {
            $news = fetch_google_news($keyword, $today);
            $script = ollama_generate_script(
                build_prompt($keyword, $news)
            );

            file_put_contents(
                $today_file,
                json_encode([
                    "date"=>$today,
                    "keyword"=>$keyword,
                    "generated_at"=>date("Y-m-d H:i:s"),
                    "radio_script"=>$script,
                    "news"=>$news
                ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            );
        }

        $files = glob($data_dir."/*_".$keyword.".json");
        rsort($files);

        $filtered = [];
        foreach ($files as $f) {
            if (preg_match("/(\d{4}-\d{2}-\d{2})_".preg_quote($keyword,"/")."\.json$/", $f, $m)) {
                if ($m[1] <= $base_date) {
                    $filtered[] = $f;
                }
            }
        }

        $files = array_slice($filtered, 0, 10);

        foreach ($files as $file):
            $data = json_decode(file_get_contents($file), true);
            if (!$data) continue;
    ?>
        <div class="card">
            <div class="date"><?php echo h($data["date"]); ?></div>

            <?php if (isset($data["audio_file"]) && $data["audio_file"] !== ""): ?>
                <audio controls>
                    <source src="data/<?php echo h($data["audio_file"]); ?>" type="audio/wav">
                </audio>
            <?php endif; ?>

            <form method="post">
                <input type="hidden" name="json_file" value="<?php echo h(basename($file)); ?>">
                <textarea name="radio_script"><?php echo h($data["radio_script"]); ?></textarea>
                <button type="submit" name="save_script" value="1">保存</button>
            </form>

            <?php foreach ($data["news"] as $n): ?>
                <hr>
                <div class="title"><?php echo h($n["title"]); ?></div>
                <div class="muted"><?php echo h($n["pubDate"]); ?></div>
                <a href="<?php echo h($n["link"]); ?>" target="_blank">記事を開く</a>
            <?php endforeach; ?>
        </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endforeach; ?>

<script>
(function () {
  const btn = document.getElementById("playAllBtn");
  if (!btn) return;

  btn.addEventListener("click", () => {
    const items = Array.from(document.querySelectorAll(".card")).map(card => {
      const audio = card.querySelector("audio");
      if (!audio) return null;
      const dateEl = card.querySelector(".date");
      if (!dateEl) return null;
      const m = dateEl.textContent.match(/\d{4}-\d{2}-\d{2}/);
      if (!m) return null;
      return {
        audio: audio,
        date: m[0],
        order: Array.from(card.parentNode.children).indexOf(card)
      };
    }).filter(Boolean);

    if (!items.length) return;

    items.sort((a, b) => {
      if (a.date !== b.date) {
        return a.date < b.date ? 1 : -1;
      }
      return a.order - b.order;
    });

    let i = 0;
    const playNext = () => {
      if (i >= items.length) return;
      items[i].audio.currentTime = 0;
      items[i].audio.play();
      items[i].audio.onended = () => {
        i++;
        playNext();
      };
    };
    playNext();
  });
})();
</script>

<script>
function playAll(btn){
    var root = btn.closest('.keyword');
    var audios = root.querySelectorAll('audio');
    if(!audios.length) return;

    var i = 0;
    audios[i].play();

    audios[i].onended = function next(){
        i++;
        if(i < audios.length){
            audios[i].play();
            audios[i].onended = next;
        }
    };
}
</script>
</body>
</html>

