<?php
/**
 * migrate.php
 * 旧ファイル名 → 新ファイル名 変換
 *
 * 旧: shortstory_{id}.json, xview_{id}.json
 * 新: xinsight_{id}.json
 *
 * 実行後は削除してください。
 */

$DATA_DIR = __DIR__ . '/data';
$results  = array();
$errors   = array();

$patterns = array(
    'shortstory_' => 'xinsight_',
    'xview_'      => 'xinsight_',
    'ustory_'     => 'xinsight_',
);

foreach ($patterns as $old_prefix => $new_prefix) {
    $files = glob($DATA_DIR . '/' . $old_prefix . '*.json');
    if (!$files) { continue; }
    foreach ($files as $old_path) {
        $old_base = basename($old_path);
        $new_base = $new_prefix . substr($old_base, strlen($old_prefix));
        $new_path = $DATA_DIR . '/' . $new_base;

        if (file_exists($new_path)) {
            /* 新ファイルが既に存在する場合はマージ（storyキーをxinsightにコピー） */
            $old_data = json_decode(file_get_contents($old_path), true);
            $new_data = json_decode(file_get_contents($new_path), true);
            if (is_array($old_data) && is_array($new_data)) {
                /* ustory/shortstoryのstoryキーをinsightとして保存 */
                if (!empty($old_data['story']) && empty($new_data['insight'])) {
                    $new_data['insight'] = $old_data['story'];
                    file_put_contents($new_path, json_encode($new_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                }
                unlink($old_path);
                $results[] = array('status' => 'merged', 'from' => $old_base, 'to' => $new_base);
            } else {
                $errors[] = array('file' => $old_base, 'reason' => 'JSON parse error');
            }
        } else {
            /* 新ファイルが存在しない場合はリネーム */
            $data = json_decode(file_get_contents($old_path), true);
            if (!is_array($data)) {
                $errors[] = array('file' => $old_base, 'reason' => 'JSON parse error');
                continue;
            }
            /* storyキーをinsightに統一 */
            if (!empty($data['story']) && empty($data['insight'])) {
                $data['insight'] = $data['story'];
            }
            if (file_put_contents($new_path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT))) {
                unlink($old_path);
                $results[] = array('status' => 'renamed', 'from' => $old_base, 'to' => $new_base);
            } else {
                $errors[] = array('file' => $old_base, 'reason' => 'write failed');
            }
        }
    }
}
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Migrate</title>
<style>
body { font-family: monospace; background: #f8fafc; color: #1e293b; padding: 2rem; font-size: 14px; }
h1 { font-size: 1.1rem; margin-bottom: 1.5rem; }
table { border-collapse: collapse; width: 100%; margin-bottom: 2rem; }
th, td { border: 1px solid #e2e8f0; padding: 6px 12px; text-align: left; }
th { background: #f1f5f9; }
.renamed { color: #0369a1; }
.merged  { color: #7c3aed; }
.err     { color: #dc2626; }
.none    { color: #94a3b8; }
</style>
</head>
<body>
<h1>Migration Result</h1>

<?php if (empty($results) && empty($errors)): ?>
<p class="none">対象ファイルがありませんでした。</p>
<?php else: ?>

<table>
<tr><th>status</th><th>from</th><th>to</th></tr>
<?php foreach ($results as $r): ?>
<tr>
    <td class="<?php echo $r['status']; ?>"><?php echo $r['status']; ?></td>
    <td><?php echo htmlspecialchars($r['from']); ?></td>
    <td><?php echo htmlspecialchars($r['to']); ?></td>
</tr>
<?php endforeach; ?>
<?php foreach ($errors as $e): ?>
<tr>
    <td class="err">error</td>
    <td><?php echo htmlspecialchars($e['file']); ?></td>
    <td class="err"><?php echo htmlspecialchars($e['reason']); ?></td>
</tr>
<?php endforeach; ?>
</table>

<p>完了: <?php echo count($results); ?> 件処理 / <?php echo count($errors); ?> 件エラー</p>
<p style="margin-top:1rem;color:#94a3b8;font-size:.85rem;">※ このファイルは実行後に削除してください。</p>
<?php endif; ?>
</body>
</html>
