<?php
// ================================================
// AIGMAdEngine - 広告エンジン管理画面
// admin (xb_bittensor) 専用
// ================================================

// ------------------------------------------------
// API: ASIN取得（aiknowledgesns.phpから流用）
// ------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'fetch_asin') {
    header('Content-Type: application/json; charset=UTF-8');
    $asin = isset($_GET['asin']) ? preg_replace('/[^A-Z0-9]/', '', strtoupper(trim($_GET['asin']))) : '';
    if (!$asin || strlen($asin) !== 10) {
        echo json_encode(array('ok' => false, 'reason' => 'invalid_asin'));
        exit;
    }
    $url  = 'https://www.amazon.co.jp/dp/' . $asin;
    $opts = array('http' => array(
        'method'        => 'GET',
        'header'        => implode("\r\n", array(
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept-Language: ja,en;q=0.9',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        )),
        'timeout'       => 12,
        'ignore_errors' => true,
    ));
    $html  = @file_get_contents($url, false, stream_context_create($opts));
    $title = '';
    if ($html) {
        if (preg_match("/<span[^>]+id=[\"']productTitle[\"'][^>]*>\\s*(.*?)\\s*<\\/span>/s", $html, $m)) {
            $title = html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8');
            $title = trim(preg_replace('/\s+/', ' ', $title));
        }
        if (!$title && preg_match('/<title>([^<]+)<\/title>/', $html, $m2)) {
            $t     = html_entity_decode($m2[1], ENT_QUOTES, 'UTF-8');
            $t     = preg_replace('/\s*[:\|].*Amazon.*$/u', '', $t);
            $title = trim($t);
        }
    }
    if ($title) {
        echo json_encode(array('ok' => true, 'asin' => $asin, 'title' => $title, 'url' => $url), JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(array('ok' => false, 'reason' => 'title_not_found', 'url' => $url));
    }
    exit;
}


// ------------------------------------------------
// API: メーカー名+型番からASIN候補検索
// ------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'search_asin') {
    header('Content-Type: application/json; charset=UTF-8');
    $q = isset($_GET['q']) ? trim($_GET['q']) : '';
    if (!$q) {
        echo json_encode(array('ok' => false, 'reason' => 'no_query'));
        exit;
    }
    $search_url = 'https://www.amazon.co.jp/s?' . http_build_query(array('k' => $q, 'lang' => 'ja_JP'));
    $opts = array('http' => array(
        'method'        => 'GET',
        'header'        => implode("\r\n", array(
            'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            'Accept-Language: ja,en-US;q=0.9',
            'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
        )),
        'timeout'       => 15,
        'ignore_errors' => true,
    ));
    $html = @file_get_contents($search_url, false, stream_context_create($opts));
    $candidates = array();
    if ($html) {
        preg_match_all('/data-asin="([A-Z0-9]{10})"[^>]*data-component-type="s-search-result"/', $html, $asin_matches);
        $asins = array();
        if (!empty($asin_matches[1])) {
            foreach ($asin_matches[1] as $a) {
                if (!in_array($a, $asins)) { $asins[] = $a; }
                if (count($asins) >= 100) { break; }
            }
        }
        $dp_opts = array('http' => array(
            'method'        => 'GET',
            'header'        => implode("\r\n", array(
                'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                'Accept-Language: ja,en-US;q=0.9',
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
            )),
            'timeout'       => 10,
            'ignore_errors' => true,
        ));
        foreach ($asins as $asin) {
            $title = '';
            // 商品ページから直接タイトル取得
            $dp_url  = 'https://www.amazon.co.jp/dp/' . $asin;
            $dp_html = @file_get_contents($dp_url, false, stream_context_create($dp_opts));
            if ($dp_html) {
                if (preg_match("/<span[^>]+id=[\"\']productTitle[\"\'][^>]*>\\s*(.*?)\\s*<\/span>/s", $dp_html, $tm)) {
                    $title = html_entity_decode(strip_tags($tm[1]), ENT_QUOTES, 'UTF-8');
                    $title = trim(preg_replace('/\\s+/', ' ', $title));
                }
                if (!$title && preg_match('/<title>([^<]+)<\/title>/', $dp_html, $tm2)) {
                    $t = html_entity_decode($tm2[1], ENT_QUOTES, 'UTF-8');
                    $t = preg_replace('/\\s*[:\|].*Amazon.*$/u', '', $t);
                    $title = trim($t);
                }
            }
            if (!$title) { $title = '(タイトル取得失敗) ' . $asin; }
            $candidates[] = array(
                'asin'  => $asin,
                'title' => mb_substr($title, 0, 120, 'UTF-8'),
                'url'   => $dp_url,
            );
            usleep(300000); // 0.3秒待機
        }
    }
    echo json_encode(array('ok' => true, 'query' => $q, 'candidates' => $candidates, 'count' => count($candidates)), JSON_UNESCAPED_UNICODE);
    exit;
}

// ------------------------------------------------
// API: 商品リスト取得
// ------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'get_items') {
    header('Content-Type: application/json; charset=UTF-8');
    $admin_file = __DIR__ . '/data/admin_associate.json';
    $data = array('associate_id' => '', 'items' => array());
    if (file_exists($admin_file)) {
        $raw = json_decode(file_get_contents($admin_file), true);
        if (is_array($raw)) { $data = $raw; }
    }
    // keyword_xb_bittensor.json からアソシエイトIDを取得（優先）
    $kf = __DIR__ . '/data/keyword_xb_bittensor.json';
    if (file_exists($kf)) {
        $kdata = json_decode(file_get_contents($kf), true);
        if (isset($kdata['associate_id']) && $kdata['associate_id'] !== '') {
            $data['associate_id'] = $kdata['associate_id'];
        }
    }
    // activeフィールドがない既存商品にデフォルト値を補完
    if (isset($data['items']) && is_array($data['items'])) {
        $fixed = array();
        foreach ($data['items'] as $item) {
            if (!isset($item['active']))   { $item['active']   = true; }
            if (!isset($item['priority'])) { $item['priority'] = 'random'; }
            if (!isset($item['weight']))   { $item['weight']   = 5; }
            if (!isset($item['device']))   { $item['device']   = 'all'; }
            if (!isset($item['template'])) { $item['template'] = 'text_comment'; }
            if (!isset($item['keywords'])) { $item['keywords'] = array(); }
            $fixed[] = $item;
        }
        $data['items'] = $fixed;
    }
    echo json_encode(array('ok' => true, 'data' => $data), JSON_UNESCAPED_UNICODE);
    exit;
}

