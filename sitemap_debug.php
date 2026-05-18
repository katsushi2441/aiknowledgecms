<?php
$keyword_file = __DIR__."/keyword.json";
echo "path: ".$keyword_file."\n";
echo "exists: ".(file_exists($keyword_file) ? "yes" : "no")."\n";
if(file_exists($keyword_file)){
    $d = json_decode(file_get_contents($keyword_file), true);
    echo "json_error: ".json_last_error_msg()."\n";
    echo "keywords count: ".(isset($d['keywords']) ? count($d['keywords']) : "no keywords key")."\n";
}
