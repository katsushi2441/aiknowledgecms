<?php
date_default_timezone_set('Asia/Tokyo');

$BASE_URL = 'https://aiknowledgecms.exbridge.jp';
$DATA_DIR = __DIR__ . '/data';
$THIS_FILE = 'aiknowledgesns.php';

function h($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function read_json_file($path, $fallback) {
    if (!file_exists($path)) { return $fallback; }
    $json = json_decode(file_get_contents($path), true);
    return is_array($json) ? $json : $fallback;
}

function short_text($text, $limit) {
    $text = trim(preg_replace('/\s+/u', ' ', strip_tags((string)$text)));
    if (function_exists('mb_substr') && mb_strlen($text, 'UTF-8') > $limit) {
        return mb_substr($text, 0, $limit, 'UTF-8') . '...';
    }
    return strlen($text) > $limit ? substr($text, 0, $limit) . '...' : $text;
}

function safe_account($account) {
    return preg_replace('/[^a-zA-Z0-9_]/', '', (string)$account);
}

function load_accounts($data_dir) {
    $accounts = array();
    $files = glob($data_dir . '/keyword_*.json');
    if (!$files) { return $accounts; }
    foreach ($files as $file) {
        $data = read_json_file($file, array());
        if (empty($data['account'])) { continue; }
        $account = safe_account($data['account']);
        $user = isset($data['user']) && is_array($data['user']) ? $data['user'] : array();
        $sources = isset($data['sources']) && is_array($data['sources']) ? $data['sources'] : array();
        $keywords = isset($data['keywords']) && is_array($data['keywords']) ? $data['keywords'] : array();
        $zenn = isset($sources['zenn']) && is_array($sources['zenn']) ? $sources['zenn'] : array();
        $accounts[] = array(
            'account' => $account,
            'name' => isset($user['name']) ? $user['name'] : '@' . $account,
            'description' => isset($user['description']) ? $user['description'] : '',
            'keywords' => $keywords,
            'sources' => $sources,
            'zenn_username' => isset($zenn['username']) ? $zenn['username'] : '',
            'zenn_articles' => isset($zenn['articles_count']) ? (int)$zenn['articles_count'] : 0,
            'zenn_likes' => isset($zenn['total_liked_count']) ? (int)$zenn['total_liked_count'] : 0,
            'updated' => isset($data['updated']) ? $data['updated'] : '',
        );
    }
    usort($accounts, function($a, $b) {
        $az = $a['zenn_articles'] + $a['zenn_likes'];
        $bz = $b['zenn_articles'] + $b['zenn_likes'];
        if ($az === $bz) { return strcmp($b['updated'], $a['updated']); }
        return $bz - $az;
    });
    return $accounts;
}

function load_oss_posts($data_dir, $limit) {
    $posts = array();
    $seen = array();
    $bulk = read_json_file($data_dir . '/oss_posts.json', array());
    if (is_array($bulk)) {
        foreach ($bulk as $item) {
            if (!is_array($item)) { continue; }
            $id = isset($item['id']) ? $item['id'] : md5(json_encode($item));
            if (isset($seen[$id])) { continue; }
            $seen[$id] = true;
            $posts[] = $item;
        }
    }
    $files = glob($data_dir . '/oss_*.json');
    if ($files) {
        foreach ($files as $file) {
            $item = read_json_file($file, array());
            if (empty($item['id'])) { continue; }
            if (isset($seen[$item['id']])) { continue; }
            $seen[$item['id']] = true;
            $posts[] = $item;
        }
    }
    usort($posts, function($a, $b) {
        $ta = isset($a['timestamp']) ? (int)$a['timestamp'] : (isset($a['created_at']) ? strtotime($a['created_at']) : 0);
        $tb = isset($b['timestamp']) ? (int)$b['timestamp'] : (isset($b['created_at']) ? strtotime($b['created_at']) : 0);
        return $tb - $ta;
    });
    return array_slice($posts, 0, $limit);
}

function load_report_files($data_dir, $prefix, $key, $limit) {
    $items = array();
    $files = glob($data_dir . '/' . $prefix . '_*.json');
    if (!$files) { return $items; }
    rsort($files);
    $seen = array();
    foreach ($files as $file) {
        $data = read_json_file($file, array());
        if (empty($data[$key])) { continue; }
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9_\-]/', '_', $data[$key]));
        if (isset($seen[$slug])) { continue; }
        $seen[$slug] = true;
        $items[] = $data;
        if (count($items) >= $limit) { break; }
    }
    return $items;
}

