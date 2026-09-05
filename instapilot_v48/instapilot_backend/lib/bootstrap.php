<?php
declare(strict_types=1);

$config = require __DIR__ . '/../config/config.php';
date_default_timezone_set($config['app']['timezone'] ?? 'Asia/Tehran');

// V48 APK: CORS allowlist (opt-in). The Android WebView app sends Origin: null.
// Enable it in config.php: 'cors_origins' => ['null'],
$__corsOrigin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
$__corsList = $config['app']['cors_origins'] ?? [];
$__corsOn = $__corsOrigin !== '' && in_array($__corsOrigin, $__corsList, true);
if ($__corsOn) {
    header('Access-Control-Allow-Origin: ' . $__corsOrigin);
    header('Vary: Origin');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: X-CSRF-Token, Content-Type, Accept');
    header('Access-Control-Allow-Methods: GET, POST, PATCH, PUT, DELETE, OPTIONS');
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }
}

session_name($config['app']['session_name'] ?? 'instapilot_session');
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
// V48: cookie path is configurable so the single-file build works from any folder on shared hosting.
$_cookiePath = trim((string)($config['app']['cookie_path'] ?? ''));
if ($_cookiePath === '') {
    $_fromBase = (string)parse_url($config['app']['base_url'] ?? '', PHP_URL_PATH);
    $_cookiePath = ($_fromBase !== '' && !str_contains($_fromBase, 'YOUR-DOMAIN')) ? rtrim($_fromBase, '/') : '/';
    if ($_cookiePath === '') $_cookiePath = '/';
}
// WebView app (approved 'null' origin over HTTPS) needs cross-site cookies.
$__samesite = ($__corsOn && $https) ? 'None' : 'Lax';
session_set_cookie_params([
    'lifetime' => 0,
    'path' => $_cookiePath,
    'secure' => $https,
    'httponly' => true,
    'samesite' => $__samesite,
]);
session_start();

// V45 security policy: idle timeout, absolute timeout and server-side device session guard.
function security_config(string $key, $default = null) { global $config; return $config['security'][$key] ?? $default; }
function client_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),0,45); }
function client_fingerprint(): string {
    $ua=(string)($_SERVER['HTTP_USER_AGENT'] ?? 'unknown');
    return hash('sha256', $ua.'|'.client_ip().'|'.(string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''));
}
function touch_auth_session(): void {
    if (empty($_SESSION['user_id'])) return;
    $now=time(); $idle=(int)security_config('idle_timeout_seconds',1800); $absolute=(int)security_config('absolute_timeout_seconds',86400);
    $created=(int)($_SESSION['auth_created_at'] ?? $now); $last=(int)($_SESSION['auth_last_activity'] ?? $now);
    if (($now-$last)>$idle || ($now-$created)>$absolute) {
        $uid=(int)$_SESSION['user_id']; $ds=(int)($_SESSION['device_session_id'] ?? 0);
        try { if($ds>0){$q=db()->prepare('UPDATE device_sessions SET revoked_at=NOW(),last_seen_at=NOW() WHERE id=? AND user_id=?');$q->execute([$ds,$uid]);} audit($uid,'session.expired',['reason'=>($now-$last)>$idle?'idle':'absolute']); } catch(Throwable $e){}
        $_SESSION=[]; if(session_status()===PHP_SESSION_ACTIVE) session_destroy();
        json_response(['ok'=>false,'message'=>'نشست شما به دلیل عدم فعالیت یا پایان زمان مجاز منقضی شد.','code'=>'SESSION_EXPIRED'],401);
    }
    $_SESSION['auth_last_activity']=$now;
    if (!empty($_SESSION['device_session_id'])) { try { $q=db()->prepare('UPDATE device_sessions SET last_seen_at=NOW() WHERE id=? AND user_id=? AND revoked_at IS NULL');$q->execute([(int)$_SESSION['device_session_id'],(int)$_SESSION['user_id']]); } catch(Throwable $e){} }
}
function require_auth(): int { touch_auth_session(); $id=(int)($_SESSION['user_id']??0); if($id<1) json_response(['ok'=>false,'message'=>'احراز هویت لازم است.','code'=>'AUTH_REQUIRED'],401); $ds=(int)($_SESSION['device_session_id']??0); if($ds>0){try{$q=db()->prepare('SELECT id FROM device_sessions WHERE id=? AND user_id=? AND revoked_at IS NULL LIMIT 1');$q->execute([$ds,$id]);if(!$q->fetch()){$_SESSION=[];session_destroy();json_response(['ok'=>false,'message'=>'این دستگاه از حساب خارج شده است.','code'=>'DEVICE_REVOKED'],401);}}catch(Throwable $e){}} return $id; }
function rate_limit(string $bucket, int $limit, int $windowSeconds): void {
    $key=hash('sha256',$bucket.'|'.client_ip());
    try {
        $now=date('Y-m-d H:i:s');
        $q=db()->prepare('SELECT id,attempts,window_started_at FROM rate_limits WHERE rate_key=? LIMIT 1');$q->execute([$key]);$r=$q->fetch();
        if(!$r){$i=db()->prepare('INSERT INTO rate_limits(rate_key,bucket,attempts,window_started_at) VALUES(?,?,1,?)');$i->execute([$key,$bucket,$now]);return;}
        $age=time()-strtotime((string)$r['window_started_at']);
        if($age >= $windowSeconds){$u=db()->prepare('UPDATE rate_limits SET attempts=1,window_started_at=? WHERE id=?');$u->execute([$now,(int)$r['id']]);return;}
        if((int)$r['attempts'] >= $limit){$retry=max(1,$windowSeconds-$age); header('Retry-After',(string)$retry); json_response(['ok'=>false,'message'=>'تعداد تلاش‌ها بیش از حد مجاز است. چند دقیقه بعد دوباره تلاش کنید.','code'=>'RATE_LIMITED','retry_after'=>$retry],429);}
        $u=db()->prepare('UPDATE rate_limits SET attempts=attempts+1 WHERE id=?');$u->execute([(int)$r['id']]);
    } catch(Throwable $e) { /* fail-open only for limiter infrastructure; authentication still enforces DB */ }
}
function record_login_activity(?int $userId,string $event,string $meta=''): void { try{$q=db()->prepare('INSERT INTO login_activity(user_id,event,ip_address,user_agent,fingerprint,metadata) VALUES(?,?,?,?,?,?)');$q->execute([$userId,$event,client_ip(),substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500),client_fingerprint(),$meta]);}catch(Throwable $e){} }
function create_device_session(int $userId): int {
    $token=bin2hex(random_bytes(32)); $hash=hash('sha256',$token);
    $q=db()->prepare('INSERT INTO device_sessions(user_id,session_token_hash,device_label,ip_address,user_agent,fingerprint,last_seen_at) VALUES(?,?,?,?,?,?,NOW())');
    $q->execute([$userId,$hash,'Web Browser',client_ip(),substr((string)($_SERVER['HTTP_USER_AGENT']??''),0,500),client_fingerprint()]);
    $id=(int)db()->lastInsertId(); $_SESSION['device_session_id']=$id; $_SESSION['auth_created_at']=time(); $_SESSION['auth_last_activity']=time(); return $id;
}