// ------------------------------------------------
// API: 商品リスト保存
// ------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'save_items') {
    header('Content-Type: application/json; charset=UTF-8');
    session_start();
    $su = isset($_SESSION['session_username']) ? $_SESSION['session_username'] : '';
    if ($su !== 'xb_bittensor') {
        echo json_encode(array('ok' => false, 'reason' => 'unauthorized'));
        exit;
    }
    $body = file_get_contents('php://input');
    $req  = json_decode($body, true);
    if (!is_array($req)) {
        echo json_encode(array('ok' => false, 'reason' => 'invalid_json'));
        exit;
    }
    $assoc_id = isset($req['associate_id']) ? trim($req['associate_id']) : '';
    $items    = isset($req['items']) ? $req['items'] : array();
    $clean    = array();
    foreach ($items as $item) {
        $url   = isset($item['url'])   ? trim($item['url'])   : '';
        $title = isset($item['title']) ? trim($item['title']) : '';
        if (!$url || !$title) { continue; }
        // ASINをURLから抽出
        $asin = '';
        if (preg_match('/\/dp\/([A-Z0-9]{10})/i', $url, $am)) { $asin = strtoupper($am[1]); }
        if (!$asin && isset($item['asin'])) { $asin = preg_replace('/[^A-Z0-9]/', '', strtoupper($item['asin'])); }
        $clean[] = array(
            'url'         => $url,
            'title'       => $title,
            'asin'        => $asin,
            'image_url'   => isset($item['image_url'])   ? trim($item['image_url'])   : '',
            'pr_comment'  => isset($item['pr_comment'])  ? trim($item['pr_comment'])  : '',
            'article'     => isset($item['article'])     ? trim($item['article'])     : '',
            'x_post'      => isset($item['x_post'])      ? trim($item['x_post'])      : '',
            'template'    => isset($item['template'])    ? trim($item['template'])    : 'text_comment',
            'priority'    => isset($item['priority'])    ? trim($item['priority'])    : 'random',
            'weight'      => isset($item['weight'])      ? intval($item['weight'])    : 5,
            'device'      => isset($item['device'])      ? trim($item['device'])      : 'all',
            'active'      => isset($item['active'])      ? (bool)$item['active']      : true,
            'keywords'    => isset($item['keywords']) && is_array($item['keywords']) ? $item['keywords'] : array(),
        );
    }
    $admin_file = __DIR__ . '/data/admin_associate.json';
    file_put_contents($admin_file, json_encode(array(
        'associate_id' => $assoc_id,
        'items'        => $clean,
    ), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo json_encode(array('ok' => true, 'count' => count($clean)));
    exit;
}

// ------------------------------------------------
// API: Ollama PRコメント生成
// ------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'gen_pr') {
    header('Content-Type: application/json; charset=UTF-8');
    session_start();
    $su = isset($_SESSION['session_username']) ? $_SESSION['session_username'] : '';
    if ($su !== 'xb_bittensor') {
        echo json_encode(array('ok' => false, 'reason' => 'unauthorized'));
        exit;
    }
    $body  = file_get_contents('php://input');
    $req   = json_decode($body, true);
    $title = isset($req['title'])    ? trim($req['title'])    : '';
    $type  = isset($req['type'])     ? trim($req['type'])     : 'pr_comment';
    $kws   = isset($req['keywords']) ? $req['keywords']       : array();
    if (!$title) {
        echo json_encode(array('ok' => false, 'reason' => 'no_title'));
        exit;
    }
    $kw_str = !empty($kws) ? implode('、', $kws) : '';
    if ($type === 'pr_comment') {
        $prompt = "以下の商品について、購買意欲を高める魅力的なPRコメントを日本語で100文字以内で書いてください。評価や感想ではなく、商品の特徴と価値を簡潔に伝えてください。\n商品名：{$title}" . ($kw_str ? "\nキーワード：{$kw_str}" : '') . "\n\nPRコメントのみ出力してください。以上";
    } elseif ($type === 'article') {
        $prompt = "以下の商品について、Amazonアソシエイトの商品紹介ページ用の紹介記事を日本語で300文字程度で書いてください。商品の特徴・用途・おすすめポイントを含めてください。\n商品名：{$title}" . ($kw_str ? "\nキーワード：{$kw_str}" : '') . "\n\n紹介記事のみ出力してください。以上";
    } elseif ($type === 'x_post') {
        $prompt = "以下の商品をXでシェアするための投稿文を日本語で100文字以内で書いてください。商品URL欄は[URL]とプレースホルダーにしてください。" . ($kw_str ? "ハッシュタグは以下のキーワードを使ってください：{$kw_str}" : '') . "\n商品名：{$title}\n\n投稿文のみ出力してください。以上";
    } else {
        echo json_encode(array('ok' => false, 'reason' => 'invalid_type'));
        exit;
    }
    $ollama_url = 'https://exbridge.ddns.net/api/generate';
    $ollama_req = json_encode(array(
        'model'  => 'gemma4:e4b',
        'prompt' => $prompt,
        'stream' => false,
    ), JSON_UNESCAPED_UNICODE);
    $opts = array('http' => array(
        'method'        => 'POST',
        'header'        => "Content-Type: application/json\r\n",
        'content'       => $ollama_req,
        'timeout'       => 60,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents($ollama_url, false, stream_context_create($opts));
    if (!$res) {
        echo json_encode(array('ok' => false, 'reason' => 'ollama_error'));
        exit;
    }
    $json = json_decode($res, true);
    $text = isset($json['response']) ? trim($json['response']) : '';
    // 末尾の「以上」を除去
    $text = preg_replace('/以上\s*$/u', '', $text);
    $text = trim($text);
    echo json_encode(array('ok' => true, 'text' => $text, 'type' => $type), JSON_UNESCAPED_UNICODE);
    exit;
}

// ------------------------------------------------
// セッション・admin判定
// ------------------------------------------------
session_start();
$su        = isset($_SESSION['session_username']) ? $_SESSION['session_username'] : '';
$is_admin  = ($su === 'xb_bittensor');
$sns_url   = 'https://aiknowledgecms.exbridge.jp/aiknowledgesns.php';

?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>AIGMAdEngine</title>
<style>
/* ---- base ---- */
:root {
  --bg:      #0a0c10;
  --bg2:     #111318;
  --bg3:     #181c24;
  --border:  #2a2f3d;
  --text:    #d4dae8;
  --dim:     #6b7280;
  --accent:  #f59e0b;
  --accent2: #3b82f6;
  --green:   #10b981;
  --red:     #ef4444;
  --orange:  #f97316;
}
* { box-sizing: border-box; margin: 0; padding: 0; }
body {
  background: var(--bg);
  color: var(--text);
  font-family: 'Share Tech Mono', 'Courier New', monospace;
  font-size: 13px;
  min-height: 100vh;
}
a { color: var(--accent2); text-decoration: none; }
a:hover { text-decoration: underline; }

/* ---- header ---- */
.hdr {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 20px;
  background: var(--bg2);
  border-bottom: 1px solid var(--border);
}
.hdr-title {
  font-size: 1.1rem;
  font-weight: bold;
  letter-spacing: .12em;
  color: var(--accent);
}
.hdr-title span { color: var(--dim); font-size: .75rem; margin-left: 8px; }
.hdr-nav a {
  color: var(--dim);
  font-size: .75rem;
  margin-left: 14px;
}
.hdr-nav a:hover { color: var(--accent); }

/* ---- layout ---- */
.wrap { max-width: 1100px; margin: 0 auto; padding: 20px 16px; }

/* ---- section ---- */
.section {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 6px;
  margin-bottom: 20px;
}
.section-hdr {
  padding: 10px 16px;
  border-bottom: 1px solid var(--border);
  font-size: .7rem;
  letter-spacing: .15em;
  color: var(--accent);
  text-transform: uppercase;
  display: flex;
  align-items: center;
  gap: 10px;
}
.section-body { padding: 16px; }

/* ---- assoc id ---- */
.assoc-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.assoc-row label { color: var(--dim); font-size: .75rem; }
.assoc-row input {
  background: var(--bg3);
  border: 1px solid var(--border);
  color: var(--text);
  padding: 6px 10px;
  border-radius: 4px;
  font-family: inherit;
  font-size: .85rem;
  width: 220px;
}

/* ---- add form ---- */
.add-form {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  align-items: flex-end;
}
.add-form input[type=text] {
  background: var(--bg3);
  border: 1px solid var(--border);
  color: var(--text);
  padding: 7px 10px;
  border-radius: 4px;
  font-family: inherit;
  font-size: .85rem;
}
.add-form input[type=text]:focus { outline: 1px solid var(--accent2); }
.inp-asin { width: 140px; }
.inp-title { width: 320px; }
.inp-url { width: 260px; }

/* ---- buttons ---- */
.btn {
  padding: 7px 14px;
  border-radius: 4px;
  border: none;
  cursor: pointer;
  font-family: inherit;
  font-size: .8rem;
  letter-spacing: .05em;
  transition: opacity .15s;
}
.btn:hover { opacity: .85; }
.btn-amber  { background: var(--accent);  color: #000; }
.btn-blue   { background: var(--accent2); color: #fff; }
.btn-green  { background: var(--green);   color: #fff; }
.btn-red    { background: var(--red);     color: #fff; }
.btn-ghost  { background: transparent; border: 1px solid var(--border); color: var(--dim); }
.btn-ghost:hover { border-color: var(--accent2); color: var(--accent2); }
.btn-sm { padding: 4px 9px; font-size: .72rem; }

/* ---- items table ---- */
.items-table { width: 100%; border-collapse: collapse; }
.items-table th {
  text-align: left;
  padding: 6px 10px;
  font-size: .68rem;
  letter-spacing: .1em;
  color: var(--dim);
  border-bottom: 1px solid var(--border);
}
.items-table td {
  padding: 8px 10px;
  border-bottom: 1px solid var(--border);
  vertical-align: top;
}
.items-table tr:last-child td { border-bottom: none; }
.items-table tr:hover td { background: var(--bg3); }

/* ---- item detail panel ---- */
.item-panel {
  background: var(--bg3);
  border: 1px solid var(--border);
  border-radius: 6px;
  padding: 14px;
  margin-top: 16px;
  display: none;
}
.item-panel.open { display: block; }
.panel-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}
@media (max-width: 640px) { .panel-grid { grid-template-columns: 1fr; } }
.field-label {
  font-size: .68rem;
  color: var(--dim);
  letter-spacing: .1em;
  margin-bottom: 4px;
  text-transform: uppercase;
}
.field-input {
  background: var(--bg2);
  border: 1px solid var(--border);
  color: var(--text);
  padding: 6px 10px;
  border-radius: 4px;
  font-family: inherit;
  font-size: .82rem;
  width: 100%;
}
.field-input:focus { outline: 1px solid var(--accent2); }
.field-textarea {
  background: var(--bg2);
  border: 1px solid var(--border);
  color: var(--text);
  padding: 8px 10px;
  border-radius: 4px;
  font-family: inherit;
  font-size: .82rem;
  width: 100%;
  resize: vertical;
  line-height: 1.55;
}
.field-textarea:focus { outline: 1px solid var(--accent2); }
.field-select {
  background: var(--bg2);
  border: 1px solid var(--border);
  color: var(--text);
  padding: 6px 10px;
  border-radius: 4px;
  font-family: inherit;
  font-size: .82rem;
}
.panel-full { grid-column: 1 / -1; }

/* ---- tags ---- */
.kw-tags { display: flex; flex-wrap: wrap; gap: 5px; margin-top: 6px; }
.kw-tag {
  background: rgba(59,130,246,.18);
  color: var(--accent2);
  padding: 2px 8px;
  border-radius: 3px;
  font-size: .72rem;
  cursor: pointer;
}
.kw-tag:hover { background: rgba(239,68,68,.25); color: var(--red); }

/* ---- badges ---- */
.badge {
  display: inline-block;
  padding: 2px 7px;
  border-radius: 3px;
  font-size: .68rem;
  letter-spacing: .05em;
}
.badge-fixed  { background: rgba(245,158,11,.2);  color: var(--accent); }
.badge-random { background: rgba(107,114,128,.2); color: var(--dim); }
.badge-on     { background: rgba(16,185,129,.2);  color: var(--green); }
.badge-off    { background: rgba(239,68,68,.15);  color: var(--red); }
.badge-all    { background: rgba(59,130,246,.15); color: var(--accent2); }
.badge-pc     { background: rgba(139,92,246,.15); color: #a78bfa; }
.badge-sp     { background: rgba(236,72,153,.15); color: #f472b6; }

/* ---- gen row ---- */
.gen-row {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
  margin-bottom: 6px;
}

/* ---- status bar ---- */
.status-bar {
  position: fixed;
  bottom: 16px;
  right: 16px;
  background: var(--bg2);
  border: 1px solid var(--border);
  padding: 8px 14px;
  border-radius: 5px;
  font-size: .78rem;
  color: var(--green);
  display: none;
  z-index: 999;
}
.status-bar.err { color: var(--red); }

/* ---- loading ---- */
.spin {
  display: inline-block;
  width: 12px; height: 12px;
  border: 2px solid var(--border);
  border-top-color: var(--accent);
  border-radius: 50%;
  animation: spin .7s linear infinite;
  vertical-align: middle;
  margin-right: 4px;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* ---- empty ---- */
.empty { color: var(--dim); font-size: .8rem; padding: 20px 0; text-align: center; }

/* ---- login wall ---- */
.login-wall {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  min-height: 60vh;
  gap: 14px;
}
.login-wall h2 { color: var(--accent); letter-spacing: .1em; }
.login-wall p  { color: var(--dim); font-size: .82rem; }

/* ---- add tabs ---- */
.add-tab {
  background: transparent;
  border: none;
  border-bottom: 2px solid transparent;
  color: var(--dim);
  padding: 7px 16px;
  font-family: inherit;
  font-size: .78rem;
  cursor: pointer;
  letter-spacing: .05em;
  transition: color .15s;
}
.add-tab:hover { color: var(--text); }
.add-tab.active { color: var(--accent); border-bottom-color: var(--accent); }

/* ---- search results ---- */
.search-result-list { display: flex; flex-direction: column; gap: 6px; }
.search-result-card {
  display: flex;
  align-items: center;
  gap: 10px;
  background: var(--bg3);
  border: 1px solid var(--border);
  border-radius: 4px;
  padding: 8px 12px;
  transition: border-color .15s;
}
.search-result-card:hover { border-color: var(--accent2); }
.search-result-asin {
  font-size: .7rem;
  color: var(--accent);
  font-family: monospace;
  min-width: 90px;
}
.search-result-title {
  flex: 1;
  font-size: .82rem;
  color: var(--text);
  line-height: 1.4;
}
.search-result-link {
  font-size: .7rem;
  color: var(--accent2);
}
</style>
</head>
<body>

<div class="hdr">
  <div class="hdr-title">AIGM<span style="color:var(--accent);">Ad</span>Engine <span>広告管理</span></div>
  <div class="hdr-nav">
    <a href="<?php echo $sns_url; ?>">← AIKnowledgeSNS</a>
    <?php if ($is_admin): ?>
    <a href="?logout=1">ログアウト</a>
    <?php endif; ?>
  </div>
</div>

<?php if (!$is_admin): ?>
<div class="wrap">
  <div class="login-wall">
    <h2>⚡ AIGMAdEngine</h2>
    <p>管理者専用です。AIKnowledgeSNSでログインしてください。</p>
    <a href="<?php echo $sns_url; ?>" class="btn btn-amber">AIKnowledgeSNSへ →</a>
  </div>
</div>
<?php else: ?>

<div class="wrap">

  <!-- アソシエイトID -->
  <div class="section">
    <div class="section-hdr">◯ アソシエイトID</div>
    <div class="section-body">
      <div class="assoc-row">
        <label>Amazon アソシエイトID</label>
        <input type="text" id="assoc-id" placeholder="yoursite-22" />
        <button class="btn btn-amber" onclick="saveAll()">保存</button>
      </div>
    </div>
  </div>

  <!-- 商品追加 -->
  <div class="section">
    <div class="section-hdr">＋ 商品追加</div>
    <div class="section-body">
      <!-- タブ切り替え -->
      <div style="display:flex;gap:0;margin-bottom:14px;border-bottom:1px solid var(--border);">
        <button class="add-tab active" id="tab-search" onclick="switchAddTab('search')">🔍 メーカー・型番で検索</button>
        <button class="add-tab" id="tab-asin" onclick="switchAddTab('asin')">ASIN直接入力</button>
      </div>

      <!-- 検索タブ -->
      <div id="add-panel-search">
        <div class="add-form">
          <div>
            <div class="field-label">メーカー名 / 型番 / 商品名</div>
            <input type="text" id="inp-search-q" class="inp-title" placeholder="例: 精和産業 TN-02 または ターボノズル" style="width:360px;" />
          </div>
          <div style="padding-top:18px;">
            <button class="btn btn-blue" onclick="searchAsin()">検索</button>
          </div>
        </div>
        <div id="search-status" style="margin-top:8px;font-size:.75rem;color:var(--dim);"></div>
        <div id="search-results" style="margin-top:10px;"></div>
      </div>

      <!-- ASIN直接入力タブ -->
      <div id="add-panel-asin" style="display:none;">
        <div class="add-form">
          <div>
            <div class="field-label">ASIN</div>
            <input type="text" id="inp-asin" class="inp-asin" placeholder="B0XXXXXXXXX" maxlength="10" />
          </div>
          <div>
            <div class="field-label">タイトル（自動取得 or 手入力）</div>
            <input type="text" id="inp-title" class="inp-title" placeholder="商品タイトル" />
          </div>
          <div style="padding-top:18px;">
            <button class="btn btn-ghost" onclick="fetchAsin()">ASIN取得</button>
            <button class="btn btn-amber" onclick="addItem()" style="margin-left:6px;">追加</button>
          </div>
        </div>
        <div id="asin-status" style="margin-top:8px;font-size:.75rem;color:var(--dim);"></div>
      </div>
    </div>
  </div>

  <!-- 商品一覧 -->
  <div class="section">
    <div class="section-hdr">
      ◯ 商品一覧
      <span id="item-count" style="color:var(--dim);font-size:.7rem;"></span>
      <button class="btn btn-green btn-sm" style="margin-left:auto;" onclick="saveAll()">💾 全保存</button>
    </div>
    <div class="section-body" style="padding:0;">
      <div id="items-area"><div class="empty">読み込み中…</div></div>
    </div>
  </div>

</div>

<div class="status-bar" id="status-bar"></div>

<script>
// ---- state ----
var items        = [];
var assocId      = '';
var openPanelIdx = -1;

// ---- add tab switch ----
function switchAddTab(tab) {
  g('add-panel-search').style.display = tab === 'search' ? '' : 'none';
  g('add-panel-asin').style.display   = tab === 'asin'   ? '' : 'none';
  g('tab-search').className = 'add-tab' + (tab === 'search' ? ' active' : '');
  g('tab-asin').className   = 'add-tab' + (tab === 'asin'   ? ' active' : '');
}

// ---- search ASIN by keyword ----
function searchAsin() {
  var q = g('inp-search-q').value.trim();
  if (!q) { g('search-status').textContent = 'キーワードを入力してください'; return; }
  g('search-status').innerHTML = '<span class="spin"></span>検索中…';
  g('search-results').innerHTML = '';
  xhrGet('?action=search_asin&q=' + encodeURIComponent(q), function(err, data) {
    console.log('[DEBUG] search_asin:', data);
    if (err || !data || !data.ok) {
      g('search-status').textContent = '検索失敗';
      return;
    }
    if (data.candidates.length === 0) {
      g('search-status').textContent = '候補が見つかりませんでした';
      return;
    }
    g('search-status').innerHTML = data.count + '件見つかりました &nbsp;<button class="btn btn-green btn-sm" onclick="addCheckedItems()">✔ チェックした商品を一括登録</button>';
    // 候補をグローバル配列に保持しインデックスで参照
    searchCandidates = data.candidates;
    renderSearchResults();
  });
}

// ---- search candidates cache ----
var searchCandidates = [];

// ---- render search results ----
function renderSearchResults() {
  var registeredAsins = {};
  for (var k = 0; k < items.length; k++) { registeredAsins[items[k].asin] = true; }
  var html = '<div class="search-result-list">';
  for (var i = 0; i < searchCandidates.length; i++) {
    var c = searchCandidates[i];
    var already = registeredAsins[c.asin] ? true : false;
    html +=
      '<div class="search-result-card" id="reg-card-' + i + '">' +
        '<input type="checkbox" id="reg-chk-' + i + '" class="reg-chk"' + (already ? ' disabled' : '') + ' style="width:16px;height:16px;cursor:pointer;accent-color:var(--accent);">' +
        '<div class="search-result-asin">' + escHtml(c.asin) + '</div>' +
        '<div class="search-result-title">' + escHtml(c.title) + '</div>' +
        '<a href="' + escHtml(c.url) + '" target="_blank" class="search-result-link">Amazon ↗</a>' +
        (already
          ? '<span style="font-size:.72rem;color:var(--dim);">登録済み</span>'
          : '<button class="btn btn-amber btn-sm" id="reg-btn-' + i + '" onclick="addItemFromSearch(' + i + ')">登録</button>'
        ) +
      '</div>';
  }
  html += '</div>';
  g('search-results').innerHTML = html;
}

// ---- bulk add checked items ----
function addCheckedItems() {
  var chks = document.querySelectorAll('.reg-chk:checked');
  if (chks.length === 0) { showStatus('チェックしてください', true); return; }
  var registeredAsins = {};
  for (var k = 0; k < items.length; k++) { registeredAsins[items[k].asin] = true; }
  var added = 0;
  for (var i = 0; i < chks.length; i++) {
    var idx = parseInt(chks[i].id.replace('reg-chk-', ''));
    var c = searchCandidates[idx];
    if (!c || registeredAsins[c.asin]) { continue; }
    items.push({ url: c.url, title: c.title, asin: c.asin, image_url: '', pr_comment: '', article: '', x_post: '', template: 'text_comment', priority: 'random', weight: 5, device: 'all', active: true, keywords: [] });
    registeredAsins[c.asin] = true;
    added++;
  }
  if (added === 0) { showStatus('追加できる商品がありません', true); return; }
  renderItems();
  saveAll(true, function() {
    showStatus(added + '件登録・保存しました');
    renderSearchResults(); // チェック状態をリセット
  });
}

// ---- add item from search result ----
function addItemFromSearch(idx) {
  var c = searchCandidates[idx];
  if (!c) { showStatus('候補データが見つかりません', true); return; }
  var asin = c.asin; var title = c.title; var url = c.url;
  items.push({
    url:        url,
    title:      title,
    asin:       asin,
    image_url:  '',
    pr_comment: '',
    article:    '',
    x_post:     '',
    template:   'text_comment',
    priority:   'random',
    weight:     5,
    device:     'all',
    active:     true,
    keywords:   [],
  });
  renderItems();
  // 自動保存
  saveAll(true, function() {
    showStatus('登録・保存しました');
    // 登録済みボタンをdisabledに
    var btn = document.getElementById('reg-btn-' + idx);
    if (btn) { btn.textContent = '登録済み'; btn.disabled = true; btn.style.opacity = '.5'; }
    var chk = document.getElementById('reg-chk-' + idx);
    if (chk) { chk.checked = false; chk.disabled = true; }
  });
}

function g(id) { return document.getElementById(id); }

function showStatus(msg, isErr) {
  var bar = g('status-bar');
  bar.textContent = msg;
  bar.className   = 'status-bar' + (isErr ? ' err' : '');
  bar.style.display = 'block';
  setTimeout(function() { bar.style.display = 'none'; }, 2500);
}

// ---- XHR ----
function xhrGet(url, cb) {
  var xhr = new XMLHttpRequest();
  xhr.open('GET', url, true);
  xhr.onreadystatechange = function() {
    if (xhr.readyState !== 4) { return; }
    try { cb(null, JSON.parse(xhr.responseText)); }
    catch(e) { cb(e, null); }
  };
  xhr.send();
}
function xhrPost(url, data, cb) {
  var xhr = new XMLHttpRequest();
  xhr.open('POST', url, true);
  xhr.setRequestHeader('Content-Type', 'application/json');
  xhr.onreadystatechange = function() {
    if (xhr.readyState !== 4) { return; }
    try { cb(null, JSON.parse(xhr.responseText)); }
    catch(e) { cb(e, null); }
  };
  xhr.send(JSON.stringify(data));
}

// ---- load ----
function loadItems() {
  xhrGet('?action=get_items', function(err, data) {
    console.log('[DEBUG] get_items:', data);
    if (err || !data || !data.ok) {
      g('items-area').innerHTML = '<div class="empty">読み込み失敗</div>';
      return;
    }
    assocId = data.data.associate_id || '';
    items   = data.data.items || [];
    g('assoc-id').value = assocId;
    renderItems();
  });
}

// ---- render ----
function renderItems() {
  g('item-count').textContent = items.length + '件';
  if (items.length === 0) {
    g('items-area').innerHTML = '<div class="empty">商品なし</div>';
    return;
  }
  var html = '<table class="items-table">' +
    '<tr>' +
      '<th style="width:40px;">#</th>' +
      '<th>タイトル / ASIN</th>' +
      '<th style="width:80px;">優先度</th>' +
      '<th style="width:50px;">重み</th>' +
      '<th style="width:60px;">デバイス</th>' +
      '<th style="width:60px;">テンプレ</th>' +
      '<th style="width:50px;">状態</th>' +
      '<th style="width:90px;"></th>' +
    '</tr>';
  for (var i = 0; i < items.length; i++) {
    var it = items[i];
    var priBadge  = it.priority === 'fixed'
      ? '<span class="badge badge-fixed">FIXED</span>'
      : '<span class="badge badge-random">RANDOM</span>';
    var devBadge  = it.device === 'pc' ? '<span class="badge badge-pc">PC</span>'
      : it.device === 'sp' ? '<span class="badge badge-sp">SP</span>'
      : '<span class="badge badge-all">ALL</span>';
    var actBadge  = it.active
      ? '<span class="badge badge-on">ON</span>'
      : '<span class="badge badge-off">OFF</span>';
    var tmpl = it.template || 'text_comment';
    html += '<tr>' +
      '<td style="color:var(--dim);">' + (i + 1) + '</td>' +
      '<td>' +
        '<div style="font-size:.85rem;">' + escHtml(it.title) + '</div>' +
        (it.asin ? '<div style="font-size:.7rem;color:var(--dim);">ASIN: ' + escHtml(it.asin) + '</div>' : '') +
        (it.keywords && it.keywords.length > 0 ? '<div style="margin-top:3px;">' + it.keywords.map(function(k){ return '<span class="kw-tag" style="cursor:default;">#' + escHtml(k) + '</span>'; }).join('') + '</div>' : '') +
      '</td>' +
      '<td>' + priBadge + '</td>' +
      '<td style="color:var(--accent);">' + (it.weight || 5) + '</td>' +
      '<td>' + devBadge + '</td>' +
      '<td style="font-size:.72rem;color:var(--dim);">' + escHtml(tmpl) + '</td>' +
      '<td>' + actBadge + '</td>' +
      '<td>' +
        '<button class="btn btn-ghost btn-sm" onclick="togglePanel(' + i + ')">編集</button> ' +
        '<button class="btn btn-red btn-sm" onclick="removeItem(' + i + ')">削除</button>' +
      '</td>' +
    '</tr>' +
    '<tr id="panel-row-' + i + '" style="display:none;"><td colspan="8" style="padding:0 10px 12px;">' +
      renderPanel(i) +
    '</td></tr>';
  }
  html += '</table>';
  g('items-area').innerHTML = html;
}

function renderPanel(i) {
  var it = items[i];
  var kws = it.keywords || [];
  return '<div class="item-panel open" id="panel-' + i + '">' +
    '<div class="panel-grid">' +

      // タイトル
      '<div class="panel-full">' +
        '<div class="field-label">タイトル</div>' +
        '<input class="field-input" id="p-title-' + i + '" value="' + escAttr(it.title) + '" />' +
      '</div>' +

      // URL
      '<div class="panel-full">' +
        '<div class="field-label">Amazon URL</div>' +
        '<input class="field-input" id="p-url-' + i + '" value="' + escAttr(it.url) + '" />' +
      '</div>' +

      // PRコメント
      '<div class="panel-full">' +
        '<div class="field-label">PRコメント</div>' +
        '<div class="gen-row">' +
          '<button class="btn btn-blue btn-sm" onclick="genContent(' + i + ',\'pr_comment\')">⚡ AI生成</button>' +
        '</div>' +
        '<textarea class="field-textarea" id="p-pr-' + i + '" rows="3">' + escHtml(it.pr_comment || '') + '</textarea>' +
      '</div>' +

      // 紹介記事
      '<div class="panel-full">' +
        '<div class="field-label">商品紹介記事</div>' +
        '<div class="gen-row">' +
          '<button class="btn btn-blue btn-sm" onclick="genContent(' + i + ',\'article\')">⚡ AI生成</button>' +
        '</div>' +
        '<textarea class="field-textarea" id="p-article-' + i + '" rows="5">' + escHtml(it.article || '') + '</textarea>' +
      '</div>' +

      // X投稿文
      '<div class="panel-full">' +
        '<div class="field-label">X投稿文サンプル</div>' +
        '<div class="gen-row">' +
          '<button class="btn btn-blue btn-sm" onclick="genContent(' + i + ',\'x_post\')">⚡ AI生成</button>' +
        '</div>' +
        '<textarea class="field-textarea" id="p-xpost-' + i + '" rows="3">' + escHtml(it.x_post || '') + '</textarea>' +
      '</div>' +

      // テンプレート
      '<div>' +
        '<div class="field-label">表示テンプレート</div>' +
        '<select class="field-select" id="p-tmpl-' + i + '">' +
          opt('text',         'テキストのみ',           it.template) +
          opt('text_comment', 'テキスト＋PRコメント',   it.template) +
          opt('image',        '画像＋テキスト（フェーズ2）', it.template) +
          opt('full',         'フル（フェーズ2）',      it.template) +
        '</select>' +
      '</div>' +

      // 優先度
      '<div>' +
        '<div class="field-label">優先度</div>' +
        '<select class="field-select" id="p-pri-' + i + '">' +
          opt('fixed',  '常時表示 (FIXED)',  it.priority) +
          opt('random', 'ランダム (RANDOM)', it.priority) +
        '</select>' +
      '</div>' +

      // 重み
      '<div>' +
        '<div class="field-label">重み (1〜10)</div>' +
        '<input class="field-input" type="number" min="1" max="10" id="p-weight-' + i + '" value="' + (it.weight || 5) + '" />' +
      '</div>' +

      // デバイス
      '<div>' +
        '<div class="field-label">デバイス</div>' +
        '<select class="field-select" id="p-device-' + i + '">' +
          opt('all', '全デバイス', it.device) +
          opt('pc',  'PCのみ',     it.device) +
          opt('sp',  'SPのみ',     it.device) +
        '</select>' +
      '</div>' +

      // 表示ON/OFF
      '<div>' +
        '<div class="field-label">表示</div>' +
        '<select class="field-select" id="p-active-' + i + '">' +
          '<option value="1"' + (it.active ? ' selected' : '') + '>ON</option>' +
          '<option value="0"' + (!it.active ? ' selected' : '') + '>OFF</option>' +
        '</select>' +
      '</div>' +

      // キーワード
      '<div class="panel-full">' +
        '<div class="field-label">キーワード（スペース区切りで入力）</div>' +
        '<div style="display:flex;gap:6px;margin-bottom:6px;">' +
          '<input class="field-input" id="p-kwinp-' + i + '" placeholder="AI ガジェット 便利グッズ" style="flex:1;" />' +
          '<button class="btn btn-ghost btn-sm" onclick="addKw(' + i + ')">追加</button>' +
        '</div>' +
        '<div class="kw-tags" id="p-kwtags-' + i + '">' + renderKwTags(i, kws) + '</div>' +
      '</div>' +

      // 更新ボタン
      '<div class="panel-full" style="text-align:right;margin-top:4px;">' +
        '<button class="btn btn-amber" onclick="updateItem(' + i + ')">💾 この商品を更新</button>' +
      '</div>' +

    '</div>' +
  '</div>';
}

function renderKwTags(idx, kws) {
  var html = '';
  for (var j = 0; j < kws.length; j++) {
    html += '<span class="kw-tag" onclick="removeKw(' + idx + ',' + j + ')" title="クリックで削除">#' + escHtml(kws[j]) + ' ×</span>';
  }
  return html;
}

// ---- panel toggle ----
function togglePanel(i) {
  var row = g('panel-row-' + i);
  if (row.style.display === 'none') {
    row.style.display = '';
    openPanelIdx = i;
  } else {
    row.style.display = 'none';
    openPanelIdx = -1;
  }
}

// ---- opt helper ----
function opt(val, label, cur) {
  return '<option value="' + escAttr(val) + '"' + (cur === val ? ' selected' : '') + '>' + escHtml(label) + '</option>';
}

// ---- escaping ----
function escHtml(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function escAttr(s) { return escHtml(s); }

// ---- fetch ASIN ----
function fetchAsin() {
  var asin = g('inp-asin').value.trim().toUpperCase().replace(/[^A-Z0-9]/g, '');
  if (asin.length !== 10) { g('asin-status').textContent = 'ASINは10文字'; return; }
  g('asin-status').innerHTML = '<span class="spin"></span>取得中…';
  xhrGet('?action=fetch_asin&asin=' + asin, function(err, data) {
    console.log('[DEBUG] fetch_asin:', data);
    if (err || !data || !data.ok) {
      g('asin-status').textContent = '取得失敗: ' + (data ? data.reason : 'error');
      return;
    }
    g('inp-title').value  = data.title;
    g('asin-status').textContent = '✓ タイトル取得成功';
  });
}

// ---- add item ----
function addItem() {
  var asin  = g('inp-asin').value.trim().toUpperCase().replace(/[^A-Z0-9]/g, '');
  var title = g('inp-title').value.trim();
  if (!title) { showStatus('タイトルを入力してください', true); return; }
  var url = asin ? 'https://www.amazon.co.jp/dp/' + asin : '';
  items.push({
    url:        url,
    title:      title,
    asin:       asin,
    image_url:  '',
    pr_comment: '',
    article:    '',
    x_post:     '',
    template:   'text_comment',
    priority:   'random',
    weight:     5,
    device:     'all',
    active:     true,
    keywords:   [],
  });
  g('inp-asin').value  = '';
  g('inp-title').value = '';
  g('asin-status').textContent = '';
  renderItems();
  showStatus('商品を追加しました（保存ボタンで確定）');
}

// ---- remove item ----
function removeItem(i) {
  if (!confirm(items[i].title + ' を削除しますか？')) { return; }
  items.splice(i, 1);
  renderItems();
  showStatus('削除しました（保存ボタンで確定）');
}

// ---- update single item from panel ----
function updateItem(i) {
  items[i].title      = g('p-title-' + i).value.trim();
  items[i].url        = g('p-url-' + i).value.trim();
  items[i].pr_comment = g('p-pr-' + i).value.trim();
  items[i].article    = g('p-article-' + i).value.trim();
  items[i].x_post     = g('p-xpost-' + i).value.trim();
  items[i].template   = g('p-tmpl-' + i).value;
  items[i].priority   = g('p-pri-' + i).value;
  items[i].weight     = parseInt(g('p-weight-' + i).value) || 5;
  items[i].device     = g('p-device-' + i).value;
  items[i].active     = g('p-active-' + i).value === '1';
  // ASINをURLから再抽出
  var am = items[i].url.match(/\/dp\/([A-Z0-9]{10})/i);
  if (am) { items[i].asin = am[1].toUpperCase(); }
  saveAll(true);
}

// ---- kw ----
function addKw(i) {
  var inp  = g('p-kwinp-' + i);
  var vals = inp.value.trim().split(/\s+/);
  inp.value = '';
  if (!items[i].keywords) { items[i].keywords = []; }
  for (var j = 0; j < vals.length; j++) {
    var kw = vals[j].replace(/^#/, '').trim();
    if (kw && items[i].keywords.indexOf(kw) === -1) {
      items[i].keywords.push(kw);
    }
  }
  g('p-kwtags-' + i).innerHTML = renderKwTags(i, items[i].keywords);
}
function removeKw(i, j) {
  items[i].keywords.splice(j, 1);
  g('p-kwtags-' + i).innerHTML = renderKwTags(i, items[i].keywords);
}

// ---- AI generate ----
function genContent(i, type) {
  var title = g('p-title-' + i).value.trim() || items[i].title;
  var kws   = items[i].keywords || [];
  var btnSel = {
    'pr_comment': 'p-pr-' + i,
    'article':    'p-article-' + i,
    'x_post':     'p-xpost-' + i,
  };
  var targetId = btnSel[type];
  g(targetId).value = '生成中…';
  xhrPost('?action=gen_pr', { title: title, type: type, keywords: kws }, function(err, data) {
    console.log('[DEBUG] gen_pr type=' + type + ':', data);
    if (err || !data || !data.ok) {
      g(targetId).value = '';
      showStatus('生成失敗', true);
      return;
    }
    g(targetId).value = data.text;
    showStatus('生成完了');
  });
}

// ---- save all ----
function saveAll(silent, cb) {
  assocId = g('assoc-id').value.trim();
  var payload = { associate_id: assocId, items: items };
  xhrPost('?action=save_items', payload, function(err, data) {
    console.log('[DEBUG] save_items:', data);
    if (err || !data || !data.ok) {
      showStatus('保存失敗', true);
      return;
    }
    if (!silent) { showStatus('保存しました (' + data.count + '件)'); }
    else { showStatus('更新しました'); }
    renderItems();
    if (typeof cb === 'function') { cb(); }
  });
}

// ---- init ----
loadItems();
</script>

<?php endif; ?>
</body>
</html>
