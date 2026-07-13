<?php
header("Content-Type: application/xml; charset=UTF-8");
$base_url = "https://aiknowledgecms.exbridge.jp";
echo '<?xml version="1.0" encoding="UTF-8"?>';
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
/* =========================
   固定ページ
========================= */
$static_pages = array(
    ""                     => "1.0",   // トップ(Agent Loopフレームワーク EN)
    "aiknowledgecms.html"  => "1.0",   // 日本語版
    "articles/"            => "0.9",   // Loop生成メディア一覧
    "aiknowledgecms.php"   => "1.0",
    "aithinkingmedia.php"  => "0.9",
    "aitrend.php"          => "0.9",
    "newskeyword.php"      => "0.9",
    "daily_summary.php"    => "0.9",
    "aiknowledgesns.php"   => "0.9",
    "airadarx.php"         => "0.9",
    "oss.php"              => "0.9",
    "osszenn.php"          => "0.9",
    "aitech.php"           => "0.9",
    "ainews.php"           => "0.9",
    "ustoryv.php"          => "0.8",
    "usongv.php"           => "0.8",
    "umediav.php"          => "0.8",
    "xinsightv.php"        => "0.8"
);
foreach($static_pages as $file => $priority){
    echo '<url>';
    echo '<loc>'.$base_url.'/'.$file.'</loc>';
    echo '<priority>'.$priority.'</priority>';
    echo '</url>';
}
/* =========================
   Loop生成記事 (/articles/*.html)
========================= */
$article_files = glob(__DIR__."/articles/*.html");
if ($article_files !== false) {
    foreach ($article_files as $f) {
        $name = basename($f);
        if ($name === "index.html") { continue; }
        $lastmod = date("Y-m-d", filemtime($f));
        echo '<url>';
        echo '<loc>'.$base_url.'/articles/'.rawurlencode($name).'</loc>';
        echo '<lastmod>'.$lastmod.'</lastmod>';
        echo '<changefreq>weekly</changefreq>';
        echo '<priority>0.8</priority>';
        echo '</url>';
    }
}
/* =========================
   キーワードページ
   2026-07-14: 全量(9,000件超)を載せるのをやめ、直近500件に制限。
   sitemap全体が11,212 URLに膨張し、その81%が動的キーワードページで、
   新規記事(/articles/)へのクロールバジェットが薄まっていた
   (未インデックス記事21件滞留の一因)。ページ自体は残る:
   sitemapで宣伝しなくなるだけ。
========================= */
$keyword_file = __DIR__."/keyword.json";
if(file_exists($keyword_file)){
    $data = json_decode(file_get_contents($keyword_file), true);
    if(isset($data["keywords"])){
        $kw_all = array_keys($data["keywords"]);
        $kw_recent = array_slice($kw_all, -500);   // 追記順を前提に末尾=新しい方を採用
        foreach($kw_recent as $kw){
            $url = $base_url."/aiknowledgecms.php?kw=".urlencode($kw);
            echo '<url>';
            echo '<loc>'.$url.'</loc>';
            echo '<priority>0.5</priority>';
            echo '</url>';
        }
    }
}
/* =========================
   daily_summary 日付ページ
========================= */
$data_dir = __DIR__."/data";
$files = glob($data_dir."/*_daily_summary.json");
if($files !== false){
    foreach($files as $f){
        if(preg_match("/(\d{4}-\d{2}-\d{2})_daily_summary\.json$/", $f, $m)){
            $date = $m[1];
            $url = $base_url."/daily_summary.php?date=".$date;
            echo '<url>';
            echo '<loc>'.$url.'</loc>';
            echo '<priority>0.7</priority>';
            echo '</url>';
        }
    }
}
/* =========================
   newskeyword 日付ページ
========================= */
$dates = array();
if($files !== false){
    foreach($files as $f){
        if(preg_match("/(\d{4}-\d{2}-\d{2})_daily_summary\.json$/", $f, $m)){
            $dates[$m[1]] = true;
        }
    }
}
foreach($dates as $date => $_){
    $url = $base_url."/newskeyword.php?base_date=".$date;
    echo '<url>';
    echo '<loc>'.$url.'</loc>';
    echo '<priority>0.7</priority>';
    echo '</url>';
}
/* =========================
   AIKnowledgeSNS アカウントページ
========================= */
$kw_files = glob(__DIR__."/data/keyword_*.json");
if ($kw_files !== false) {
    foreach ($kw_files as $f) {
        $d = json_decode(file_get_contents($f), true);
        if (!$d || !isset($d['account'])) { continue; }
        $updated = isset($d['updated']) ? $d['updated'] : date('Y-m-d');
        $url = $base_url."/aiknowledgesns.php?view=account&amp;u=".urlencode($d['account']);
        echo '<url>';
        echo '<loc>'.$url.'</loc>';
        echo '<lastmod>'.$updated.'</lastmod>';
        echo '<changefreq>weekly</changefreq>';
        echo '<priority>0.7</priority>';
        echo '</url>';
    }
}
/* =========================
   UStoryV 個別ページ
========================= */
$xinsight_files = glob($data_dir."/xinsight_*.json");
if ($xinsight_files !== false) {
    foreach ($xinsight_files as $f) {
        $d = json_decode(file_get_contents($f), true);
        if (!$d || empty($d['story']) || !isset($d['tweet_id'])) { continue; }
        $lastmod = isset($d['saved_at']) ? substr($d['saved_at'], 0, 10) : date('Y-m-d');
        $url = $base_url."/ustoryv.php?id=".urlencode($d['tweet_id']);
        echo '<url>';
        echo '<loc>'.$url.'</loc>';
        echo '<lastmod>'.$lastmod.'</lastmod>';
        echo '<changefreq>monthly</changefreq>';
        echo '<priority>0.7</priority>';
        echo '</url>';
    }
}
/* =========================
   USongV 個別ページ
========================= */
if ($xinsight_files !== false) {
    foreach ($xinsight_files as $f) {
        $d = json_decode(file_get_contents($f), true);
        if (!$d || empty($d['lyrics']) || !isset($d['tweet_id'])) { continue; }
        $lastmod = isset($d['saved_at']) ? substr($d['saved_at'], 0, 10) : date('Y-m-d');
        $url = $base_url."/usongv.php?id=".urlencode($d['tweet_id']);
        echo '<url>';
        echo '<loc>'.$url.'</loc>';
        echo '<lastmod>'.$lastmod.'</lastmod>';
        echo '<changefreq>monthly</changefreq>';
        echo '<priority>0.7</priority>';
        echo '</url>';
    }
}
/* =========================
   UMediaV 個別ページ
========================= */
if ($xinsight_files !== false) {
    foreach ($xinsight_files as $f) {
        $d = json_decode(file_get_contents($f), true);
        if (!$d || empty($d['media']) || !isset($d['tweet_id'])) { continue; }
        $lastmod = isset($d['saved_at']) ? substr($d['saved_at'], 0, 10) : date('Y-m-d');
        $url = $base_url."/umediav.php?id=".urlencode($d['tweet_id']);
        echo '<url>';
        echo '<loc>'.$url.'</loc>';
        echo '<lastmod>'.$lastmod.'</lastmod>';
        echo '<changefreq>monthly</changefreq>';
        echo '<priority>0.7</priority>';
        echo '</url>';
    }
}
/* =========================
   XInsightV 個別ページ
========================= */
if ($xinsight_files !== false) {
    foreach ($xinsight_files as $f) {
        $d = json_decode(file_get_contents($f), true);
        if (!$d || empty($d['insight']) || !isset($d['tweet_id'])) { continue; }
        $lastmod = isset($d['saved_at']) ? substr($d['saved_at'], 0, 10) : date('Y-m-d');
        $url = $base_url."/xinsightv.php?id=".urlencode($d['tweet_id']);
        echo '<url>';
        echo '<loc>'.$url.'</loc>';
        echo '<lastmod>'.$lastmod.'</lastmod>';
        echo '<changefreq>monthly</changefreq>';
        echo '<priority>0.7</priority>';
        echo '</url>';
    }
}
/* =========================
   OSS 個別ページ・タグページ
========================= */
$oss_file = __DIR__."/data/oss_posts.json";
if (file_exists($oss_file)) {
    $oss_posts = json_decode(file_get_contents($oss_file), true);
    if ($oss_posts) {

        // タグ収集（重複除去）
        $all_tags = array();
        foreach ($oss_posts as $post) {
            if (!empty($post['tags'])) {
                foreach ($post['tags'] as $tag) {
                    $all_tags[$tag] = true;
                }
            }
        }

        // タグごとのページ
        foreach ($all_tags as $tag => $_) {
            $url = $base_url."/oss.php?tag=".urlencode($tag);
            echo '<url>';
            echo '<loc>'.$url.'</loc>';
            echo '<changefreq>daily</changefreq>';
            echo '<priority>0.7</priority>';
            echo '</url>';
        }

        // OSS個別ページ
        foreach ($oss_posts as $post) {
            if (!isset($post['id'])) { continue; }
            $url     = $base_url."/oss.php?id=".urlencode($post['id']);
            $lastmod = isset($post['created_at']) ? substr($post['created_at'], 0, 10) : date('Y-m-d');
            echo '<url>';
            echo '<loc>'.$url.'</loc>';
            echo '<lastmod>'.$lastmod.'</lastmod>';
            echo '<changefreq>monthly</changefreq>';
            echo '<priority>0.6</priority>';
            echo '</url>';
        }

        // OSSZenn タグページ
        foreach ($all_tags as $tag => $_) {
            $url = $base_url."/osszenn.php?tag=".urlencode($tag);
            echo '<url>';
            echo '<loc>'.$url.'</loc>';
            echo '<changefreq>daily</changefreq>';
            echo '<priority>0.7</priority>';
            echo '</url>';
        }

        // OSSZenn 個別ページ
        foreach ($oss_posts as $post) {
            if (!isset($post['id'])) { continue; }
            $url     = $base_url."/osszenn.php?id=".urlencode($post['id']);
            $lastmod = isset($post['created_at']) ? substr($post['created_at'], 0, 10) : date('Y-m-d');
            echo '<url>';
            echo '<loc>'.$url.'</loc>';
            echo '<lastmod>'.$lastmod.'</lastmod>';
            echo '<changefreq>daily</changefreq>';
            echo '<priority>0.6</priority>';
            echo '</url>';
        }
    }
}
/* =========================
   AITech Links 個別ページ・タグページ
========================= */
$aitech_file = __DIR__."/data/aitech_posts.json";
if (file_exists($aitech_file)) {
    $aitech_posts = json_decode(file_get_contents($aitech_file), true);
    if ($aitech_posts) {
        $aitech_tags = array();
        foreach ($aitech_posts as $post) {
            if (!empty($post['tags'])) {
                foreach ($post['tags'] as $tag) { $aitech_tags[$tag] = true; }
            }
        }
        foreach ($aitech_tags as $tag => $_) {
            $url = $base_url."/aitech.php?tag=".urlencode($tag);
            echo '<url><loc>'.$url.'</loc><changefreq>daily</changefreq><priority>0.7</priority></url>';
        }
        foreach ($aitech_posts as $post) {
            if (!isset($post['id'])) { continue; }
            $url     = $base_url."/aitech.php?id=".urlencode($post['id']);
            $lastmod = isset($post['created_at']) ? substr($post['created_at'], 0, 10) : date('Y-m-d');
            echo '<url><loc>'.$url.'</loc><lastmod>'.$lastmod.'</lastmod><changefreq>monthly</changefreq><priority>0.6</priority></url>';
        }
    }
}
/* =========================
   AI News 個別ページ・タグページ
========================= */
$ainews_file = __DIR__."/data/ainews_posts.json";
if (file_exists($ainews_file)) {
    $ainews_posts = json_decode(file_get_contents($ainews_file), true);
    if ($ainews_posts) {
        $ainews_tags = array();
        foreach ($ainews_posts as $post) {
            if (!empty($post['tags'])) {
                foreach ($post['tags'] as $tag) { $ainews_tags[$tag] = true; }
            }
        }
        foreach ($ainews_tags as $tag => $_) {
            $url = $base_url."/ainews.php?tag=".urlencode($tag);
            echo '<url><loc>'.$url.'</loc><changefreq>daily</changefreq><priority>0.7</priority></url>';
        }
        foreach ($ainews_posts as $post) {
            if (!isset($post['id'])) { continue; }
            $url     = $base_url."/ainews.php?id=".urlencode($post['id']);
            $lastmod = isset($post['created_at']) ? substr($post['created_at'], 0, 10) : date('Y-m-d');
            echo '<url><loc>'.$url.'</loc><lastmod>'.$lastmod.'</lastmod><changefreq>daily</changefreq><priority>0.7</priority></url>';
        }
    }
}
echo '</urlset>';
?>