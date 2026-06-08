<?php
function aigm_load_config($yaml_path) {
    if (!file_exists($yaml_path)) { return array(); }
    $lines = file($yaml_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $config = array();
    $section = '';
    $subsection = '';
    foreach ($lines as $line) {
        if (preg_match('/^\s*#/', $line) || trim($line) === '') { continue; }
        if (preg_match('/^(\w+):\s*$/', $line, $m)) {
            $section = $m[1];
            $subsection = '';
            if (!isset($config[$section])) { $config[$section] = array(); }
            continue;
        }
        if (preg_match('/^  (\w+):\s*$/', $line, $m)) {
            $subsection = $m[1];
            if (!isset($config[$section][$subsection])) { $config[$section][$subsection] = array(); }
            continue;
        }
        if (preg_match('/^    (\w+):\s*(.+)$/', $line, $m)) {
            $config[$section][$subsection][$m[1]] = trim($m[2]);
            continue;
        }
        if (preg_match('/^  (\w+):\s*(.+)$/', $line, $m)) {
            $config[$section][$m[1]] = trim($m[2]);
            continue;
        }
        if (preg_match('/^(\w+):\s*(.+)$/', $line, $m)) {
            $config[$m[1]] = trim($m[2]);
        }
    }
    return $config;
}

$_aigm_config = aigm_load_config(__DIR__ . '/config.yaml');

define('AIGM_BASE_URL', isset($_aigm_config['site']['base_url']) ? $_aigm_config['site']['base_url'] : 'https://aiknowledgecms.exbridge.jp');
define('AIGM_COOKIE_DOMAIN', '.exbridge.jp');
define('AIGM_ADMIN', isset($_aigm_config['site']['admin']) ? $_aigm_config['site']['admin'] : 'xb_bittensor');
define('AIGM_GTAG_ID', isset($_aigm_config['site']['gtag_id']) ? $_aigm_config['site']['gtag_id'] : 'G-BP0650KDFR');
