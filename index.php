<?php
session_start();
if (!function_exists('str_starts_with')) {
    function str_starts_with($haystack, $needle) {
        return $needle !== '' && strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}

define('XAI_API_KEY', 'XXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXXX'); // API KEY
define('CHAT_PASSWORD', 'XXXXXXXXPASSS');                                    // PASSWORD
define('DATA_DIR',      __DIR__ . '/data');
define('AVATARS_DIR',   __DIR__ . '/avatars');
define('IMG_DIR',       __DIR__ . '/img');

foreach ([DATA_DIR, AVATARS_DIR, IMG_DIR] as $d) {
    if (!is_dir($d)) mkdir($d, 0755, true);
}

function chars_file()  { return DATA_DIR . '/characters.json'; }
function load_chars() {
    $f = chars_file();
    if (!file_exists($f)) return [];
    $d = json_decode(file_get_contents($f), true);
    return is_array($d) ? $d : [];
}
function save_chars(array $c) {
    file_put_contents(chars_file(), json_encode($c, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}
function get_char(string $id): ?array {
    $c = load_chars();
    return $c[$id] ?? null;
}

function default_char(): array {
    return [
        'id'          => 'default',
        'name'        => 'xAI',
        'description' => 'A regular AI bot.',
        'prompt'      => 'You are a regular AI bot that helps and answers questions.',
        'avatar'      => null,
        'created_at'  => time(),
    ];
}

function ensure_default_char() {
    $c = load_chars();
    if (empty($c)) {
        $c['default'] = default_char();
        save_chars($c);
    }
}
ensure_default_char();

function history_file(string $char_id) {
    return DATA_DIR . '/' . preg_replace('/[^a-z0-9_-]/i', '_', $char_id) . '.json';
}
function load_history(string $char_id): array {
    $f = history_file($char_id);
    if (!file_exists($f)) return [];
    $d = json_decode(file_get_contents($f), true);
    return is_array($d) ? $d : [];
}
function save_history(string $char_id, array $h) {
    if (count($h) > 200) $h = array_slice($h, -200);
    file_put_contents(history_file($char_id), json_encode($h, JSON_UNESCAPED_UNICODE));
}

function active_char_id(): string {
    $chars = load_chars();
    $id = $_SESSION['char_id'] ?? 'default';
    return isset($chars[$id]) ? $id : array_key_first($chars);
}

if (isset($_POST['logout'])) {
    session_destroy();
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}
if (isset($_POST['password'])) {
    if ($_POST['password'] === CHAT_PASSWORD) {
        $_SESSION['auth'] = true;
    } else {
        $login_error = 'Incorrect password';
    }
}
$authed = !empty($_SESSION['auth']);

function xai_complete(array $messages, int $max_tokens = 512): string {
    $payload = json_encode([
        'model'       => 'grok-3-fast',
        'messages'    => $messages,
        'temperature' => 0.3,
        'max_tokens'  => $max_tokens,
        'stream'      => false,
    ]);
    $ch = curl_init('https://api.x.ai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . XAI_API_KEY,
        ],
        CURLOPT_POSTFIELDS => $payload,
    ]);
    $res = curl_exec($ch);
    curl_close($ch);
    $r = json_decode($res, true);
    return trim($r['choices'][0]['message']['content'] ?? '');
}

if ($authed && isset($_GET['action'])) {

    header('Content-Type: application/json; charset=utf-8');

    if ($_GET['action'] === 'chars_list') {
        $chars = load_chars();
        $active = active_char_id();
        $out = [];
        foreach ($chars as $id => $c) {
            $out[] = [
                'id'          => $id,
                'name'        => $c['name'],
                'description' => $c['description'] ?? '',
                'avatar'      => $c['avatar'] ?? null,
                'active'      => ($id === $active),
            ];
        }
        echo json_encode($out);
        exit();
    }

    if ($_GET['action'] === 'char_save') {
        $id   = trim($_POST['id']   ?? '');
        $name = trim($_POST['name'] ?? '');
        $desc = trim($_POST['description'] ?? '');
        $prmt = trim($_POST['prompt'] ?? '');

        if (!$name) { echo json_encode(['error' => 'No name']); exit(); }

        $chars = load_chars();

        if (!$id) {
            $id = 'char_' . time() . '_' . rand(100, 999);
        }

        $existing = $chars[$id] ?? [];

        $chars[$id] = array_merge($existing, [
            'id'          => $id,
            'name'        => $name,
            'description' => $desc,
            'prompt'      => $prmt ?: "Your name {$name}. {$desc}",
            'avatar'      => $existing['avatar'] ?? null,
            'created_at'  => $existing['created_at'] ?? time(),
        ]);

        save_chars($chars);
        echo json_encode(['ok' => true, 'id' => $id, 'char' => $chars[$id]]);
        exit();
    }

    if ($_GET['action'] === 'char_avatar') {
        $id = trim($_POST['id'] ?? '');
        if (!$id) { echo json_encode(['error' => 'Not ID']); exit(); }

        $chars = load_chars();
        if (!isset($chars[$id])) { echo json_encode(['error' => 'Character not found']); exit(); }

        if (!isset($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['error' => 'The file was not uploaded.']); exit();
        }

        $file = $_FILES['avatar'];
        $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg','jpeg','png','gif','webp'])) {
            echo json_encode(['error' => 'Invalid format']); exit();
        }

        if (!empty($chars[$id]['avatar'])) {
            $old = __DIR__ . '/' . ltrim($chars[$id]['avatar'], './');
            if (file_exists($old)) unlink($old);
        }

        $fname = 'avatar_' . $id . '_' . time() . '.' . $ext;
        $dest  = AVATARS_DIR . '/' . $fname;
        move_uploaded_file($file['tmp_name'], $dest);

        $chars[$id]['avatar'] = './avatars/' . $fname;
        save_chars($chars);

        echo json_encode(['ok' => true, 'avatar' => $chars[$id]['avatar']]);
        exit();
    }

    if ($_GET['action'] === 'char_delete') {
        $id = trim($_POST['id'] ?? '');
        if ($id === 'default') { echo json_encode(['error' => 'Cannot delete default']); exit(); }

        $chars = load_chars();
        if (isset($chars[$id])) {
            if (!empty($chars[$id]['avatar'])) {
                $av = __DIR__ . '/' . ltrim($chars[$id]['avatar'], './');
                if (file_exists($av)) unlink($av);
            }
            $hf = history_file($id);
            if (file_exists($hf)) unlink($hf);

            unset($chars[$id]);
            save_chars($chars);
        }
        if (active_char_id() === $id) {
            $_SESSION['char_id'] = 'default';
        }
        echo json_encode(['ok' => true]);
        exit();
    }

    if ($_GET['action'] === 'char_switch') {
        $id = trim($_POST['id'] ?? '');
        $chars = load_chars();
        if (!isset($chars[$id])) { echo json_encode(['error' => 'Not found']); exit(); }
        $_SESSION['char_id'] = $id;
        echo json_encode(['ok' => true, 'char' => $chars[$id]]);
        exit();
    }

    if ($_GET['action'] === 'char_active') {
        $id  = active_char_id();
        $c   = get_char($id);
        echo json_encode($c ?: default_char());
        exit();
    }

    if ($_GET['action'] === 'history') {
        $id = active_char_id();
        echo json_encode(load_history($id));
        exit();
    }

    if ($_GET['action'] === 'reset') {
        $id = active_char_id();
        $f  = history_file($id);
        if (file_exists($f)) unlink($f);
        echo json_encode(['ok' => true]);
        exit();
    }

    if ($_GET['action'] === 'detect_intent') {
        $text    = trim($_POST['message'] ?? '');
        $history = load_history(active_char_id());
        $ctx_msgs = [];
        foreach (array_slice($history, -6) as $h) {
            $ctx_msgs[] = ['role' => $h['role'], 'content' => $h['content']];
        }
        $p = "You determine the user's intent in the chat.\n\nContext:\n";
        foreach ($ctx_msgs as $c) { $p .= "[{$c['role']}]: {$c['content']}\n"; }
        $p .= "\nNew message: «{$text}»\n\nAnswer ONLY with one word: IMAGE — if you want to draw/generate/display an image. TEXT — in all other cases..";
        $intent = xai_complete([['role' => 'user', 'content' => $p]], 10);
        $intent = (stripos($intent, 'IMAGE') !== false) ? 'IMAGE' : 'TEXT';
        echo json_encode(['intent' => $intent]);
        exit();
    }

    if ($_GET['action'] === 'enhance_prompt') {
        $text    = trim($_POST['message'] ?? '');
        $history = load_history(active_char_id());
        $ctx_msgs = [];
        foreach (array_slice($history, -6) as $h) {
            $ctx_msgs[] = ['role' => $h['role'], 'content' => $h['content']];
        }
        $p = "You are an expert in creating prompts for image generation..\n\nContext:\n";
        foreach ($ctx_msgs as $c) { $p .= "[{$c['role']}]: {$c['content']}\n"; }
        $p .= "\nRequest: «{$text}»\n\nWrite one short, detailed prompt in English. Return ONLY the prompt text, without quotation marks or explanations..";
        $enhanced = xai_complete([['role' => 'user', 'content' => $p]], 256);
        echo json_encode(['prompt' => $enhanced ?: $text]);
        exit();
    }

    if ($_GET['action'] === 'image') {
        $prompt = trim($_POST['prompt'] ?? '');
        if (!$prompt) { echo json_encode(['error' => 'No prompt']); exit(); }

        $payload = json_encode([
            'model'           => 'grok-imagine-image',
            'prompt'          => $prompt,
            'n'               => 1,
            'response_format' => 'url',
        ]);
        $ch = curl_init('https://api.x.ai/v1/images/generations');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . XAI_API_KEY],
            CURLOPT_POSTFIELDS     => $payload,
        ]);
        $res  = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code !== 200) { echo json_encode(['error' => "API $code: $res"]); exit(); }
        $r   = json_decode($res, true);
        $url = $r['data'][0]['url'] ?? null;
        if (!$url) { echo json_encode(['error' => 'URL not received']); exit(); }

        $local_url = $url;
        $img_data  = @file_get_contents($url);
        if ($img_data) {
            $filename  = 'img_' . time() . '_' . rand(1000, 9999) . '.jpg';
            file_put_contents(IMG_DIR . '/' . $filename, $img_data);
            $local_url = './img/' . $filename;
        }

        $char_id = active_char_id();
        $original_request = trim($_POST['original_request'] ?? $prompt);
        $h   = load_history($char_id);
        $h[] = ['role' => 'user',      'content' => $original_request, 'type' => 'image_request'];
        $h[] = ['role' => 'assistant', 'content' => $local_url,        'type' => 'image'];
        save_history($char_id, $h);

        echo json_encode(['url' => $local_url, 'prompt_used' => $prompt]);
        exit();
    }

     if ($_GET['action'] === 'stream') {
        $text = trim($_POST['message'] ?? '');
        if (!$text) exit();

        $char_id = active_char_id();
        $char    = get_char($char_id) ?? default_char();
        $system  = $char['prompt'];

        $history   = load_history($char_id);
        $history[] = ['role' => 'user', 'content' => $text];

        $messages = [['role' => 'system', 'content' => $system]];
        foreach ($history as $h) {
            if (($h['type'] ?? '') === 'image') continue;
            $messages[] = ['role' => $h['role'], 'content' => $h['content']];
        }

        $payload = json_encode([
            'model'       => 'grok-4-1-fast-non-reasoning',
            'messages'    => $messages,
            'temperature' => 0.7,
            'max_tokens'  => 4096,
            'stream'      => true,
        ]);

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        if (ob_get_level()) ob_end_clean();

        $accumulated = '';
        $buffer      = '';

        $ch = curl_init('https://api.x.ai/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_POST          => true,
            CURLOPT_HTTPHEADER    => ['Content-Type: application/json', 'Authorization: Bearer ' . XAI_API_KEY],
            CURLOPT_POSTFIELDS    => $payload,
            CURLOPT_WRITEFUNCTION => function ($ch, $data) use (&$buffer, &$accumulated) {
                $buffer .= $data;
                while (($pos = strpos($buffer, "\n")) !== false) {
                    $line   = trim(substr($buffer, 0, $pos));
                    $buffer = substr($buffer, $pos + 1);
                    if (!str_starts_with($line, 'data:')) continue;
                    $json = trim(substr($line, 5));
                    if ($json === '[DONE]') break;
                    $obj   = json_decode($json, true);
                    $chunk = $obj['choices'][0]['delta']['content'] ?? null;
                    if ($chunk === null || $chunk === '') continue;
                    $accumulated .= $chunk;
                    echo 'data: ' . json_encode(['chunk' => $chunk]) . "\n\n";
                    flush();
                }
                return strlen($data);
            },
        ]);
        curl_exec($ch);
        curl_close($ch);

        if (trim($accumulated) !== '') {
            $history_save   = load_history($char_id);
            $history_save[] = ['role' => 'user',      'content' => $text];
            $history_save[] = ['role' => 'assistant',  'content' => $accumulated];
            save_history($char_id, $history_save);
        }

        echo 'data: ' . json_encode(['done' => true]) . "\n\n";
        flush();
        exit();
    }
}

