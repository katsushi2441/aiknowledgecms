<?php
// ================================================
// AIGMAdEngine - 広告ウィジェット配信 (JSONP)
// ================================================
// 使い方:
// <div id="aigm-ad"></div>
// <script src="https://aiknowledgecms.exbridge.jp/ad_widget.php?callback=aigmAd&slot=sidebar&limit=3&kw=AI"></script>
// <script>
// function aigmAd(data) {
//   // data.html をそのまま埋め込む
//   document.getElementById('aigm-ad').innerHTML = data.html;
// }
// </script>
// ================================================

header('Content-Type: application/javascript; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-cache, no-store, must-revalidate');

$callback = isset($_GET['callback']) ? preg_replace('/[^a-zA-Z0-9_$]/', '', $_GET['callback']) : 'aigmAd';
$slot     = isset($_GET['slot'])     ? trim($_GET['slot'])     : 'default';
$kw       = isset($_GET['kw'])       ? trim($_GET['kw'])       : '';
$limit_pc = isset($_GET['limit'])    ? intval($_GET['limit'])  : 0; // 0=無制限
$assoc_override = isset($_GET['tag']) ? trim($_GET['tag'])     : '';

// ---- データ読み込み ----
$admin_file = __DIR__ . '/data/admin_associate.json';
$data = array('associate_id' => '', 'items' => array());
if (file_exists($admin_file)) {
    $raw = json_decode(file_get_contents($admin_file), true);
    if (is_array($raw)) { $data = $raw; }
}

// アソシエイトID（keyword_xb_bittensor.jsonを優先）
$assoc_id = '';
$kf = __DIR__ . '/data/keyword_xb_bittensor.json';
if (file_exists($kf)) {
    $kdata = json_decode(file_get_contents($kf), true);
    if (isset($kdata['associate_id']) && $kdata['associate_id'] !== '') {
        $assoc_id = $kdata['associate_id'];
    }
}
if (!$assoc_id && isset($data['associate_id'])) { $assoc_id = $data['associate_id']; }
if ($assoc_override) { $assoc_id = $assoc_override; }

// ---- 商品フィルタリング ----
$all_items = isset($data['items']) ? $data['items'] : array();

// activeのみ
$active_items = array();
foreach ($all_items as $item) {
    $active = isset($item['active']) ? $item['active'] : true;
    if (!$active) { continue; }

    // デバイスフィルタは後でJS側で処理するためここでは通す
    // キーワード一致で優先度スコアを付ける
    $score = 0;
    if ($kw && isset($item['keywords']) && is_array($item['keywords'])) {
        foreach ($item['keywords'] as $k) {
            if (mb_strpos($kw, $k, 0, 'UTF-8') !== false || mb_strpos($k, $kw, 0, 'UTF-8') !== false) {
                $score += 10;
            }
        }
    }
    $item['_score']   = $score;
    $item['_priority'] = isset($item['priority']) ? $item['priority'] : 'random';
    $item['_weight']   = isset($item['weight'])   ? intval($item['weight']) : 5;
    $item['_device']   = isset($item['device'])   ? $item['device'] : 'all';
    $active_items[] = $item;
}

// fixed優先、その後weightでランダム抽選
$fixed_items  = array();
$random_items = array();
foreach ($active_items as $item) {
    if ($item['_priority'] === 'fixed') {
        $fixed_items[] = $item;
    } else {
        $random_items[] = $item;
    }
}

// スコア降順でfixedをソート
usort($fixed_items, function($a, $b) {
    return $b['_score'] - $a['_score'];
});

// weightに基づいてrandomをシャッフル（重み付き）
$weighted_pool = array();
foreach ($random_items as $item) {
    $w = max(1, $item['_weight']);
    for ($i = 0; $i < $w; $i++) {
        $weighted_pool[] = $item;
    }
}
shuffle($weighted_pool);

// 重複除去
$seen   = array();
$random_deduped = array();
foreach ($weighted_pool as $item) {
    $asin = isset($item['asin']) ? $item['asin'] : $item['url'];
    if (isset($seen[$asin])) { continue; }
    $seen[$asin] = true;
    $random_deduped[] = $item;
}

// fixed + random を結合
$merged = array_merge($fixed_items, $random_deduped);