function collect_tags($accounts, $oss_posts) {
    $tags = array();
    foreach ($accounts as $account) {
        foreach ($account['keywords'] as $kw) {
            $kw = trim((string)$kw);
            if ($kw !== '') { $tags[$kw] = isset($tags[$kw]) ? $tags[$kw] + 1 : 1; }
        }
    }
    foreach ($oss_posts as $post) {
        if (empty($post['tags']) || !is_array($post['tags'])) { continue; }
        foreach ($post['tags'] as $tag) {
            $tag = trim((string)$tag);
            if ($tag !== '') { $tags[$tag] = isset($tags[$tag]) ? $tags[$tag] + 1 : 1; }
        }
    }
    arsort($tags);
    return array_slice($tags, 0, 24, true);
}

$accounts = load_accounts($DATA_DIR);
$oss_posts = load_oss_posts($DATA_DIR, 8);
$finreports = load_report_files($DATA_DIR, 'finreport', 'ticker', 6);
$polymarket_reports = load_report_files($DATA_DIR, 'polymarket', 'query', 6);
$tags = collect_tags($accounts, $oss_posts);

$view_account = isset($_GET['view'], $_GET['u']) && $_GET['view'] === 'account' ? safe_account($_GET['u']) : '';
$profile = null;
if ($view_account !== '') {
    foreach ($accounts as $account) {
        if (strtolower($account['account']) === strtolower($view_account)) {
            $profile = $account;
            break;
        }
    }
}

$page_title = $profile ? $profile['name'] . ' | AIKnowledgeSNS' : 'AIKnowledgeSNS | AI Knowledge Portal';
$page_desc = $profile
    ? short_text($profile['description'] . ' ' . implode(' ', $profile['keywords']), 150)
    : 'AIKnowledgeSNSは、url2aiが自律的に集めたOSS、Zenn、FinReport、Polymarketなどの知識を人が読むための入口です。';
