<?php
require_once dirname(__DIR__) . '/auth_common.php';
date_default_timezone_set('Asia/Tokyo');

url2ai_auth_handle_login_flow('/swork/index.php');
$auth = url2ai_auth_bootstrap();
$logged_in = $auth['logged_in'];
$username = $auth['session_user'];
$is_admin = $auth['is_admin'];

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function swork_leads_file() {
    $candidates = array(
        __DIR__ . '/leads.csv',
        dirname(__DIR__) . '/work/swork_leads.csv',
    );
    foreach ($candidates as $file) if (is_file($file)) return $file;
    return '';
}

function swork_load_leads() {
    $file = swork_leads_file();
    if ($file === '') return array();
    $fp = fopen($file, 'r');
    if (!$fp) return array();
    $header = fgetcsv($fp);
    if (!$header) return array();
    $items = array();
    while (($row = fgetcsv($fp)) !== false) {
        $item = array();
        foreach ($header as $i => $key) $item[$key] = isset($row[$i]) ? $row[$i] : '';
        $items[] = $item;
    }
    fclose($fp);
    return $items;
}

function swork_find_lead($leads, $id) {
    foreach ($leads as $lead) {
        if (isset($lead['id']) && $lead['id'] === $id) return $lead;
    }
    return count($leads) ? $leads[0] : array();
}

function swork_outreach_subject($lead) {
    return 'バイブコーディングによる手入力削減・システム内製化のご提案';
}

function swork_outreach_body($lead) {
    $company = isset($lead['company_name']) ? $lead['company_name'] : '';
    $hypothesis = isset($lead['hypothesis']) ? $lead['hypothesis'] : '';
    return $company . "\nご担当者様\n\n突然のご連絡失礼いたします。株式会社エクスブリッジです。\n\n弊社では、バイブコーディングを活用して、FAX発注書、見積依頼、型番入力、商品マスタ照合など、パソコン上の手入力作業を減らすシステム開発を支援しています。\n\n" . $hypothesis . "\n\n既存SaaSを契約し続ける形ではなく、業務に合わせた仕組みを自社資産として持ち、社内で育てていけることを重視しています。\n\nバイブコーディングセミナーとVWorkの導入支援により、外部委託に頼りきらない業務改善・システム内製化も支援できます。\n\nもしご関心がありましたら、短時間で現状業務を伺い、どこを自動化できるか整理いたします。\n\n今後のご案内が不要な場合は、その旨ご返信ください。\n\n株式会社エクスブリッジ\nsales@exbridge.jp\nhttps://exbridge.jp/";
}

function swork_filter_leads($leads, $q) {
    $q = trim($q);
    if ($q === '') return $leads;
    $out = array();
    foreach ($leads as $lead) {
        $haystack = implode(' ', $lead);
        if (mb_stripos($haystack, $q, 0, 'UTF-8') !== false) $out[] = $lead;
    }
    return $out;
}