$active_char = $authed ? (get_char(active_char_id()) ?? default_char()) : default_char();
$char_name   = htmlspecialchars($active_char['name']);
$char_avatar = htmlspecialchars($active_char['avatar'] ?? '');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<title><?= $char_name ?></title>
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg:        #0d0d0f;
    --surface:   #131316;
    --border:    #22222a;
    --accent:    #c9a96e;
    --accent2:   #7c6aad;
    --text:      #e8e4dc;
    --muted:     #5a5760;
    --user-bg:   #1a1824;
    --bot-bg:    #141418;
    --radius:    16px;
}

html, body { height: 100%; }
body {
    background: var(--bg);
    color: var(--text);
    font-family: Arial, sans-serif;
    font-size: 14px;
    line-height: 1.7;
    min-height: 100vh;
    overflow: hidden;
}
body::before {
    content: '';
    position: fixed;
    inset: 0;
    background:
        radial-gradient(ellipse 80% 50% at 20% 80%, rgba(124,106,173,0.06) 0%, transparent 60%),
        radial-gradient(ellipse 60% 40% at 80% 20%, rgba(201,169,110,0.05) 0%, transparent 60%);
    pointer-events: none;
    z-index: 0;
}

/* ─── LOGIN ─────────────────────────────── */
.login-wrap { position:fixed;inset:0;display:flex;align-items:center;justify-content:center;z-index:10; }
.login-box {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 24px;
    padding: 52px 48px;
    width: 360px;
    text-align: center;
    position: relative;
    animation: fadeUp .5s ease both;
}
.login-box::before {
    content:'';position:absolute;top:-1px;left:30%;right:30%;height:2px;
    background:linear-gradient(90deg,transparent,var(--accent),transparent);border-radius:2px;
}
.login-avatar { width:72px;height:72px;border-radius:50%;overflow:hidden;display:flex;align-items:center;justify-content:center;margin:0 auto 24px;box-shadow:0 0 40px rgba(201,169,110,0.15); }
.login-avatar img { width:100%;height:100%;object-fit:cover; }
.login-avatar-placeholder { width:72px;height:72px;border-radius:50%;background:linear-gradient(135deg,var(--accent2),var(--accent));display:flex;align-items:center;justify-content:center;font-size:28px;margin:0 auto 24px; }
.login-title { font-size:32px;font-weight:300;color:var(--accent);letter-spacing:.04em;margin-bottom:6px; }
.login-sub   { color:var(--muted);font-size:12px;margin-bottom:32px;letter-spacing:.08em; }
.login-box input[type=password] {
    width:100%;background:var(--bg);border:1px solid var(--border);border-radius:10px;
    padding:13px 18px;color:var(--text);font-size:14px;outline:none;
    transition:border-color .2s;letter-spacing:.12em;margin-bottom:14px;
}
.login-box input[type=password]:focus { border-color:var(--accent); }
.login-btn {
    width:100%;background:linear-gradient(135deg,var(--accent2),var(--accent));
    border:none;border-radius:10px;padding:13px;color:#fff;font-size:13px;
    letter-spacing:.1em;cursor:pointer;transition:opacity .2s,transform .1s;
}
.login-btn:hover  { opacity:.88; }
.login-btn:active { transform:scale(.98); }
.login-error { color:#e07070;font-size:12px;margin-top:12px; }

/* ─── CHAT LAYOUT ───────────────────────── */
.chat-wrap { display:flex;flex-direction:column;height:100vh;position:relative;z-index:1; }

.chat-header {
    display: flex; align-items: center; gap: 14px;
    padding: 5px 20px; border-bottom: 1px solid var(--border);
    background: rgba(13,13,15,.5); backdrop-filter: blur(12px); flex-shrink: 0;

     position: absolute;
    width: 100%;
    top: 0;
    z-index: 100;
    transition: transform 0.5s ease;
    will-change: transform;
}

.chat-header.hidden {
    transform: translateY(-100%);
}
.header-avatar { width:40px;height:40px;border-radius:50%;overflow:hidden;flex-shrink:0;box-shadow:0 0 20px rgba(201,169,110,0.2); }
.header-avatar img { width:100%;height:100%;object-fit:cover; }
.header-avatar-placeholder { width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,var(--accent2),var(--accent));display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0; }
.header-name   { font-size:15px;font-weight:300;color:var(--accent);letter-spacing:.04em; }
.header-status { font-size:11px;color:var(--muted);letter-spacing:.06em; }
.header-status.online::before {
    content:'';display:inline-block;width:6px;height:6px;border-radius:50%;
    background:#5ecc8a;margin-right:6px;vertical-align:middle;
}
.header-actions { margin-left:auto;display:flex;gap:8px;align-items:center; }
.header-btn {
    background:none;border:1px solid var(--border);border-radius:8px;color:var(--muted);
    padding:6px 12px;font-size:11px;letter-spacing:.06em;cursor:pointer;transition:all .2s;white-space:nowrap;
}
.header-btn:hover { border-color:var(--accent);color:var(--accent); }

