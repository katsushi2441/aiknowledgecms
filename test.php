<?php
// デバッグ専用: stream_zennの最初の1件だけシリアル取得して内容を確認する
if (isset($_GET['action']) && $_GET['action'] === 'stream_zenn') {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    set_time_limit(0);

    function dbg($text, $cls='') {
        echo 'data: ' . json_encode(array('type'=>'log','text'=>$text,'cls'=>$cls), JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
    }
    function done_sse($s, $sk) {
        echo 'data: ' . json_encode(array('type'=>'done','text'=>'','cls'=>'','success'=>$s,'skip'=>$sk), JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
    }

    dbg('=== デバッグ: 1件シリアル取得 ===', 'log-head');

    // file_get_contents でテスト
    $url  = 'https://zenn.dev/api/articles?topic_name=ai&order=latest&page=1';
    $opts = array('http' => array(
        'method'        => 'GET',
        'header'        => "User-Agent: Mozilla/5.0 (compatible; AIKnowledgeBot/1.0)\r\nAccept: application/json\r\n",
        'timeout'       => 15,
        'ignore_errors' => true,
    ));
    dbg('[DEBUG] file_get_contents: ' . $url);
    $res = @file_get_contents($url, false, stream_context_create($opts));

    // レスポンスヘッダ確認
    $http_status = '?';
    if (isset($http_response_header) && is_array($http_response_header)) {
        $http_status = $http_response_header[0];
        dbg('[DEBUG] HTTP header[0]: ' . $http_status);
    }

    if ($res === false) {
        dbg('[ERROR] file_get_contents 完全失敗 (false)', 'log-err');
    } else {
        dbg('[DEBUG] body_len=' . strlen($res));
        dbg('[DEBUG] body_head=' . substr($res, 0, 100));
        $json = json_decode($res, true);
        if (!$json) {
            dbg('[ERROR] json_decode失敗', 'log-err');
        } elseif (!isset($json['articles'])) {
            dbg('[ERROR] articlesキーなし keys=' . implode(',', array_keys($json)), 'log-err');
        } else {
            dbg('[OK] articles件数=' . count($json['articles']), 'log-ok');
        }
    }

    // curl単体でもテスト
    dbg('[DEBUG] curl単体テスト開始');
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (compatible; AIKnowledgeBot/1.0)');
    curl_setopt($ch, CURLOPT_HTTPHEADER, array('Accept: application/json'));
    $curl_body = curl_exec($ch);
    $curl_err  = curl_error($ch);
    $curl_http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    dbg('[DEBUG] curl http=' . $curl_http . ' err=' . ($curl_err ? $curl_err : 'none') . ' body_len=' . strlen((string)$curl_body));
    if ($curl_body) {
        dbg('[DEBUG] curl body_head=' . substr($curl_body, 0, 100));
    }

    done_sse(0, 0);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head><meta charset="UTF-8">
<style>
body{background:#050a0d;color:#c8e8ff;font-family:monospace;padding:20px;}
.logbox{background:rgba(0,0,0,.5);border:1px solid rgba(255,136,0,.25);border-radius:4px;padding:12px;height:500px;overflow-y:auto;font-size:.8rem;line-height:1.8;}
.log-ok{color:#00ff88;}.log-err{color:#ff4466;}.log-head{color:#ff8800;}
</style></head>
<body>
<div class="logbox" id="logbox"><div style="color:#ff8800;">診断開始...</div></div>
<script>
function addLog(msg,cls){var b=document.getElementById('logbox');var d=document.createElement('div');if(cls){d.className=cls;}d.textContent=msg;b.appendChild(d);b.scrollTop=b.scrollHeight;}
var s=new EventSource('test.php?action=stream_zenn');
s.onopen=function(){addLog('[SSE] 接続確立','log-ok');};
s.onmessage=function(e){try{var m=JSON.parse(e.data);if(m.type==='log'){addLog(m.text,m.cls||'');}else if(m.type==='done'){s.close();addLog('=== 診断完了 ===','log-head');}}catch(ex){addLog('[ERR]'+ex.message,'log-err');}};
s.onerror=function(){s.close();addLog('[SSE ERROR]','log-err');};
</script>
</body></html>