$all_leads = swork_load_leads();
$query = isset($_GET['q']) ? trim((string)$_GET['q']) : '';
$leads = swork_filter_leads($all_leads, $query);
$selected_id = isset($_GET['id']) ? (string)$_GET['id'] : '';
$selected = $selected_id !== '' ? swork_find_lead($all_leads, $selected_id) : (count($leads) ? $leads[0] : array());
$form_url = isset($selected['contact_form_url']) && $selected['contact_form_url'] !== '' ? $selected['contact_form_url'] : (isset($selected['website_url']) ? $selected['website_url'] : '');
$subject = $selected ? swork_outreach_subject($selected) : '';
$body = $selected ? swork_outreach_body($selected) : '';
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SWork</title>
<style>
body{margin:0;background:#f4f6f6;color:#111;font-family:-apple-system,BlinkMacSystemFont,"Hiragino Sans",Meiryo,sans-serif;line-height:1.6}.top{background:#fff;border-bottom:1px solid #ddd}.wrap{max-width:1280px;margin:0 auto;padding:18px 16px}.bar{display:flex;align-items:center;justify-content:space-between;gap:12px}.brand{font-weight:800;color:#111;text-decoration:none}.nav{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:34px;padding:6px 10px;border:1px solid #cfd6d3;background:#fff;border-radius:4px;color:#111;text-decoration:none;cursor:pointer;font-size:13px}.primary{background:#0f766e;color:#fff;border-color:#0f766e}.grid{display:grid;grid-template-columns:minmax(360px,1fr) minmax(420px,1.1fr);gap:14px}.panel{background:#fff;border:1px solid #d9dfdc;border-radius:6px;overflow:hidden}.panel-h{padding:12px 14px;border-bottom:1px solid #e7ece9;display:flex;align-items:center;justify-content:space-between;gap:10px}.panel-b{padding:12px 14px}.search{display:flex;gap:8px}.search input{flex:1;min-height:34px;border:1px solid #cfd6d3;border-radius:4px;padding:6px 8px}.table-wrap{overflow:auto;max-height:72vh}table{width:100%;border-collapse:collapse;font-size:13px}th,td{padding:9px 10px;border-bottom:1px solid #eef1ef;text-align:left;vertical-align:top}th{position:sticky;top:0;background:#fbfcfc;z-index:1}.company{font-weight:800}.muted{color:#66706b;font-size:12px}.tag{display:inline-flex;padding:2px 7px;border:1px solid #d9dfdc;border-radius:999px;font-size:11px;color:#44504a;background:#f8faf9}.selected{background:#ecfdf5}.detail{display:grid;gap:12px}.kv{display:grid;grid-template-columns:100px 1fr;gap:8px;font-size:13px}.copybox{width:100%;min-height:180px;box-sizing:border-box;border:1px solid #cfd6d3;border-radius:4px;padding:10px;font-family:inherit;font-size:13px;line-height:1.6}.subject{min-height:34px}.frame{width:100%;height:54vh;border:1px solid #cfd6d3;border-radius:4px;background:#fff}.empty{padding:20px;color:#66706b}.tools{display:flex;gap:8px;flex-wrap:wrap}@media(max-width:900px){.grid{grid-template-columns:1fr}.table-wrap{max-height:none}.frame{height:60vh}}
</style>
</head>
<body>
<header class="top"><div class="wrap bar"><a class="brand" href="index.php">SWork</a><div class="nav"><a class="btn" href="inbox.php">Inbox</a><?php if($logged_in): ?>@<?php echo h($username); ?> <a class="btn" href="<?php echo h($auth['logout_url']); ?>">logout</a><?php else: ?><a class="btn primary" href="<?php echo h($auth['login_url']); ?>">Xでログイン</a><?php endif; ?></div></div></header>
<main class="wrap">
<?php if(!$logged_in): ?>
<div class="panel"><div class="empty">X認証でログインしてください。</div></div>
<?php elseif(!$is_admin): ?>
<div class="panel"><div class="empty">管理者のみ閲覧できます。</div></div>
<?php else: ?>
<div class="grid">
<section class="panel">
  <div class="panel-h">
    <strong>ターゲット顧客リスト</strong>
    <span class="muted"><?php echo h(count($leads)); ?> / <?php echo h(count($all_leads)); ?>件</span>
  </div>
  <div class="panel-b">
    <form class="search" method="get"><input name="q" value="<?php echo h($query); ?>" placeholder="会社名・住所・業種で検索"><button class="btn primary">検索</button></form>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>会社</th><th>連絡先</th><th>状態</th><th></th></tr></thead>
      <tbody>
      <?php foreach($leads as $lead): $is_sel = isset($selected['id'], $lead['id']) && $selected['id'] === $lead['id']; ?>
      <tr class="<?php echo $is_sel ? 'selected' : ''; ?>">
        <td><div class="company"><?php echo h($lead['company_name']); ?></div><div class="muted"><?php echo h($lead['branch']); ?> / <?php echo h($lead['address']); ?></div></td>
        <td><div><?php echo h($lead['phone']); ?></div><div class="muted"><?php echo h($lead['website_url']); ?></div></td>
        <td><span class="tag"><?php echo h($lead['status']); ?></span></td>
        <td><a class="btn" href="?id=<?php echo urlencode($lead['id']); ?><?php echo $query !== '' ? '&q=' . urlencode($query) : ''; ?>">選択</a></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<section class="panel">
  <div class="panel-h">
    <strong><?php echo h(isset($selected['company_name']) ? $selected['company_name'] : '詳細'); ?></strong>
    <div class="tools">
      <?php if($form_url): ?><a class="btn primary" href="<?php echo h($form_url); ?>" target="_blank" rel="noopener">フォームを開く</a><?php endif; ?>
      <button class="btn" type="button" onclick="copyBody()">本文コピー</button>
    </div>
  </div>
  <div class="panel-b detail">
    <?php if(!$selected): ?>
    <div class="empty">リードCSVがありません。</div>
    <?php else: ?>
    <div class="kv"><div class="muted">サイト</div><div><?php if($form_url): ?><a href="<?php echo h($form_url); ?>" target="_blank" rel="noopener"><?php echo h($form_url); ?></a><?php endif; ?></div></div>
    <div class="kv"><div class="muted">仮説</div><div><?php echo h($selected['hypothesis']); ?></div></div>
    <input class="copybox subject" id="subject" value="<?php echo h($subject); ?>">
    <textarea class="copybox" id="body"><?php echo h($body); ?></textarea>
    <?php if($form_url): ?>
    <iframe class="frame" src="<?php echo h($form_url); ?>"></iframe>
    <?php else: ?>
    <div class="empty">問い合わせフォームURL未登録です。公式サイトから確認してください。</div>
    <?php endif; ?>
    <?php endif; ?>
  </div>
</section>
</div>
<?php endif; ?>
</main>
<script>
function copyBody(){
  const body = document.getElementById('body');
  if (!body) return;
  body.select();
  document.execCommand('copy');
}
</script>
</body>
</html>