/* ─── Messages ───────────────────────────── */
.messages { flex:1;overflow-y:auto;padding:28px 0px;scroll-behavior:smooth; }
.messages::-webkit-scrollbar { width:4px; }
.messages::-webkit-scrollbar-track { background:transparent; }
.messages::-webkit-scrollbar-thumb { background:var(--border);border-radius:2px; }

.msg-row { display:flex;padding:6px 28px;gap:12px;animation:fadeUp .25s ease both; }
.msg-row.user { flex-direction:row-reverse; }
.msg-avatar { width:32px;height:32px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:14px;margin-top:4px;overflow:hidden; }
.msg-avatar img { width:100%;height:100%;object-fit:cover; }
.msg-row.bot  .msg-avatar { background:linear-gradient(135deg,var(--accent2),var(--accent)); }
.msg-row.user .msg-avatar { background:var(--user-bg);border:1px solid var(--border); }
.msg-bubble { min-width:70%;padding:12px 16px;border-radius:var(--radius);font-size:11px;line-height:1.7;white-space:pre-wrap;word-break:break-word; }
.msg-row > div:last-child { min-width:0;max-width:calc(100% - 50px); }
.msg-row.bot  .msg-bubble { background:var(--bot-bg);border:1px solid var(--border);border-top-left-radius:4px; }
.msg-row.user .msg-bubble { background:var(--user-bg);border:1px solid #2a2840;border-top-right-radius:4px; }
.msg-bubble img { max-width:100%;border-radius:10px;display:block;margin-top:4px; }
.msg-time { font-size:10px;color:var(--muted);margin-top:5px;letter-spacing:.04em; }
.msg-row.user .msg-time { text-align:right; }

/* Typing */
.typing-dots { display:flex;gap:5px;align-items:center;padding:6px 2px; }
.typing-dots span { width:6px;height:6px;border-radius:50%;background:var(--accent);animation:dot-bounce .9s ease-in-out infinite; }
.typing-dots span:nth-child(2) { animation-delay:.15s; }
.typing-dots span:nth-child(3) { animation-delay:.3s; }

.date-sep { text-align:center;font-size:11px;color:var(--muted);letter-spacing:.1em;padding:12px 0;position:relative; }
.date-sep::before,.date-sep::after { content:'';position:absolute;top:50%;width:30%;height:1px;background:var(--border); }
.date-sep::before { left:5%; }
.date-sep::after  { right:5%; }

.prompt-hint { font-size:10px;color:var(--accent2);margin-top:3px;padding-left:2px;font-style:italic;opacity:.7; }

/* Input */
.input-area { padding:10px 25px 5px;border-top:1px solid var(--border);background:rgba(13,13,15,.9);backdrop-filter:blur(12px);flex-shrink:0; }
.input-row { display:flex;gap:10px;align-items:center;background:var(--surface);border:1px solid var(--border);border-radius:16px;padding:5px 5px 5px 12px;transition:border-color .2s; }
.input-row:focus-within { border-color:rgba(201,169,110,.4); }
#msg-input { flex:1;background:none;border:none;outline:none;color:var(--text);font-size:14px;line-height:1.6;resize:none;max-height:140px;min-height:24px;padding:2px 0; }
#msg-input::placeholder { color:var(--muted); }
#msg-input:disabled { opacity:0.5; }
.send-btn,.img-btn { width:38px;height:38px;border-radius:10px;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;transition:all .2s; }
.send-btn { background:linear-gradient(135deg,var(--accent2),var(--accent));color:#fff; }
.send-btn:hover  { opacity:.85;transform:scale(1.05); }
.send-btn:active { transform:scale(.95); }
.send-btn:disabled,.img-btn:disabled { opacity:0.4;cursor:not-allowed;transform:none; }
.img-btn { background:var(--bg);border:1px solid var(--border);color:var(--muted); }
.img-btn:hover { border-color:var(--accent);color:var(--accent); }
.input-hint { font-size:11px;color:var(--muted);margin-top:8px;padding-left:4px;letter-spacing:.04em; }

/* Empty state */
.empty-state { display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;gap:12px;color:var(--muted); }
.empty-state .big-avatar { width:80px;height:80px;border-radius:50%;overflow:hidden;display:flex;align-items:center;justify-content:center;font-size:36px;box-shadow:0 0 60px rgba(201,169,110,0.12);margin-bottom:8px; }
.empty-state .big-avatar img { width:100%;height:100%;object-fit:cover; }
.empty-state .big-avatar-placeholder { width:80px;height:80px;border-radius:50%;background:linear-gradient(135deg,var(--accent2),var(--accent));display:flex;align-items:center;justify-content:center;font-size:36px; }
.empty-state h2 { font-size:28px;font-weight:300;color:var(--accent);letter-spacing:.04em; }
.empty-state p  { font-size:12px;letter-spacing:.06em; }
.chat-header.hidden {
  transform: translateY(-100%);
}
/* Markdown */
.msg-bubble strong { color:var(--accent);font-weight:600; }
.msg-bubble em     { font-style:italic;color:#b8b4cc; }
.msg-bubble code   { background:rgba(255,255,255,.07);padding:2px 6px;border-radius:4px;font-size:13px; }

/* ─── MODAL ─────────────────────────────── */
.modal-overlay {
    position:fixed;inset:0;background:rgba(0,0,0,.6);backdrop-filter:blur(4px);
    z-index:100;display:flex;align-items:center;justify-content:center;
    animation:fadeIn .2s ease;
}
.modal-box {
    background:var(--surface);border:1px solid var(--border);border-radius:20px;
    padding:32px;width:480px;max-width:94vw;max-height:90vh;overflow-y:auto;
    position:relative;animation:fadeUp .25s ease;
}
.modal-title { font-size:18px;font-weight:400;color:var(--accent);margin-bottom:24px;letter-spacing:.04em; }
.modal-close {
    position:absolute;top:16px;right:16px;background:none;border:none;
    color:var(--muted);font-size:18px;cursor:pointer;line-height:1;padding:4px 8px;
    border-radius:6px;transition:color .2s;
}
.modal-close:hover { color:var(--text); }

/* Form elements */
.form-group { margin-bottom:16px; }
.form-label { display:block;font-size:11px;color:var(--muted);letter-spacing:.08em;margin-bottom:6px;text-transform:uppercase; }
.form-input,.form-textarea {
    width:100%;background:var(--bg);border:1px solid var(--border);border-radius:10px;
    padding:10px 14px;color:var(--text);font-size:13px;outline:none;transition:border-color .2s;
    font-family:inherit;
}
.form-input:focus,.form-textarea:focus { border-color:var(--accent); }
.form-textarea { resize:vertical;min-height:80px;line-height:1.5; }

.btn { padding:9px 18px;border-radius:9px;border:none;cursor:pointer;font-size:13px;letter-spacing:.06em;transition:all .2s; }
.btn-primary { background:linear-gradient(135deg,var(--accent2),var(--accent));color:#fff; }
.btn-primary:hover { opacity:.88; }
.btn-secondary { background:var(--bg);border:1px solid var(--border);color:var(--muted); }
.btn-secondary:hover { border-color:var(--accent);color:var(--accent); }
.btn-danger { background:rgba(224,112,112,.12);border:1px solid rgba(224,112,112,.3);color:#e07070; }
.btn-danger:hover { background:rgba(224,112,112,.2); }
.btn-row { display:flex;gap:10px;margin-top:20px;flex-wrap:wrap; }

/* Chars list */
.chars-list { display:flex;flex-direction:column;gap:10px;margin-bottom:20px; }
.char-item {
    display:flex;align-items:center;gap:12px;padding:12px 14px;
    background:var(--bg);border:1px solid var(--border);border-radius:12px;
    cursor:pointer;transition:border-color .2s;
}
.char-item:hover { border-color:var(--accent2); }
.char-item.active { border-color:var(--accent); }
.char-item-avatar { width:40px;height:40px;border-radius:50%;overflow:hidden;flex-shrink:0;background:linear-gradient(135deg,var(--accent2),var(--accent));display:flex;align-items:center;justify-content:center;font-size:18px; }
.char-item-avatar img { width:100%;height:100%;object-fit:cover; }
.char-item-info { flex:1;min-width:0; }
.char-item-name { font-size:14px;color:var(--text);font-weight:500; }
.char-item-desc { font-size:11px;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;margin-top:2px; }
.char-item-actions { display:flex;gap:6px;flex-shrink:0; }
.icon-btn { background:none;border:1px solid var(--border);border-radius:7px;color:var(--muted);padding:5px 8px;font-size:12px;cursor:pointer;transition:all .2s; }
.icon-btn:hover { border-color:var(--accent);color:var(--accent); }
.icon-btn.danger:hover { border-color:#e07070;color:#e07070; }
.active-badge { font-size:10px;background:rgba(201,169,110,.15);color:var(--accent);border-radius:4px;padding:2px 6px;margin-left:6px; }

/* Avatar upload preview */
.avatar-upload-area {
    border:2px dashed var(--border);border-radius:12px;padding:20px;text-align:center;
    cursor:pointer;transition:border-color .2s;color:var(--muted);font-size:12px;
}
.avatar-upload-area:hover { border-color:var(--accent); }
.avatar-preview { width:80px;height:80px;border-radius:50%;overflow:hidden;margin:0 auto 12px;background:linear-gradient(135deg,var(--accent2),var(--accent));display:flex;align-items:center;justify-content:center;font-size:32px; }
.avatar-preview img { width:100%;height:100%;object-fit:cover; }

/* Animations */
@keyframes fadeUp { from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)} }
@keyframes fadeIn { from{opacity:0}to{opacity:1} }
@keyframes dot-bounce { 0%,80%,100%{transform:translateY(0);opacity:.5}40%{transform:translateY(-6px);opacity:1} }

/* Mobile */
@media(max-width:600px) {
    .chat-header { padding:10px 14px; }
    .msg-row     { padding:5px 14px; }
    .input-area  { padding:12px 14px 18px; }
    .login-box   { width:90%;padding:40px 28px; }
    .header-btn  { padding:5px 9px;font-size:10px; }
}
</style>
</head>
<body>

<?php if (!$authed): ?>
<!-- ─── LOGIN ─────────────────────────────── -->
<div class="login-wrap">
  <div class="login-box">
    <?php if ($char_avatar): ?>
      <div class="login-avatar"><img src="<?= $char_avatar ?>" alt="avatar"></div>
    <?php else: ?>
      <div class="login-avatar-placeholder">🤖</div>
    <?php endif; ?>
    <div class="login-title"><?= $char_name ?></div>
    <div class="login-sub">PERSONAL CHAT</div>
    <form method="POST">
      <input type="password" name="password" placeholder="••••••••" autofocus autocomplete="current-password">
      <button type="submit" class="login-btn">LOGIN</button>
      <?php if (!empty($login_error)): ?>
        <div class="login-error"><?= htmlspecialchars($login_error) ?></div>
      <?php endif; ?>
    </form>
  </div>
</div>

<?php else: ?>
<!-- ─── CHAT ──────────────────────────────── -->
<div class="chat-wrap">
  <div class="chat-header">
    <div id="header-avatar-wrap">
      <?php if ($char_avatar): ?>
        <div class="header-avatar"><img src="<?= $char_avatar ?>" alt="avatar"></div>
      <?php else: ?>
        <div class="header-avatar-placeholder">🤖</div>
      <?php endif; ?>
    </div>
    <div>
      <div class="header-name" id="header-name"><?= $char_name ?></div>
      <div class="header-status online" id="status-text">online</div>
    </div>
    <div class="header-actions">
      <button class="header-btn" onclick="openCharsModal()">👤 characters</button>
      <button class="header-btn" onclick="resetChat()">↺ reset</button>
      <form method="POST" style="display:inline">
        <button name="logout" value="1" class="header-btn">exit</button>
      </form>
    </div>
  </div>

  <div class="messages" id="messages">
    <div class="empty-state" id="empty-state">
      <div id="empty-avatar">
        <?php if ($char_avatar): ?>
          <div class="big-avatar"><img src="<?= $char_avatar ?>" alt="avatar"></div>
        <?php else: ?>
          <div class="big-avatar-placeholder">🤖</div>
        <?php endif; ?>
      </div>
      <h2 id="empty-name"><?= $char_name ?></h2>
      <p>write something...</p>
    </div>
  </div>

  <div class="input-area">
    <div class="input-row" id="input-row">
      <textarea id="msg-input" placeholder="Write..." rows="1" autofocus></textarea>
      <button class="img-btn" id="img-btn" onclick="sendImageManual()" title="Generate an image">🎨</button>
      <button class="send-btn" onmousedown="event.preventDefault()" id="send-btn" onclick="sendMessage()" title="Send">➤</button>
    </div>
     <div class="input-hint">Enter — send · Shift+Enter — transfer · 🎨 — hand-painted picture</div>
  </div>
</div>

<div class="modal-overlay" id="chars-modal" style="display:none" onclick="if(event.target===this)closeCharsModal()">
  <div class="modal-box">
    <button class="modal-close" onclick="closeCharsModal()">✕</button>
    <div class="modal-title">👤 Characters</div>
    <div class="chars-list" id="chars-list"><!-- JS --></div>
    <button class="btn btn-primary" onclick="openCharForm()">+ Create a character</button>
  </div>
</div>

<div class="modal-overlay" id="char-form-modal" style="display:none" onclick="if(event.target===this)closeCharForm()">
  <div class="modal-box">
    <button class="modal-close" onclick="closeCharForm()">✕</button>
    <div class="modal-title" id="char-form-title">Create a character</div>

    <input type="hidden" id="cf-id">

    <div class="form-group">
      <label class="form-label">Name *</label>
      <input type="text" class="form-input" id="cf-name" placeholder="For example: Alice">
    </div>
    <div class="form-group">
      <label class="form-label">Description (short)</label>
      <input type="text" class="form-input" id="cf-desc" placeholder="For example: good witch">
    </div>
    <div class="form-group">
      <label class="form-label">System prompt</label>
      <textarea class="form-textarea" id="cf-prompt" rows="5" placeholder="Your name is Alice. You are a good sorceress..."></textarea>
    </div>
    <div class="form-group">
      <label class="form-label">Avatar</label>
      <div class="avatar-upload-area" onclick="document.getElementById('cf-avatar-input').click()">
        <div class="avatar-preview" id="cf-avatar-preview">🤖</div>
        <div>Click to download (JPG, PNG, WebP)</div>
        <input type="file" id="cf-avatar-input" accept="image/*" style="display:none" onchange="previewAvatar(this)">
      </div>
    </div>

    <div class="btn-row">
      <button class="btn btn-primary" onclick="saveChar()">Save</button>
      <button class="btn btn-secondary" onclick="closeCharForm()">Cencel</button>
    </div>
  </div>
</div>

<script>
// ─── State ────────────────────────────────
const messagesEl = document.getElementById('messages');
const inputEl    = document.getElementById('msg-input');
const statusEl   = document.getElementById('status-text');
const emptyEl    = document.getElementById('empty-state');
const sendBtn    = document.getElementById('send-btn');
const imgBtn     = document.getElementById('img-btn');

let isBusy       = false;
let activeChar   = <?= json_encode($active_char) ?>;

// ─── UI helpers ───────────────────────────
function setInputBusy(busy) {
    isBusy = busy;
    inputEl.readOnly = busy; 
    sendBtn.disabled = imgBtn.disabled = busy;
    inputEl.style.opacity = busy ? '0.7' : '1';
}
function now() { return new Date().toLocaleTimeString('ru',{hour:'2-digit',minute:'2-digit'}); }
function makeDateSep(label) { const d=document.createElement('div');d.className='date-sep';d.textContent=label;return d; }
function scrollBottom() { messagesEl.scrollTo({top:messagesEl.scrollHeight,behavior:'smooth'}); }
function hideEmpty() { if (emptyEl) emptyEl.style.display='none'; }
function escHtml(t) { return String(t).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function renderMarkdown(text) {
    return text
        .replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>')
        .replace(/\*(.+?)\*/g,'<em>$1</em>')
        .replace(/`(.+?)`/g,'<code>$1</code>');
}

function botAvatarHtml() {
    if (activeChar && activeChar.avatar) {
        return `<img src="${escHtml(activeChar.avatar)}" style="width:100%;height:100%;object-fit:cover;border-radius:50%;">`;
    }
    return '🤖';
}

function updateHeaderUI(char) {
    activeChar = char;
    // header avatar
    const wrap = document.getElementById('header-avatar-wrap');
    if (char.avatar) {
        wrap.innerHTML = `<div class="header-avatar"><img src="${escHtml(char.avatar)}" alt="avatar"></div>`;
    } else {
        wrap.innerHTML = `<div class="header-avatar-placeholder">🤖</div>`;
    }
    document.getElementById('header-name').textContent = char.name;
    // empty state
    const ea = document.getElementById('empty-avatar');
    if (char.avatar) {
        ea.innerHTML = `<div class="big-avatar"><img src="${escHtml(char.avatar)}" alt="avatar"></div>`;
    } else {
        ea.innerHTML = `<div class="big-avatar-placeholder">🤖</div>`;
    }
    document.getElementById('empty-name').textContent = char.name;
    inputEl.placeholder = 'Write ' + char.name + '...';
}

function appendMessage(role, text, animate=true) {
    hideEmpty();
    const row = document.createElement('div');
    row.className = 'msg-row ' + role;
    if (!animate) row.style.animation = 'none';
    const avatar = role === 'bot' ? `<div class="msg-avatar">${botAvatarHtml()}</div>` : `<div class="msg-avatar">👤</div>`;
    row.innerHTML = `${avatar}<div><div class="msg-bubble">${renderMarkdown(escHtml(text))}</div><div class="msg-time">${now()}</div></div>`;
    messagesEl.appendChild(row);
    scrollBottom();
    return row;
}

function appendImage(role, url, animate=true, promptUsed='') {
    hideEmpty();
    const row = document.createElement('div');
    row.className = 'msg-row ' + role;
    if (!animate) row.style.animation = 'none';
    const hint = promptUsed ? `<div class="prompt-hint">prompt: ${escHtml(promptUsed)}</div>` : '';
    const avatar = role === 'bot' ? `<div class="msg-avatar">${botAvatarHtml()}</div>` : `<div class="msg-avatar">👤</div>`;
    row.innerHTML = `${avatar}<div><div class="msg-bubble"><img src="${escHtml(url)}" alt="image" loading="lazy"></div>${hint}<div class="msg-time">${now()}</div></div>`;
    messagesEl.appendChild(row);
    scrollBottom();
}

function showTyping(label='') {
    const row = document.createElement('div');
    row.className = 'msg-row bot';
    row.id = 'typing-row';
    row.innerHTML = `<div class="msg-avatar">${botAvatarHtml()}</div><div><div class="msg-bubble"><div class="typing-dots"><span></span><span></span><span></span></div>${label?`<div style="font-size:11px;color:var(--muted);margin-top:4px;">${escHtml(label)}</div>`:''}</div></div>`;
    messagesEl.appendChild(row);
    scrollBottom();
}
function removeTyping() { const el=document.getElementById('typing-row');if(el)el.remove(); }
function setStatus(text, online=true) { statusEl.textContent=text;statusEl.className='header-status'+(online?' online':''); }

// ─── History ──────────────────────────────
async function loadHistory() {
    const res  = await fetch('?action=history');
    const hist = await res.json();
    if (!hist.length) return;
    emptyEl.style.display = 'none';
    messagesEl.appendChild(makeDateSep('History'));
    hist.forEach(msg => {
        if (msg.type === 'image')         appendImage('bot', msg.content, false);
        else if (msg.type === 'image_request') appendMessage('user', '🎨 ' + msg.content, false);
        else appendMessage(msg.role === 'user' ? 'user' : 'bot', msg.content, false);
    });
    scrollBottom();
}

// ─── Intent / Image ───────────────────────
async function detectIntent(text) {
    const fd = new FormData(); fd.append('message', text);
    const res = await fetch('?action=detect_intent',{method:'POST',body:fd});
    return (await res.json()).intent || 'TEXT';
}
async function enhancePrompt(text) {
    const fd = new FormData(); fd.append('message', text);
    const res = await fetch('?action=enhance_prompt',{method:'POST',body:fd});
    return (await res.json()).prompt || text;
}
async function doGenerateImage(originalText, enhancedPrompt) {
    const fd = new FormData();
    fd.append('prompt', enhancedPrompt);
    fd.append('original_request', originalText);
    const res  = await fetch('?action=image',{method:'POST',body:fd});
    const data = await res.json();
    removeTyping(); setStatus('online',true); setInputBusy(false);
    inputEl.focus(); 
    if (data.url) appendImage('bot', data.url, true, data.prompt_used !== originalText ? data.prompt_used : '');
    else          appendMessage('bot', '😔 It didn\'t work out: ' + (data.error || 'unknown error'));
}

// ─── Send ─────────────────────────────────
async function sendMessage() {
    if (isBusy) return;
    const text = inputEl.value.trim();
    if (!text) return;
    inputEl.value = ''; inputEl.style.height = 'auto';
    setInputBusy(true);
    setStatus('analyzes...',true);
    showTyping('Think...');
    let intent;
    try { intent = await detectIntent(text); } catch(e) { intent = 'TEXT'; }
    removeTyping();
    if (intent === 'IMAGE') {
        appendMessage('user', '🎨 ' + text);
        setStatus('improves prompt...',true); showTyping('I\'m improving the prompt...');
        let enhanced = text;
        try { enhanced = await enhancePrompt(text); } catch(e) {}
        removeTyping(); setStatus('draws...',true); showTyping('I\'m drawing...');
        await doGenerateImage(text, enhanced);
    } else {
        appendMessage('user', text);
        showTyping(); setStatus('typing...',true);
        const fd = new FormData(); fd.append('message', text);
        const response = await fetch('?action=stream',{method:'POST',body:fd});
        const reader   = response.body.getReader();
        const decoder  = new TextDecoder();
        removeTyping(); hideEmpty();
        const row = document.createElement('div');
        row.className = 'msg-row bot';
        row.innerHTML = `<div class="msg-avatar">${botAvatarHtml()}</div><div><div class="msg-bubble" id="stream-bubble"></div><div class="msg-time" id="stream-time"></div></div>`;
        messagesEl.appendChild(row);
        const bubble  = document.getElementById('stream-bubble');
        const timeDiv = document.getElementById('stream-time');
        let full = '';
        while (true) {
            const {done, value} = await reader.read();
            if (done) break;
            for (const line of decoder.decode(value).split('\n')) {
                if (!line.startsWith('data:')) continue;
                try {
                    const obj = JSON.parse(line.slice(5).trim());
                    if (obj.chunk) { full += obj.chunk; bubble.innerHTML = renderMarkdown(escHtml(full)); scrollBottom(); }
                    if (obj.done)  { timeDiv.textContent = now(); bubble.id=''; timeDiv.id=''; }
                } catch {}
            }
        }
        setStatus('online',true); setInputBusy(false);
        inputEl.focus();
    }
}

async function sendImageManual() {
    if (isBusy) return;
    const text = inputEl.value.trim();
    const promptText = text || window.prompt('Describe the picture:');
    if (!promptText) return;
    if (text) { inputEl.value=''; inputEl.style.height='auto'; }
    setInputBusy(true);
    appendMessage('user', '🎨 ' + promptText);
    setStatus('improving the prompt...',true); showTyping('I\'m improving the prompt...');
    let enhanced = promptText;
    try { enhanced = await enhancePrompt(promptText); } catch(e) {}
    removeTyping(); setStatus('draws...',true); showTyping('I\'m drawing...');
    await doGenerateImage(promptText, enhanced);
}

async function resetChat() {
    if (!confirm('Clear history from ' + activeChar.name + '?')) return;
    await fetch('?action=reset');
    messagesEl.innerHTML = '';
    messagesEl.appendChild(emptyEl);
    emptyEl.style.display = '';
}

// ─── Chars modal ──────────────────────────
async function openCharsModal() {
    await renderCharsList();
    document.getElementById('chars-modal').style.display = 'flex';
}
function closeCharsModal() { document.getElementById('chars-modal').style.display = 'none'; }

async function renderCharsList() {
    const res   = await fetch('?action=chars_list');
    const chars = await res.json();
    const list  = document.getElementById('chars-list');
    list.innerHTML = '';
    chars.forEach(c => {
        const item = document.createElement('div');
        item.className = 'char-item' + (c.active ? ' active' : '');
        const av = c.avatar
            ? `<img src="${escHtml(c.avatar)}" alt="av">`
            : '🤖';
        item.innerHTML = `
            <div class="char-item-avatar">${av}</div>
            <div class="char-item-info">
                <div class="char-item-name">${escHtml(c.name)}${c.active ? '<span class="active-badge">active</span>' : ''}</div>
                <div class="char-item-desc">${escHtml(c.description || '—')}</div>
            </div>
            <div class="char-item-actions">
                ${!c.active ? `<button class="icon-btn" onclick="switchChar('${escHtml(c.id)}')" title="Choose">✓</button>` : ''}
                <button class="icon-btn" onclick="openCharForm('${escHtml(c.id)}')" title="Edit">✎</button>
                ${c.id !== 'default' ? `<button class="icon-btn danger" onclick="deleteChar('${escHtml(c.id)}')" title="Remove">✕</button>` : ''}
            </div>`;
        list.appendChild(item);
    });
}

async function switchChar(id) {
    const fd = new FormData(); fd.append('id', id);
    const res  = await fetch('?action=char_switch',{method:'POST',body:fd});
    const data = await res.json();
    if (data.ok) {
        updateHeaderUI(data.char);
        messagesEl.innerHTML = '';
        messagesEl.appendChild(emptyEl);
        emptyEl.style.display = '';
        await loadHistory();
        closeCharsModal();
    }
}

async function deleteChar(id) {
    if (!confirm('Delete a character and all of their history?')) return;
    const fd = new FormData(); fd.append('id', id);
    await fetch('?action=char_delete',{method:'POST',body:fd});
    await renderCharsList();
}

// ─── Char form ────────────────────────────
let editCharId = null;

async function openCharForm(id = null) {
    editCharId = id;
    document.getElementById('char-form-title').textContent = id ? 'Edit character' : 'Create a character';
    document.getElementById('cf-id').value      = id || '';
    document.getElementById('cf-name').value    = '';
    document.getElementById('cf-desc').value    = '';
    document.getElementById('cf-prompt').value  = '';
    document.getElementById('cf-avatar-input').value = '';
    document.getElementById('cf-avatar-preview').innerHTML = '🤖';

    if (id) {
        const res   = await fetch('?action=chars_list');
        const chars = await res.json();
        const c     = chars.find(x => x.id === id);
        if (c) {
            document.getElementById('cf-name').value   = c.name;
            document.getElementById('cf-desc').value   = c.description || '';
            if (c.avatar) {
                document.getElementById('cf-avatar-preview').innerHTML = `<img src="${escHtml(c.avatar)}" alt="avatar">`;
            }
        }
    }
    document.getElementById('char-form-modal').style.display = 'flex';
}
function closeCharForm() { document.getElementById('char-form-modal').style.display = 'none'; }

function previewAvatar(input) {
    if (!input.files || !input.files[0]) return;
    const reader = new FileReader();
    reader.onload = e => {
        document.getElementById('cf-avatar-preview').innerHTML = `<img src="${e.target.result}" alt="preview">`;
    };
    reader.readAsDataURL(input.files[0]);
}

async function saveChar() {
    const id    = document.getElementById('cf-id').value.trim();
    const name  = document.getElementById('cf-name').value.trim();
    const desc  = document.getElementById('cf-desc').value.trim();
    const prmt  = document.getElementById('cf-prompt').value.trim();
    const fileInput = document.getElementById('cf-avatar-input');

    if (!name) { alert('Enter the character's name'); return; }

    const fd = new FormData();
    if (id) fd.append('id', id);
    fd.append('name', name);
    fd.append('description', desc);
    fd.append('prompt', prmt);
    const saveRes  = await fetch('?action=char_save',{method:'POST',body:fd});
    const saveData = await saveRes.json();
    if (saveData.error) { alert(saveData.error); return; }

    const newId = saveData.id;

    if (fileInput.files && fileInput.files[0]) {
        const afd = new FormData();
        afd.append('id', newId);
        afd.append('avatar', fileInput.files[0]);
        await fetch('?action=char_avatar',{method:'POST',body:afd});
    }

    closeCharForm();
    await renderCharsList();
}

const scrollContainer = document.querySelector('.messages') || window; 
const header = document.querySelector('.chat-header');
let lastScrollTop = 0;

scrollContainer.addEventListener('scroll', () => {
  let scrollTop = (scrollContainer === window) 
    ? window.pageYOffset 
    : scrollContainer.scrollTop;

  if (scrollTop > lastScrollTop && scrollTop > 50) {
    header.classList.add('hidden');
  } else {
    header.classList.remove('hidden');
  }
  lastScrollTop = scrollTop <= 0 ? 0 : scrollTop;
}, { passive: true });
// ─── Init ─────────────────────────────────
loadHistory();
inputEl.focus();
updateHeaderUI(activeChar);
</script>
<?php endif; ?>
</body>
</html>