function db(): PDO {
    static $pdo = null;
    global $config;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=%s', $config['db']['host'], $config['db']['name'], $config['db']['charset']);
    $pdo = new PDO($dsn, $config['db']['user'], $config['db']['pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}

function json_response(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function input_json(): array {
    $raw = file_get_contents('php://input') ?: '';
    if ($raw === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function require_method(string ...$methods): void {
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', $methods, true)) {
        json_response(['ok'=>false,'message'=>'Method Not Allowed'], 405);
    }
}

function current_user_id(): int { return require_auth(); }

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function require_csrf(): void {
    global $config;
    $header = (string)($config['security']['csrf_header'] ?? 'X-CSRF-Token');
    $serverKey = 'HTTP_' . str_replace('-', '_', strtoupper($header));
    $token = (string)($_SERVER[$serverKey] ?? '');
    $sessionToken = (string)($_SESSION['csrf'] ?? '');
    if ($token === '' || $sessionToken === '' || !hash_equals($sessionToken, $token)) {
        json_response(['ok'=>false,'message'=>'نشست امنیتی منقضی یا نامعتبر است. صفحه را تازه‌سازی کنید.','code'=>'CSRF_INVALID'], 419);
    }
}

function rotate_csrf(): string {
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    return $_SESSION['csrf'];
}

function audit(?int $userId, string $action, array $metadata = []): void {
    try {
        $stmt = db()->prepare('INSERT INTO audit_logs (user_id, action, ip_address, metadata) VALUES (?,?,?,?)');
        $stmt->execute([$userId, $action, $_SERVER['REMOTE_ADDR'] ?? null, json_encode($metadata, JSON_UNESCAPED_UNICODE)]);
    } catch (Throwable $e) {}
}

function notify(int $userId, string $type, string $title, string $body = ''): void {
    $stmt = db()->prepare('INSERT INTO notifications (user_id,type,title,body) VALUES (?,?,?,?)');
    $stmt->execute([$userId,$type,$title,$body]);
}

function curl_json(string $url, array $options = []): array {
    $ch = curl_init($url);
    $headers = $options['headers'] ?? ['Accept: application/json'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $options['timeout'] ?? 45,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $options['method'] ?? 'GET',
        CURLOPT_POSTFIELDS => $options['body'] ?? null,
    ]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false) throw new RuntimeException($error ?: 'cURL error');
    $json = json_decode($body, true);
    return ['status'=>$status,'data'=>is_array($json)?$json:[],'raw'=>$body];
}

require_once __DIR__ . '/instagram.php';
require_once __DIR__ . '/ai_engine.php';
require_once __DIR__ . '/publisher.php';