$page_url = $profile
    ? $BASE_URL . '/' . $THIS_FILE . '?view=account&u=' . rawurlencode($profile['account'])
    : $BASE_URL . '/' . $THIS_FILE;
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo h($page_title); ?></title>
<meta name="description" content="<?php echo h($page_desc); ?>">
<meta name="robots" content="index, follow">
<link rel="canonical" href="<?php echo h($page_url); ?>">
<meta property="og:type" content="<?php echo $profile ? 'profile' : 'website'; ?>">
<meta property="og:title" content="<?php echo h($page_title); ?>">
<meta property="og:description" content="<?php echo h($page_desc); ?>">
<meta property="og:url" content="<?php echo h($page_url); ?>">
<meta property="og:site_name" content="AIKnowledgeSNS">
<meta property="og:image" content="<?php echo h($BASE_URL); ?>/images/aiknowledgecms.png">
<meta property="og:image:width" content="1200">
<meta property="og:image:height" content="630">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?php echo h($page_title); ?>">
<meta name="twitter:description" content="<?php echo h($page_desc); ?>">
<meta name="twitter:image" content="<?php echo h($BASE_URL); ?>/images/aiknowledgecms.png">
<script async src="https://www.googletagmanager.com/gtag/js?id=G-BP0650KDFR"></script>
<script>
window.dataLayer = window.dataLayer || [];
function gtag(){dataLayer.push(arguments);}
gtag('js', new Date());
gtag('config', 'G-BP0650KDFR');
</script>
<script>
(function () {
  var s = document.createElement('script');
  s.src = 'https://aiknowledgecms.exbridge.jp/simpletrack.php?url='
    + encodeURIComponent(location.href) + '&ref=' + encodeURIComponent(document.referrer);
  document.head.appendChild(s);
})();
</script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box}
:root{--bg:#f8fafc;--panel:#fff;--text:#111827;--muted:#64748b;--line:#e2e8f0;--blue:#2563eb;--indigo:#4f46e5;--soft:#eef2ff;--mono:'JetBrains Mono',monospace}
body{margin:0;background:linear-gradient(180deg,#f8fafc 0%,#eef2ff 100%);color:var(--text);font-family:'Inter',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;font-size:14px;min-height:100vh}
a{color:inherit;text-decoration:none}
.header{position:sticky;top:0;z-index:20;background:rgba(255,255,255,.92);backdrop-filter:blur(14px);border-bottom:1px solid var(--line)}
.bar{max-width:1180px;margin:0 auto;padding:15px 22px;display:flex;align-items:center;gap:18px}
.logo{font-weight:800;font-size:18px;letter-spacing:-.03em}.logo span{color:var(--blue)}
.nav{margin-left:auto;display:flex;align-items:center;gap:10px;flex-wrap:wrap}.nav a{font-size:12px;font-weight:700;color:#475569;border:1px solid var(--line);border-radius:999px;padding:7px 11px;background:#fff}.nav a:hover{border-color:#bfdbfe;color:var(--blue);background:#eff6ff}
.container{max-width:1180px;margin:0 auto;padding:34px 22px 70px}
.hero{text-align:center;padding:34px 20px 28px}.eyebrow{display:inline-flex;align-items:center;gap:8px;font-size:12px;font-weight:800;color:#3730a3;background:#eef2ff;border:1px solid #c7d2fe;border-radius:999px;padding:8px 14px;margin-bottom:18px}
.hero h1{font-size:42px;line-height:1.18;letter-spacing:-.04em;margin:0 0 16px}.hero p{max-width:820px;margin:0 auto;color:#475569;font-size:17px;line-height:1.85}
.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-top:28px}.stat{background:rgba(255,255,255,.92);border:1px solid var(--line);border-radius:16px;padding:18px 20px;text-align:left;box-shadow:0 12px 32px rgba(15,23,42,.05)}.stat strong{display:block;font-size:29px;line-height:1;color:#0f172a;margin-bottom:8px}.stat span{font-size:12px;color:var(--muted);font-weight:700}
.section{margin-top:34px}.section-head{display:flex;align-items:end;justify-content:space-between;gap:16px;margin-bottom:14px}.section h2{margin:0;font-size:23px;letter-spacing:-.03em}.section p.lead{margin:4px 0 0;color:var(--muted);line-height:1.7}
.flow{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px}.flow-step{background:#fff;border:1px solid var(--line);border-radius:16px;padding:18px;box-shadow:0 12px 30px rgba(15,23,42,.05)}.flow-step b{display:block;font-size:14px;margin-bottom:8px}.flow-step span{color:var(--muted);font-size:12px;line-height:1.65}
.product-grid,.account-grid,.stream-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:18px}
.card{background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden;box-shadow:0 14px 30px rgba(148,163,184,.08);transition:transform .16s ease,box-shadow .16s ease}.card:hover{transform:translateY(-2px);box-shadow:0 18px 42px rgba(37,99,235,.12)}
.visual{height:112px;background:radial-gradient(circle at top right,rgba(255,255,255,.24),transparent 36%),linear-gradient(135deg,var(--accent,#2563eb),#0f172a);position:relative}.badge{position:absolute;left:14px;top:14px;background:rgba(255,255,255,.9);border-radius:999px;padding:7px 11px;font-size:12px;font-weight:800;color:#111827;box-shadow:0 10px 24px rgba(15,23,42,.14)}
.body{padding:18px}.pill{display:inline-flex;font-family:var(--mono);font-size:10px;font-weight:700;color:#1d4ed8;background:#eff6ff;border:1px solid #bfdbfe;border-radius:999px;padding:4px 9px;margin-bottom:10px}.body h3{margin:0 0 8px;font-size:18px;letter-spacing:-.02em}.body p{margin:0;color:#64748b;line-height:1.7;font-size:13px}.meta{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}.meta span,.tag{font-size:11px;font-weight:700;color:#475569;background:#f8fafc;border:1px solid var(--line);border-radius:999px;padding:5px 8px}
.item{display:block;background:#fff;border:1px solid var(--line);border-radius:14px;padding:16px;box-shadow:0 10px 24px rgba(15,23,42,.04)}.item:hover{border-color:#bfdbfe}.item-title{font-weight:800;font-size:15px;line-height:1.45;margin-bottom:7px}.item-text{color:#64748b;font-size:13px;line-height:1.65}.item-foot{display:flex;flex-wrap:wrap;gap:8px;margin-top:11px;color:#64748b;font-size:11px;font-family:var(--mono)}
.account-card{display:block;background:#fff;border:1px solid var(--line);border-radius:16px;padding:18px;box-shadow:0 12px 28px rgba(15,23,42,.05)}.account-card:hover{border-color:#bfdbfe}.handle{font-family:var(--mono);font-weight:700;color:#2563eb;font-size:13px}.name{font-size:17px;font-weight:800;margin:7px 0}.desc{color:#64748b;line-height:1.65;font-size:13px;min-height:42px}.tags{display:flex;flex-wrap:wrap;gap:7px;margin-top:13px}
.portal-box{background:rgba(255,255,255,.92);border:1px solid var(--line);border-radius:18px;padding:22px 24px;box-shadow:0 16px 40px rgba(15,23,42,.05);line-height:1.85;color:#374151}
.profile{background:#fff;border:1px solid var(--line);border-radius:18px;padding:24px;box-shadow:0 16px 40px rgba(15,23,42,.06);margin-top:24px}.profile-top{display:flex;justify-content:space-between;gap:16px;align-items:start}.profile h1{font-size:28px;margin:6px 0 10px}.actions{display:flex;flex-wrap:wrap;gap:9px;margin-top:18px}.btn{display:inline-flex;align-items:center;justify-content:center;border-radius:10px;border:1px solid #bfdbfe;color:#1d4ed8;background:#eff6ff;font-weight:800;font-size:13px;padding:9px 12px}
.empty{background:#fff;border:1px dashed #cbd5e1;border-radius:16px;padding:22px;color:#64748b;line-height:1.7}.search{width:100%;max-width:360px;border:1px solid var(--line);border-radius:999px;padding:11px 15px;font:inherit;outline:none}.search:focus{border-color:#93c5fd;box-shadow:0 0 0 4px rgba(37,99,235,.1)}
@media(max-width:820px){.bar{align-items:flex-start;flex-direction:column}.nav{margin-left:0}.hero h1{font-size:31px}.stats,.flow{grid-template-columns:1fr}.profile-top{display:block}.container{padding:24px 16px 50px}}
</style>
</head>
<body>
<header class="header">
  <div class="bar">
    <a class="logo" href="aiknowledgesns.php">AIKnowledge<span>SNS</span></a>
    <nav class="nav">
      <a href="aiknowledgecms.php">CMS</a>
      <a href="oss.php">OSS</a>
      <a href="osszenn.php">OSS Zenn</a>
      <a href="finreport.php">FinReport</a>
      <a href="polymarket.php">Polymarket</a>
      <a href="https://github.com/katsushi2441/aiknowledgecms" target="_blank" rel="noopener">GitHub</a>
    </nav>
  </div>
</header>

<main class="container">
<?php if ($profile): ?>
  <div class="eyebrow">Knowledge Profile</div>
  <section class="profile">
    <div class="profile-top">
      <div>
        <div class="handle">@<?php echo h($profile['account']); ?></div>
        <h1><?php echo h($profile['name']); ?></h1>
        <?php if ($profile['description']): ?><p class="desc"><?php echo h($profile['description']); ?></p><?php endif; ?>
      </div>
      <a class="btn" href="aiknowledgesns.php">Portal</a>
    </div>
    <div class="tags">
      <?php foreach (array_slice($profile['keywords'], 0, 16) as $kw): ?>
      <span class="tag">#<?php echo h($kw); ?></span>
      <?php endforeach; ?>
    </div>
    <div class="actions">
      <a class="btn" href="https://x.com/<?php echo h($profile['account']); ?>" target="_blank" rel="noopener">X profile</a>
      <?php if ($profile['zenn_username']): ?>
      <a class="btn" href="https://zenn.dev/<?php echo h($profile['zenn_username']); ?>" target="_blank" rel="noopener">Zenn</a>
      <?php endif; ?>
    </div>
  </section>
<?php else: ?>
  <section class="hero">
    <div class="eyebrow">AI Knowledge Portal</div>
    <h1>AIが集めた知識を、人が読む入口へ。</h1>
    <p>AIKnowledgeSNSは、発信するSNSではなく、url2aiが自律的に蓄積したOSS、Zenn、FinReport、Polymarketなどの知識を横断して読むためのポータルです。</p>
    <div class="stats">
      <div class="stat"><strong><?php echo number_format(count($accounts)); ?></strong><span>Knowledge Accounts</span></div>
      <div class="stat"><strong><?php echo number_format(count($oss_posts)); ?></strong><span>Recent OSS Items</span></div>
      <div class="stat"><strong><?php echo number_format(count($finreports)); ?></strong><span>FinReports</span></div>
      <div class="stat"><strong><?php echo number_format(count($polymarket_reports)); ?></strong><span>Polymarket Reports</span></div>
    </div>
  </section>

  <section class="section">
    <div class="section-head">
      <div>
        <h2>Product Structure</h2>
        <p class="lead">裏側で集め、表側で読ませる。AIKnowledgeCMSの役割をこの形に整理します。</p>
      </div>
    </div>
    <div class="flow">
      <div class="flow-step"><b>url2ai</b><span>URL、OSS、Zenn、金融、予測市場などを自律収集するエンジン。</span></div>
      <div class="flow-step"><b>Knowledge JSON</b><span>集めた情報を日付・テーマ・対象ごとに構造化して蓄積。</span></div>
      <div class="flow-step"><b>AIKnowledgeCMS</b><span>蓄積された知識を長期的に管理する中核。</span></div>
      <div class="flow-step"><b>AIKnowledgeSNS</b><span>人が読む入口。ポータル、タイムライン、カテゴリ横断。</span></div>
      <div class="flow-step"><b>Reuse</b><span>記事、資料、分析、次のAI処理へ知識を再利用。</span></div>
    </div>
  </section>

  <section class="section">
    <div class="section-head">
      <div>
        <h2>Knowledge Products</h2>
        <p class="lead">自律的に知識が増えていく領域を、AIKnowledgeCMSのプロダクトとして見せます。</p>
      </div>
    </div>
    <div class="product-grid">
      <a class="card" href="oss.php"><div class="visual" style="--accent:#0d9488"><span class="badge">OSS</span></div><div class="body"><span class="pill">AUTONOMOUS</span><h3>OSS Knowledge</h3><p>AI系OSSを収集し、背景、用途、使いどころを知識化します。</p></div></a>
      <a class="card" href="osszenn.php"><div class="visual" style="--accent:#3b82f6"><span class="badge">OSS + Zenn</span></div><div class="body"><span class="pill">MATCHING</span><h3>Zenn Knowledge</h3><p>Zennの技術者・記事とOSSをつなぎ、学習導線にします。</p></div></a>
      <a class="card" href="finreport.php"><div class="visual" style="--accent:#0369a1"><span class="badge">FinReport</span></div><div class="body"><span class="pill">MARKET INTEL</span><h3>Financial Reports</h3><p>株式、暗号資産、企業情報をAIが投資レポート化します。</p></div></a>
      <a class="card" href="polymarket.php"><div class="visual" style="--accent:#7c3aed"><span class="badge">Polymarket</span></div><div class="body"><span class="pill">PREDICTION</span><h3>Prediction Intelligence</h3><p>予測市場のテーマを読み解き、確率と文脈を知識化します。</p></div></a>
    </div>
  </section>

  <section class="section">
    <div class="section-head">
      <div>
        <h2>Knowledge Radar</h2>
        <p class="lead">最新の蓄積を横断して読む入口です。</p>
      </div>
    </div>
    <div class="stream-grid">
      <?php foreach ($oss_posts as $post): ?>
      <a class="item" href="oss.php?id=<?php echo urlencode(isset($post['id']) ? $post['id'] : ''); ?>">
        <div class="item-title"><?php echo h(isset($post['title']) ? $post['title'] : 'OSS Knowledge'); ?></div>
        <div class="item-text"><?php echo h(short_text(isset($post['post_text']) ? $post['post_text'] : (isset($post['summary']) ? $post['summary'] : ''), 130)); ?></div>
        <div class="item-foot"><span>OSS</span><?php if (!empty($post['created_at'])): ?><span><?php echo h(substr($post['created_at'], 0, 10)); ?></span><?php endif; ?></div>
      </a>
      <?php endforeach; ?>
      <?php foreach ($finreports as $report): ?>
      <a class="item" href="finreport.php?ticker=<?php echo urlencode($report['ticker']); ?>">
        <div class="item-title"><?php echo h($report['ticker']); ?> 投資レポート</div>
        <div class="item-text"><?php echo h(short_text(isset($report['summary']) ? $report['summary'] : '', 130)); ?></div>
        <div class="item-foot"><span>FinReport</span><?php if (!empty($report['created_at'])): ?><span><?php echo h(substr($report['created_at'], 0, 10)); ?></span><?php endif; ?></div>
      </a>
      <?php endforeach; ?>
      <?php foreach ($polymarket_reports as $report): ?>
      <a class="item" href="polymarket.php?query=<?php echo urlencode($report['query']); ?>">
        <div class="item-title"><?php echo h($report['query']); ?></div>
        <div class="item-text"><?php echo h(short_text(isset($report['summary']) ? $report['summary'] : '', 130)); ?></div>
        <div class="item-foot"><span>Polymarket</span><?php if (!empty($report['created_at'])): ?><span><?php echo h(substr($report['created_at'], 0, 10)); ?></span><?php endif; ?></div>
      </a>
      <?php endforeach; ?>
      <?php if (empty($oss_posts) && empty($finreports) && empty($polymarket_reports)): ?>
      <div class="empty">まだローカルの知識データがありません。本番環境の <code>data/</code> に蓄積されたJSONを読む想定です。</div>
      <?php endif; ?>
    </div>
  </section>

  <section class="section">
    <div class="section-head">
      <div>
        <h2>Knowledge Accounts</h2>
        <p class="lead">ZennやX由来の知識アカウントを、読む対象として一覧化します。</p>
      </div>
      <input class="search" id="account-search" type="search" placeholder="アカウント・キーワード検索">
    </div>
    <div class="account-grid" id="account-grid">
      <?php foreach ($accounts as $account): ?>
      <a class="account-card" href="aiknowledgesns.php?view=account&u=<?php echo urlencode($account['account']); ?>" data-search="<?php echo h(strtolower($account['account'] . ' ' . $account['name'] . ' ' . $account['description'] . ' ' . implode(' ', $account['keywords']) . ' ' . $account['zenn_username'])); ?>">
        <div class="handle">@<?php echo h($account['account']); ?></div>
        <div class="name"><?php echo h($account['name']); ?></div>
        <div class="desc"><?php echo h(short_text($account['description'], 90)); ?></div>
        <div class="tags">
          <?php foreach (array_slice($account['keywords'], 0, 5) as $kw): ?><span class="tag">#<?php echo h($kw); ?></span><?php endforeach; ?>
        </div>
      </a>
      <?php endforeach; ?>
      <?php if (empty($accounts)): ?><div class="empty">アカウントデータがありません。</div><?php endif; ?>
    </div>
  </section>

  <section class="section">
    <div class="section-head"><div><h2>Knowledge Tags</h2><p class="lead">蓄積された知識の入り口になるキーワードです。</p></div></div>
    <div class="portal-box">
      <div class="tags">
      <?php foreach ($tags as $tag => $count): ?>
        <span class="tag">#<?php echo h($tag); ?> <?php echo (int)$count; ?></span>
      <?php endforeach; ?>
      <?php if (empty($tags)): ?>タグデータがありません。<?php endif; ?>
      </div>
    </div>
  </section>
<?php endif; ?>
</main>

<script>
(function(){
  var input = document.getElementById('account-search');
  if (!input) { return; }
  var cards = Array.prototype.slice.call(document.querySelectorAll('.account-card'));
  input.addEventListener('input', function(){
    var q = input.value.toLowerCase().trim();
    cards.forEach(function(card){
      card.style.display = !q || card.getAttribute('data-search').indexOf(q) !== -1 ? '' : 'none';
    });
  });
})();
</script>
</body>
</html>
