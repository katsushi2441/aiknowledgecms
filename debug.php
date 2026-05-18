<?php
// debug_path.php - aiknowledgecms.phpと同じディレクトリに置いて実行
date_default_timezone_set("Asia/Tokyo");

define("DATA_DIR", __DIR__ . "/data");

echo "<pre>\n";
echo "DATA_DIR: " . DATA_DIR . "\n";
echo "data/ exists: " . (file_exists(DATA_DIR) ? "YES" : "NO") . "\n\n";

// 1. data/直下のサブディレクトリ一覧
echo "=== data/ 直下のサブディレクトリ ===\n";
foreach (scandir(DATA_DIR) as $f) {
    if ($f === "." || $f === "..") { continue; }
    $path = DATA_DIR . "/" . $f;
    if (is_dir($path)) {
        $cnt = count(array_diff(scandir($path), array(".", "..")));
        echo "  [DIR] " . $f . "/ (" . $cnt . " files)\n";
    }
}

echo "\n";

// 2. 直近のyyyymmフォルダの先頭5ファイルを表示
echo "=== 最新yyyymmフォルダの先頭5ファイル ===\n";
$dirs = array();
foreach (scandir(DATA_DIR) as $f) {
    if ($f === "." || $f === "..") { continue; }
    if (is_dir(DATA_DIR . "/" . $f) && preg_match('/^\d{6}$/', $f)) {
        $dirs[] = $f;
    }
}
rsort($dirs); // 新しい順
if (!empty($dirs)) {
    $latest = $dirs[0];
    $latest_path = DATA_DIR . "/" . $latest;
    echo "対象フォルダ: " . $latest . "/\n";
    $files = array_diff(scandir($latest_path), array(".", ".."));
    $i = 0;
    foreach ($files as $f) {
        if ($i++ >= 5) { break; }
        echo "  " . $f . "\n";
    }
}

echo "\n";

// 3. get_json_path_for_date関数が存在するか確認
echo "=== aiknowledgecms.phpの関数チェック ===\n";
$cms = __DIR__ . "/aiknowledgecms.php";
if (file_exists($cms)) {
    $src = file_get_contents($cms);
    echo "get_json_path_for_date: " . (strpos($src, "get_json_path_for_date") !== false ? "あり" : "なし(古いファイル!)") . "\n";
    echo "get_data_dir_for_date: "  . (strpos($src, "get_data_dir_for_date")  !== false ? "あり" : "なし(古いファイル!)") . "\n";
} else {
    echo "aiknowledgecms.php が見つかりません\n";
}

echo "\n";

// 4. 実際のパス解決テスト
echo "=== パス解決テスト ===\n";
// 最新yyyymmフォルダから実在するファイルを1件取得してテスト
if (!empty($dirs)) {
    $latest = $dirs[0];
    $latest_path = DATA_DIR . "/" . $latest;
    $files = array_values(array_diff(scandir($latest_path), array(".", "..")));
    if (!empty($files)) {
        $sample_file = $files[0];
        // ファイル名から日付とキーワードを抽出
        if (preg_match('/^(\d{4}-\d{2}-\d{2})_(.+)\.json$/', $sample_file, $m)) {
            $date = $m[1];
            $kw   = $m[2];
            $ym   = date("Ym", strtotime($date));

            $new_path = DATA_DIR . "/" . $ym . "/" . $date . "_" . $kw . ".json";
            $old_path = DATA_DIR . "/" . $date . "_" . $kw . ".json";

            echo "サンプルファイル: " . $sample_file . "\n";
            echo "日付: " . $date . " / キーワード: " . $kw . "\n";
            echo "yyyymm: " . $ym . "\n";
            echo "new_path: " . $new_path . "\n";
            echo "new exists: " . (file_exists($new_path) ? "YES" : "NO") . "\n";
            echo "old_path: " . $old_path . "\n";
            echo "old exists: " . (file_exists($old_path) ? "YES" : "NO") . "\n";
        }
    }
}

echo "\n";

// 5. keyword.jsonチェック
echo "=== keyword.json チェック ===\n";
$kj = __DIR__ . "/keyword.json";
echo "keyword.json exists: " . (file_exists($kj) ? "YES" : "NO") . "\n";
if (file_exists($kj)) {
    $json = json_decode(file_get_contents($kj), true);
    $cnt = isset($json["keywords"]) ? count($json["keywords"]) : 0;
    echo "keyword数: " . $cnt . "\n";
    if ($cnt > 0) {
        $keys = array_keys($json["keywords"]);
        echo "先頭3件: " . implode(", ", array_slice($keys, 0, 3)) . "\n";
    }
}

echo "</pre>\n";
