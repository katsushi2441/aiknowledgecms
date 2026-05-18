<?php
date_default_timezone_set("Asia/Tokyo");

if (session_status() === PHP_SESSION_NONE) { session_start(); }

/* =========================================================
   X API キー読み込み
========================================================= */
$x_keys_file = __DIR__ . '/x_api_keys.sh';
$x_keys = array();
if (file_exists($x_keys_file)) {
    $lines = file($x_keys_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (preg_match('/(?:export\s+)?(\w+)=["\']?([^"\'#\r\n]*)["\']?/', $line, $m)) {
            $x_keys[trim($m[1])] = trim($m[2]);
        }
    }
}
$x_client_id     = isset($x_keys['X_API_KEY'])    ? $x_keys['X_API_KEY']    : '';
$x_client_secret = isset($x_keys['X_API_SECRET']) ? $x_keys['X_API_SECRET'] : '';
$x_redirect_uri  = 'https://aiknowledgecms.exbridge.jp/execphp.php';

/* =========================================================
   OAuth2 PKCE ヘルパー
========================================================= */
function ep_base64url($data) {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function ep_gen_verifier() {
    $bytes = '';
    for ($i = 0; $i < 32; $i++) { $bytes .= chr(mt_rand(0, 255)); }
    return ep_base64url($bytes);
}
function ep_gen_challenge($verifier) {
    return ep_base64url(hash('sha256', $verifier, true));
}
function ep_x_post($url, $post_data, $headers) {
    $opts = array('http' => array(
        'method'        => 'POST',
        'header'        => implode("\r\n", $headers) . "\r\n",
        'content'       => $post_data,
        'timeout'       => 12,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents($url, false, stream_context_create($opts));
    if (!$res) { $res = '{}'; }
    return json_decode($res, true);
}
function ep_x_get($url, $token) {
    $opts = array('http' => array(
        'method'        => 'GET',
        'header'        => "Authorization: Bearer $token\r\nUser-Agent: ExecPHP/1.0\r\n",
        'timeout'       => 12,
        'ignore_errors' => true,
    ));
    $res = @file_get_contents($url, false, stream_context_create($opts));
    if (!$res) { $res = '{}'; }
    return json_decode($res, true);
}

/* =========================================================
   OAuth ルーティング
========================================================= */
if (isset($_GET['ep_logout'])) {
    session_destroy();
    header('Location: ' . $x_redirect_uri);
    exit;
}
if (isset($_GET['ep_login'])) {
    $verifier  = ep_gen_verifier();
    $challenge = ep_gen_challenge($verifier);
    $state     = md5(uniqid('', true));
    $_SESSION['ep_code_verifier'] = $verifier;
    $_SESSION['ep_oauth_state']   = $state;
    $params = array(
        'response_type'         => 'code',
        'client_id'             => $x_client_id,
        'redirect_uri'          => $x_redirect_uri,
        'scope'                 => 'tweet.read users.read',
        'state'                 => $state,
        'code_challenge'        => $challenge,
        'code_challenge_method' => 'S256',
    );
    header('Location: https://twitter.com/i/oauth2/authorize?' . http_build_query($params));
    exit;
}
if (isset($_GET['code']) && isset($_GET['state']) && isset($_SESSION['ep_oauth_state'])) {
    if ($_GET['state'] === $_SESSION['ep_oauth_state']) {
        $post = http_build_query(array(
            'grant_type'    => 'authorization_code',
            'code'          => $_GET['code'],
            'redirect_uri'  => $x_redirect_uri,
            'code_verifier' => $_SESSION['ep_code_verifier'],
            'client_id'     => $x_client_id,
        ));
        $cred = base64_encode($x_client_id . ':' . $x_client_secret);
        $data = ep_x_post('https://api.twitter.com/2/oauth2/token', $post, array(
            'Content-Type: application/x-www-form-urlencoded',
            'Authorization: Basic ' . $cred,
        ));
        if (isset($data['access_token'])) {
            $_SESSION['ep_access_token'] = $data['access_token'];
            unset($_SESSION['ep_oauth_state'], $_SESSION['ep_code_verifier']);
            $me = ep_x_get('https://api.twitter.com/2/users/me', $data['access_token']);
            if (isset($me['data']['username'])) {
                $_SESSION['ep_username'] = $me['data']['username'];
            }
        }
    }
    header('Location: ' . $x_redirect_uri);
    exit;
}

$logged_in = isset($_SESSION['ep_access_token']) && $_SESSION['ep_access_token'] !== '';
$username  = isset($_SESSION['ep_username']) ? $_SESSION['ep_username'] : '';
$is_admin  = ($username === 'xb_bittensor');

/* =========================================================
   実行処理
========================================================= */
$output = '';
$error  = '';
$mode   = isset($_POST['mode']) ? $_POST['mode'] : 'php';

function find_php_bin() {
    $bins = array('/usr/local/bin/php', '/usr/bin/php', '/usr/bin/php8', '/usr/bin/php81', '/usr/bin/php8.1', '/usr/bin/php8.2', '/usr/bin/php74');
    foreach ($bins as $bin) {
        if (file_exists($bin)) { return $bin; }
    }
    return '';
}

function find_sh_bin() {
    $bins = array('/bin/sh', '/usr/bin/sh', '/bin/bash', '/usr/bin/bash');
    foreach ($bins as $bin) {
        if (file_exists($bin)) { return $bin; }
    }
    return '';
}

function ollama_generate($prompt, $system = '') {
    $full_prompt = $system ? trim($system) . "\n\n" . trim($prompt) : trim($prompt);
    $payload = json_encode(array(
        'model'  => 'gemma4:e4b',
        'prompt' => $full_prompt,
        'stream' => false,
    ));
    $opts = array(
        'http' => array(
            'method'        => 'POST',
            'header'        => "Content-Type: application/json\r\n",
            'content'       => $payload,
            'timeout'       => 60,
            'ignore_errors' => true,
        )
    );
    $ctx = stream_context_create($opts);
    $res = @file_get_contents('https://exbridge.ddns.net/api/generate', false, $ctx);
    if (!$res) {
        return array('ok' => false, 'response' => '', 'reason' => 'ollama_unreachable');
    }
    $data = json_decode($res, true);
    $response = isset($data['response']) ? $data['response'] : '';
    return array('ok' => true, 'response' => $response);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_admin) {
    $code = isset($_POST['code']) ? $_POST['code'] : '';
    if ($code !== '') {
        $out = array();
        $ret = 0;
        if ($mode === 'chat') {
            $result = ollama_generate($code, 'You are a helpful AI assistant. Answer concisely in Japanese.');
            if (!$result['ok']) {
                $output = '';
                $error = 'Ollama に接続できませんでした';
            } else {
                $output = $result['response'];
                $error  = '';
            }
        } elseif ($mode === 'cmd') {
            $sh = find_sh_bin();
            if ($sh === '') {
                $output = 'ERROR: シェルが見つかりません';
                $error  = 'Exit code: 1';
            } else {
                $tmp = tempnam(sys_get_temp_dir(), 'execcmd_') . '.sh';
                file_put_contents($tmp, $code);
                $out = array();
                $ret = 0;
                exec($sh . ' ' . escapeshellarg($tmp) . ' 2>&1', $out, $ret);
                unlink($tmp);
                $output = implode("\n", $out);
                $error  = ($ret !== 0) ? 'Exit code: ' . $ret : '';
            }
        } else {
            ob_start();
            $eval_error = '';
            set_error_handler(function($errno, $errstr, $errfile, $errline) use (&$eval_error) {
                $eval_error .= "Error[$errno]: $errstr (line $errline)\n";
                return true;
            });
            try {
                eval($code);
            } catch (Exception $e) {
                $eval_error .= 'Exception: ' . $e->getMessage() . "\n";
            }
            restore_error_handler();
            $output = ob_get_clean();
            $error  = $eval_error;
        }
    }
}

function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?><!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>PHP Playground</title>
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@400;700&family=Inter:wght@400;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
    --bg:#f8f9fa;--surface:#ffffff;--border:#e2e8f0;--border2:#cbd5e1;
    --accent:#6d28d9;--accent2:#7c3aed;
    --green:#059669;--red:#dc2626;
    --text:#1e293b;--muted:#64748b;--code-bg:#f1f5f9;
    --mono:'JetBrains Mono',monospace;
    --sans:'Inter',sans-serif;
}
body{background:var(--bg);color:var(--text);font-family:var(--sans);height:100vh;display:flex;flex-direction:column;font-size:14px}
header{display:flex;align-items:center;justify-content:space-between;padding:.7rem 1.5rem;border-bottom:1px solid var(--border);background:var(--surface);box-shadow:0 1px 3px rgba(0,0,0,.06)}
.logo{font-size:.95rem;font-weight:700;color:var(--text)}
.logo span{color:var(--accent)}
.userbar{display:flex;align-items:center;gap:.8rem;font-size:.8rem;color:var(--muted)}
.userbar strong{color:var(--green)}
.btn-sm{background:none;border:1px solid var(--border2);color:var(--muted);padding:.2rem .7rem;border-radius:4px;font-size:.75rem;cursor:pointer;text-decoration:none;transition:all .15s}
.btn-sm:hover{border-color:var(--red);color:var(--red)}

/* ログイン */
.login-wrap{flex:1;display:flex;align-items:center;justify-content:center;background:var(--bg)}
.login-card{text-align:center;padding:2.5rem;border:1px solid var(--border);border-radius:12px;background:var(--surface);width:320px;box-shadow:0 4px 16px rgba(0,0,0,.08)}
.login-card h2{font-size:1.3rem;font-weight:700;margin-bottom:.4rem}
.login-card p{color:var(--muted);font-size:.82rem;margin-bottom:1.8rem}
.btn-login{display:inline-flex;align-items:center;gap:.5rem;background:var(--accent);color:#fff;padding:.65rem 1.6rem;border-radius:6px;text-decoration:none;font-weight:600;font-size:.88rem;transition:background .2s}
.btn-login:hover{background:var(--accent2)}
.btn-login svg{width:16px;height:16px;fill:white}

/* メイン */
main{flex:1;display:flex;flex-direction:column;gap:1rem;overflow:hidden;padding:1rem}
.pane{display:flex;flex-direction:column;border:1px solid var(--border);border-radius:14px;overflow:hidden;background:var(--surface)}
.pane:last-child{background:var(--surface)}

/* タブ */
.tab-bar{display:flex;align-items:center;gap:0;border-bottom:1px solid var(--border);background:var(--surface);padding:0 1rem}
.tab{padding:.55rem .9rem;font-family:var(--mono);font-size:.72rem;color:var(--muted);cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px;transition:all .15s;background:none;border-top:none;border-left:none;border-right:none}
.tab:hover{color:var(--text)}
.tab.active{color:var(--accent);border-bottom-color:var(--accent);font-weight:700}
.tab-right{margin-left:auto;display:flex;gap:.3rem}
.snip{background:none;border:1px solid var(--border);color:var(--muted);padding:.2rem .5rem;border-radius:3px;font-family:var(--mono);font-size:.68rem;cursor:pointer;transition:all .15s}
.snip:hover{border-color:var(--accent);color:var(--accent)}

textarea#code{flex:1;background:var(--code-bg);color:#1e1b4b;font-family:var(--mono);font-size:.82rem;line-height:1.7;border:none;outline:none;padding:1rem;resize:none;tab-size:4}

.pane-footer{padding:.55rem 1rem;border-top:1px solid var(--border);background:var(--surface);display:flex;align-items:center;justify-content:space-between}
.btn-run{background:var(--accent);color:white;border:none;padding:.4rem 1.3rem;border-radius:5px;font-family:var(--mono);font-size:.78rem;font-weight:700;cursor:pointer;display:flex;align-items:center;gap:.4rem;transition:background .15s}
.btn-run:hover{background:var(--accent2)}
.btn-run:disabled{background:var(--border2);cursor:not-allowed;opacity:.7}
.btn-clear{background:none;border:1px solid var(--border2);color:var(--muted);padding:.4rem .9rem;border-radius:5px;font-family:var(--mono);font-size:.72rem;cursor:pointer;transition:all .15s}
.btn-clear:hover{border-color:var(--text);color:var(--text)}
.run-status{font-family:var(--mono);font-size:.72rem;color:var(--muted);}

.output-header{display:flex;align-items:center;justify-content:space-between;padding:.55rem 1rem;border-bottom:1px solid var(--border);background:var(--surface);font-family:var(--mono);font-size:.72rem;color:var(--muted)}
.status-ok{color:var(--green);font-weight:700}
.status-err{color:var(--red);font-weight:700}
.output-area{flex:1;overflow-y:auto;padding:1rem;font-family:var(--mono);font-size:.82rem;line-height:1.7;white-space:pre-wrap;word-break:break-all;color:var(--text)}
.output-area.ok{color:var(--green)}
.output-area.err{color:var(--red)}
.output-area.empty{color:var(--border2)}
.hint{font-family:var(--mono);font-size:.68rem;color:var(--muted)}
::-webkit-scrollbar{width:5px}
::-webkit-scrollbar-track{background:var(--code-bg)}
::-webkit-scrollbar-thumb{background:var(--border2);border-radius:3px}
</style>
</head>
<body>
<header>
    <div class="logo">PHP <span>Playground</span></div>
    <?php if ($logged_in): ?>
    <div class="userbar">
        <span>@<strong><?php echo h($username); ?></strong></span>
        <a href="?ep_logout=1" class="btn-sm">logout</a>
    </div>
    <?php endif; ?>
</header>

<?php if (!$logged_in): ?>
<div class="login-wrap">
    <div class="login-card">
        <h2>PHP Playground</h2>
        <p>@xb_bittensor のみ利用可能</p>
        <a href="?ep_login=1" class="btn-login">
            <svg viewBox="0 0 24 24"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-4.714-6.231-5.401 6.231H2.744l7.737-8.835L1.254 2.25H8.08l4.253 5.622zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
            X でログイン
        </a>
    </div>
</div>

<?php elseif (!$is_admin): ?>
<div class="login-wrap">
    <div class="login-card">
        <h2>Access Denied</h2>
        <p>@<?php echo h($username); ?> はアクセスできません</p>
        <a href="?ep_logout=1" class="btn-login" style="background:var(--red)">logout</a>
    </div>
</div>

<?php else: ?>
<form method="POST" id="form">
<input type="hidden" name="mode" id="mode_input" value="<?php echo h($mode); ?>">
<main>
    <div class="pane">
        <div class="tab-bar">
            <button type="button" class="tab <?php echo $mode === 'php' ? 'active' : ''; ?>" onclick="switchMode('php', event)">PHP</button>
            <button type="button" class="tab <?php echo $mode === 'cmd' ? 'active' : ''; ?>" onclick="switchMode('cmd', event)">Command</button>
            <button type="button" class="tab <?php echo $mode === 'chat' ? 'active' : ''; ?>" onclick="switchMode('chat', event)">Chat</button>
            <div class="tab-right" id="snippet-controls" style="<?php echo $mode === 'chat' ? 'display:none;' : ''; ?>">
                <button type="button" class="snip" id="snip1" onclick="setSnippet('fxtwitter')">FxTwitter</button>
                <button type="button" class="snip" id="snip2" onclick="setSnippet('thread')">Thread</button>
                <button type="button" class="snip" id="snip3" onclick="setSnippet('json')">JSON</button>
            </div>
        </div>
        <textarea id="code" name="code" placeholder="<?php echo $mode === 'chat' ? 'Ollama に質問を入力してください…' : 'PHP またはコマンドを入力してください…'; ?>"><?php echo isset($_POST['code']) ? h($_POST['code']) : ''; ?></textarea>
        <div class="pane-footer">
            <button type="button" class="btn-clear" onclick="document.getElementById('code').value=''">clear</button>
            <span class="run-status" id="run-status"></span>
            <button type="submit" class="btn-run" id="run-button">
                <svg width="10" height="10" viewBox="0 0 12 12" fill="currentColor"><polygon points="2,1 11,6 2,11"/></svg>
                Run
            </button>
        </div>
    </div>

    <div class="pane">
        <div class="output-header">
            <span>output</span>
            <?php if ($output !== '' || $error !== ''): ?>
            <span class="<?php echo $error ? 'status-err' : 'status-ok'; ?>">
                <?php echo $error ? 'ERROR' : 'OK'; ?>
            </span>
            <?php endif; ?>
        </div>
        <div class="output-area <?php
            if ($error) echo 'err';
            elseif ($output !== '') echo 'ok';
            else echo 'empty';
        ?>">
<?php
if ($output !== '' && $error !== '') {
    echo h($output) . "\n" . h($error);
} elseif ($output !== '') {
    echo h($output);
} elseif ($error) {
    echo h($error);
} else {
    echo '// 実行結果がここに表示されます';
}
?>
        </div>
        <div class="pane-footer">
            <span class="hint">Ctrl+Enter で実行</span>
            <button type="button" class="btn-clear" onclick="copyOutput()">copy</button>
        </div>
    </div>
</main>
</form>
<?php endif; ?>

<script>
var phpSnippets = {
    fxtwitter:
'function fx_get($tweet_id) {\n' +
'    $ch = curl_init("https://api.fxtwitter.com/i/status/" . $tweet_id);\n' +
'    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);\n' +
'    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0");\n' +
'    curl_setopt($ch, CURLOPT_TIMEOUT, 10);\n' +
'    $res = curl_exec($ch);\n' +
'    curl_close($ch);\n' +
'    return json_decode($res, true);\n' +
'}\n' +
'$data = fx_get(\'2038563912409571722\');\n' +
'echo $data[\'tweet\'][\'text\'] . "\\n";\n' +
'echo "reply_to: " . $data[\'tweet\'][\'replying_to_id\'];',

    thread:
'function fx_get($tweet_id) {\n' +
'    $ch = curl_init("https://api.fxtwitter.com/status/" . $tweet_id);\n' +
'    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);\n' +
'    curl_setopt($ch, CURLOPT_USERAGENT, "Mozilla/5.0");\n' +
'    curl_setopt($ch, CURLOPT_TIMEOUT, 10);\n' +
'    $res = curl_exec($ch);\n' +
'    curl_close($ch);\n' +
'    return json_decode($res, true);\n' +
'}\n' +
'function fetch_thread($tweet_id, $depth) {\n' +
'    if ($depth > 10) return array();\n' +
'    $data = fx_get($tweet_id);\n' +
'    if (empty($data[\'tweet\'])) return array();\n' +
'    $tweet = $data[\'tweet\'];\n' +
'    $result = array();\n' +
'    if (!empty($tweet[\'replying_to_id\'])) {\n' +
'        $result = fetch_thread($tweet[\'replying_to_id\'], $depth + 1);\n' +
'    }\n' +
'    $result[] = array(\n' +
'        \'user\' => $tweet[\'author\'][\'screen_name\'],\n' +
'        \'text\' => $tweet[\'text\'],\n' +
'    );\n' +
'    return $result;\n' +
'}\n' +
'$thread = fetch_thread(\'2038563912409571722\', 0);\n' +
'foreach ($thread as $t) {\n' +
'    echo "@" . $t[\'user\'] . ": " . $t[\'text\'] . "\\n\\n";\n' +
'}',

    json:
'$data = array(\'name\' => \'test\', \'value\' => 42);\n' +
'echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);'
};

var cmdSnippets = {
    fxtwitter: 'curl -s "https://api.fxtwitter.com/status/2038563912409571722"',
    thread:    'curl -s "https://api.fxtwitter.com/status/2038563912409571722" | python3 -m json.tool',
    json:      'echo \'{"test":1}\' | python3 -m json.tool'
};

var currentMode = '<?php echo $mode; ?>';

function switchMode(m, e) {
    currentMode = m;
    document.getElementById('mode_input').value = m;
    document.querySelectorAll('.tab').forEach(function(t) { t.classList.remove('active'); });
    if (e && e.currentTarget) {
        e.currentTarget.classList.add('active');
    } else if (e && e.target) {
        e.target.classList.add('active');
    }
    var snippetControls = document.getElementById('snippet-controls');
    if (snippetControls) {
        snippetControls.style.display = m === 'chat' ? 'none' : 'flex';
    }
    var codeArea = document.getElementById('code');
    if (codeArea) {
        codeArea.value = '';
        codeArea.placeholder = m === 'chat' ? 'Ollama に質問を入力してください…' : 'PHP またはコマンドを入力してください…';
    }
}

function setSnippet(key) {
    var s = currentMode === 'cmd' ? cmdSnippets[key] : phpSnippets[key];
    document.getElementById('code').value = s || '';
}

function copyOutput() {
    var el = document.querySelector('.output-area');
    if (!el) return;
    var text = el.innerText;
    if (navigator.clipboard) {
        navigator.clipboard.writeText(text);
    } else {
        var ta = document.createElement('textarea');
        ta.value = text;
        document.body.appendChild(ta);
        ta.select();
        document.execCommand('copy');
        document.body.removeChild(ta);
    }
}

var ta = document.getElementById('code');
if (ta) {
    ta.addEventListener('keydown', function(e) {
        if (e.key === 'Tab') {
            e.preventDefault();
            var s = this.selectionStart;
            this.value = this.value.substring(0, s) + '    ' + this.value.substring(this.selectionEnd);
            this.selectionStart = this.selectionEnd = s + 4;
        }
        if (e.key === 'Enter' && e.ctrlKey) {
            document.getElementById('form').submit();
        }
    });
}

var form = document.getElementById('form');
if (form) {
    form.addEventListener('submit', function(e) {
        var btn = document.getElementById('run-button');
        var status = document.getElementById('run-status');
        if (btn) {
            btn.disabled = true;
            if (status) {
                status.textContent = '実行中... 少々お待ちください';
            }
        }
    });
}
</script>
</body>
</html>