// ---- HTML生成関数 ----
function build_item_html($item, $assoc_id, $template) {
    $url   = isset($item['url'])   ? $item['url']   : '';
    $title = isset($item['title']) ? $item['title'] : '';
    $pr    = isset($item['pr_comment']) ? $item['pr_comment'] : '';
    $asin  = isset($item['asin'])  ? $item['asin']  : '';

    // アソシエイトタグ付与
    if ($assoc_id && $url) {
        $url .= (strpos($url, '?') !== false ? '&' : '?') . 'tag=' . rawurlencode($assoc_id);
    }

    $title_esc = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $url_esc   = htmlspecialchars($url,   ENT_QUOTES, 'UTF-8');
    $pr_esc    = htmlspecialchars($pr,    ENT_QUOTES, 'UTF-8');

    if ($template === 'text') {
        return '<a href="' . $url_esc . '" target="_blank" rel="noopener" class="aigm-card aigm-text">'
             . '<span class="aigm-title">' . $title_esc . '</span>'
             . '<span class="aigm-cta">Amazonで見る →</span>'
             . '</a>';
    } elseif ($template === 'text_comment' && $pr) {
        return '<a href="' . $url_esc . '" target="_blank" rel="noopener" class="aigm-card aigm-text-comment">'
             . '<span class="aigm-title">' . $title_esc . '</span>'
             . '<span class="aigm-pr">' . $pr_esc . '</span>'
             . '<span class="aigm-cta">Amazonで見る →</span>'
             . '</a>';
    } else {
        // デフォルト：text_commentと同じ
        return '<a href="' . $url_esc . '" target="_blank" rel="noopener" class="aigm-card aigm-text">'
             . '<span class="aigm-title">' . $title_esc . '</span>'
             . '<span class="aigm-cta">Amazonで見る →</span>'
             . '</a>';
    }
}

// CSS（media queryでPC/SP切り替え、scriptなし）
$css = '
<style id="aigm-style">
.aigm-widget { font-family: sans-serif; box-sizing: border-box; }
.aigm-label { font-size: 11px; color: #888; letter-spacing: .1em; margin-bottom: 8px; }
.aigm-grid {
  display: flex;
  gap: 10px;
  overflow-x: auto;
  padding-bottom: 6px;
  scrollbar-width: thin;
}
.aigm-grid::-webkit-scrollbar { height: 4px; }
.aigm-grid::-webkit-scrollbar-thumb { background: #ccc; border-radius: 2px; }
.aigm-card {
  display: flex;
  flex-direction: column;
  gap: 5px;
  background: #fff;
  border: 1px solid #e0e0e0;
  border-radius: 6px;
  padding: 10px 12px;
  text-decoration: none;
  color: inherit;
  transition: border-color .15s, box-shadow .15s;
  min-width: 160px;
  max-width: 220px;
  flex-shrink: 0;
}
@media (max-width: 768px) {
  .aigm-grid { flex-direction: column; overflow-x: visible; }
  .aigm-grid .aigm-card { max-width: 100%; min-width: unset; }
  .aigm-grid .aigm-card:nth-child(n+3) { display: none; }
}
.aigm-card:hover { border-color: #f90; box-shadow: 0 2px 8px rgba(255,153,0,.18); }
.aigm-title { font-size: 12px; color: #222; line-height: 1.5;
  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.aigm-pr { font-size: 11px; color: #555; line-height: 1.55;
  display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.aigm-cta { font-size: 11px; color: #f90; font-weight: bold; margin-top: 2px; }
.aigm-disclaimer { font-size: 10px; color: #aaa; margin-top: 6px; }
</style>';

// 表示アイテム生成（device filterのみ、PC/SP分離なし）
$html_items = array();
foreach ($merged as $item) {
    $template  = isset($item['template']) ? $item['template'] : 'text_comment';
    $html_items[] = build_item_html($item, $assoc_id, $template);
}
if ($limit_pc > 0) {
    $html_items = array_slice($html_items, 0, $limit_pc);
}

if (empty($html_items)) {
    $output = '';
} else {
    $items_html = implode('', $html_items);
    $output = $css . '
<div class="aigm-widget">
  <div class="aigm-label">◯ AMAZON おすすめ</div>
  <div class="aigm-grid">' . $items_html . '</div>
  <div class="aigm-disclaimer">※ Amazonアソシエイト・プログラム参加。購入でサイト運営者に報酬が発生する場合があります。</div>
</div>';
}

// JSONPで返す
$json = json_encode(array(
    'ok'    => true,
    'html'  => $output,
    'count' => count($html_items),
), JSON_UNESCAPED_UNICODE);

echo $callback . '(' . $json . ');';
exit;
