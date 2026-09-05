<?php
declare(strict_types=1);
require __DIR__ . '/../lib/bootstrap.php';
require __DIR__ . '/../lib/learning.php';
require __DIR__ . '/../lib/attribution.php';
require __DIR__ . '/../lib/decision.php';
require __DIR__ . '/../lib/mission_planner.php';
require __DIR__ . '/../lib/v42_engine.php';
require __DIR__ . '/../lib/v43_brain.php';

$path = trim((string)($_GET['route'] ?? ''), '/');
if ($path === '') {
    $uriPath = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/', '/');
    $marker = strpos($uriPath, '/api/');
    if ($marker !== false) $path = trim(substr($uriPath, $marker + 5), '/');
    elseif (str_ends_with($uriPath, '/api')) $path = '';
}
if (str_starts_with($path, 'api/')) $path = substr($path, 4);

function v24_integration_value(int $userId, string $key): string {
    $st=db()->prepare('SELECT setting_value,is_secret FROM system_settings WHERE user_id=? AND setting_group="integrations" AND setting_key=? LIMIT 1');
    $st->execute([$userId,$key]); $r=$st->fetch(); if(!$r) return '';
    $v=(string)$r['setting_value']; if((int)$r['is_secret']===1 && $v!=='••••••••') return ig_decrypt($v); if($v==='••••••••') return ''; return $v;
}

try {
    // V23 route normalization: tolerate legacy clients that accidentally omitted the slash after /api.
    $legacyRoutes = [
        'apiapprovals' => 'approvals',
        'apijobs' => 'jobs',
        'apischeduler/recommendations' => 'scheduler/recommendations',
        'apischeduler/slots' => 'scheduler/slots',
        'apianalytics/overview' => 'analytics/overview',
        'apiai/memory' => 'ai/memory',
    ];
    if (isset($legacyRoutes[$path])) $path = $legacyRoutes[$path];

    if ($path === 'v43/brain') { require_method('GET'); require_auth(); json_response(['ok'=>true,'brain'=>v43_brain((int)$_SESSION['user_id'])]); }
    if ($path === 'v43/settings') { require_method('POST'); require_auth(); require_csrf(); $in=input_json(); $uid=(int)$_SESSION['user_id']; $mode=in_array(($in['mode']??'assist'),['assist','autopilot'],true)?$in['mode']:'assist'; $approval=($in['publish_approval']??'required')==='required'?'required':'manual'; v43_set_setting($uid,'mode',$mode); v43_set_setting($uid,'publish_approval',$approval); json_response(['ok'=>true,'settings'=>['mode'=>$mode,'publish_approval'=>$approval]]); }
    if ($path === 'v43/missions') { $uid=(int)($_SESSION['user_id']??0); if(!$uid) json_response(['ok'=>false,'message'=>'ورود لازم است.'],401); if($_SERVER['REQUEST_METHOD']==='GET') json_response(['ok'=>true,'items'=>v43_missions($uid)]); require_method('POST'); require_csrf(); $in=input_json(); json_response(['ok'=>true,'mission'=>v43_create_mission($uid,(string)($in['type']??'analyze'),$in['title']??null,$in['payload']??[])],201); }
    if ($path === 'v43/missions/approve') { require_method('POST'); require_auth(); require_csrf(); $in=input_json(); json_response(['ok'=>true,'mission'=>v43_approve((int)$_SESSION['user_id'],(int)($in['id']??0))]); }
    if ($path === 'v43/missions/run') { require_method('POST'); require_auth(); require_csrf(); $in=input_json(); json_response(['ok'=>true,'mission'=>v43_run((int)$_SESSION['user_id'],(int)($in['id']??0))]); }

    if ($path === '' || $path === 'health') {
        require_method('GET');
        $dbOk = true; $dbError = null;
        try { db()->query('SELECT 1'); } catch(Throwable $e){ $dbOk=false; $dbError='Database unavailable'; }
        json_response([
            'ok'=>true, 'service'=>'InstaPilot API', 'version'=>'47.0.0', 'php'=>PHP_VERSION,
            'php_required'=>'8.4+', 'php_ok'=>version_compare(PHP_VERSION,'8.4.0','>='),
            'database'=>$dbOk?'ok':'error', 'database_ok'=>$dbOk, 'database_message'=>$dbError,
            'route'=>'/ins/api', 'time'=>date(DATE_ATOM)
        ]);
    }

    if ($path === 'auth/csrf') {
        require_method('GET');
        json_response(['ok'=>true,'csrf'=>csrf_token(),'authenticated'=>!empty($_SESSION['user_id'])]);
    }

    if ($path === 'auth/register') {
        require_method('POST'); require_csrf();
        $in=input_json(); $username=trim((string)($in['username']??'')); $password=(string)($in['password']??''); $name=trim((string)($in['display_name']??$username));
        if (strlen($username)<3 || strlen($password)<8) json_response(['ok'=>false,'message'=>'نام کاربری و رمز عبور معتبر وارد کنید.'],422);
        $stmt=db()->prepare('SELECT id FROM users WHERE username=?'); $stmt->execute([$username]);
        if ($stmt->fetch()) json_response(['ok'=>false,'message'=>'این نام کاربری قبلاً ثبت شده است.'],409);
        $stmt=db()->prepare('INSERT INTO users(username,password_hash,display_name) VALUES(?,?,?)');
        $stmt->execute([$username,password_hash($password,PASSWORD_DEFAULT),$name]);
        session_regenerate_id(true); $_SESSION['user_id']=(int)db()->lastInsertId(); rotate_csrf(); create_device_session((int)$_SESSION['user_id']); record_login_activity((int)$_SESSION['user_id'],'register'); audit($_SESSION['user_id'],'register');
        json_response(['ok'=>true,'user'=>['id'=>$_SESSION['user_id'],'username'=>$username,'display_name'=>$name],'csrf'=>csrf_token()],201);
    }

    if ($path === 'auth/login') {
        require_method('POST'); rate_limit('login', (int)security_config('login_rate_limit',8), (int)security_config('login_rate_window_seconds',300)); require_csrf();
        $in=input_json(); $username=trim((string)($in['username']??'')); $password=(string)($in['password']??'');
        $stmt=db()->prepare('SELECT id,username,password_hash,display_name,role FROM users WHERE username=? LIMIT 1'); $stmt->execute([$username]); $u=$stmt->fetch();
        if (!$u || !password_verify($password,$u['password_hash'])) json_response(['ok'=>false,'message'=>'نام کاربری یا رمز عبور نادرست است.'],401);
        session_regenerate_id(true); $_SESSION['user_id']=(int)$u['id']; rotate_csrf(); $ds=create_device_session((int)$u['id']); record_login_activity((int)$u['id'],'login'); audit((int)$u['id'],'login',['device_session_id'=>$ds]); notify((int)$u['id'],'security','ورود جدید','ورود موفق از یک مرورگر جدید ثبت شد.');
        json_response(['ok'=>true,'user'=>['id'=>(int)$u['id'],'username'=>$u['username'],'display_name'=>$u['display_name'],'role'=>$u['role']],'csrf'=>csrf_token()]);
    }

    if ($path === 'auth/session') {
        require_method('GET');
        $id=(int)($_SESSION['user_id']??0);
        if($id<1) json_response(['ok'=>true,'authenticated'=>false,'user'=>null]);
        $u=db()->prepare('SELECT id,username,display_name,role,created_at FROM users WHERE id=? LIMIT 1'); $u->execute([$id]); $user=$u->fetch();
        if(!$user){ $_SESSION=[]; session_destroy(); json_response(['ok'=>true,'authenticated'=>false,'user'=>null]); }
        $ds=(int)($_SESSION['device_session_id']??0); if($ds>0){try{$q=db()->prepare('SELECT id FROM device_sessions WHERE id=? AND user_id=? AND revoked_at IS NULL LIMIT 1');$q->execute([$ds,$id]);if(!$q->fetch()){$_SESSION=[];session_destroy();json_response(['ok'=>false,'message'=>'این دستگاه از حساب خارج شده است.','code'=>'DEVICE_REVOKED'],401);}}catch(Throwable $e){}} json_response(['ok'=>true,'authenticated'=>true,'user'=>$user,'csrf'=>csrf_token(),'session'=>['idle_timeout'=>(int)security_config('idle_timeout_seconds',1800),'absolute_timeout'=>(int)security_config('absolute_timeout_seconds',86400),'last_activity'=>(int)($_SESSION['auth_last_activity']??time()),'created_at'=>(int)($_SESSION['auth_created_at']??time())]]);
    }

    if ($path === 'auth/logout') {
        require_method('POST'); require_csrf(); $id=current_user_id(); $ds=(int)($_SESSION['device_session_id']??0); if($ds){$q=db()->prepare('UPDATE device_sessions SET revoked_at=NOW(),last_seen_at=NOW() WHERE id=? AND user_id=?');$q->execute([$ds,$id]);} record_login_activity($id,'logout'); audit($id,'logout');
        $_SESSION=[];
        if (ini_get('session.use_cookies')) { $p=session_get_cookie_params(); setcookie(session_name(),'',['expires'=>time()-42000,'path'=>$p['path'],'domain'=>$p['domain']??'','secure'=>$p['secure'],'httponly'=>$p['httponly'],'samesite'=>$p['samesite']??'Lax']); }
        session_destroy(); json_response(['ok'=>true]);
    }

    if ($path === 'notifications/poll') {
        require_method('GET'); $id=current_user_id(); $after=max(0,(int)($_GET['after_id']??0));
        $q=db()->prepare('SELECT id,type,title,body,read_at,created_at FROM notifications WHERE user_id=? AND id>? ORDER BY id ASC LIMIT 30');$q->execute([$id,$after]);
        $items=$q->fetchAll(); $u=db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL');$u->execute([$id]);
        json_response(['ok'=>true,'items'=>$items,'unread'=>(int)$u->fetchColumn(),'server_time'=>date(DATE_ATOM)]);
    }
    if ($path === 'auth/devices') {
        require_method('GET'); $id=current_user_id(); $q=db()->prepare('SELECT id,device_label,ip_address,user_agent,last_seen_at,created_at,revoked_at,(id=? ) AS current_device FROM device_sessions WHERE user_id=? ORDER BY last_seen_at DESC');$q->execute([(int)($_SESSION['device_session_id']??0),$id]); json_response(['ok'=>true,'items'=>$q->fetchAll()]);
    }
    if ($path === 'auth/devices/revoke') {
        require_method('POST'); $id=current_user_id(); require_csrf(); $in=input_json(); $did=(int)($in['id']??0); if($did<1)json_response(['ok'=>false,'message'=>'شناسه دستگاه نامعتبر است.'],422);
        $q=db()->prepare('UPDATE device_sessions SET revoked_at=NOW() WHERE id=? AND user_id=? AND revoked_at IS NULL');$q->execute([$did,$id]); record_login_activity($id,'device_revoked',json_encode(['device_session_id'=>$did])); notify($id,'security','دستگاه لغو شد','یک نشست دستگاه از حساب شما خارج شد.'); json_response(['ok'=>true,'updated'=>$q->rowCount()]);
    }
    if ($path === 'auth/devices/revoke-others') {
        require_method('POST'); $id=current_user_id(); require_csrf(); $current=(int)($_SESSION['device_session_id']??0); $q=db()->prepare('UPDATE device_sessions SET revoked_at=NOW() WHERE user_id=? AND id<>? AND revoked_at IS NULL');$q->execute([$id,$current]); record_login_activity($id,'devices_revoked'); notify($id,'security','نشست‌های دیگر بسته شدند','همه دستگاه‌های دیگر از حساب شما خارج شدند.'); json_response(['ok'=>true,'updated'=>$q->rowCount()]);
    }
    if ($path === 'auth/activity') {
        require_method('GET'); $id=current_user_id(); $q=db()->prepare('SELECT id,event,ip_address,user_agent,created_at,metadata FROM login_activity WHERE user_id=? ORDER BY created_at DESC,id DESC LIMIT 50');$q->execute([$id]); json_response(['ok'=>true,'items'=>$q->fetchAll()]);
    }

    if ($path === 'me') {
        require_method('GET'); $id=current_user_id();
        $u=db()->prepare('SELECT id,username,display_name,role,created_at FROM users WHERE id=?'); $u->execute([$id]);
        json_response(['ok'=>true,'user'=>$u->fetch()]);
    }


    if ($path === 'dashboard') {
        require_method('GET');
        $id=current_user_id();
        $u=db()->prepare('SELECT id,username,display_name,role,created_at FROM users WHERE id=?'); $u->execute([$id]);
        $profile=db()->prepare('SELECT brand_name,category,audience,bio,tone FROM brand_profiles WHERE user_id=?'); $profile->execute([$id]);
        $ig=db()->prepare('SELECT ig_user_id,username,account_type,status,last_sync_at,token_expires_at FROM instagram_accounts WHERE user_id=? ORDER BY id DESC LIMIT 1'); $ig->execute([$id]);
        $unread=db()->prepare('SELECT COUNT(*) FROM notifications WHERE user_id=? AND read_at IS NULL'); $unread->execute([$id]);
        $jobs=db()->prepare("SELECT COUNT(*) FROM jobs WHERE user_id=? AND status IN ('queued','processing')"); $jobs->execute([$id]);
        json_response(['ok'=>true,'user'=>$u->fetch(),'brand'=>$profile->fetch()?:null,'instagram'=>$ig->fetch()?:null,'unread_notifications'=>(int)$unread->fetchColumn(),'active_jobs'=>(int)$jobs->fetchColumn()]);
    }

    if ($path === 'jobs') {
        $id=current_user_id();
        if ($_SERVER['REQUEST_METHOD']==='GET') {
            $s=db()->prepare('SELECT id,job_type,status,attempts,available_at,locked_at,error_message,created_at,updated_at FROM jobs WHERE user_id=? ORDER BY created_at DESC LIMIT 50');
            $s->execute([$id]); json_response(['ok'=>true,'items'=>$s->fetchAll()]);
        }
        require_method('POST'); require_csrf(); $in=input_json();
        $type=trim((string)($in['job_type']??''));
        if ($type==='') json_response(['ok'=>false,'message'=>'job_type الزامی است.'],422);
        $payload=$in['payload']??[]; if(!is_array($payload)) $payload=[];
        $available=$in['available_at']??date('Y-m-d H:i:s');
        $s=db()->prepare('INSERT INTO jobs(user_id,job_type,payload,available_at) VALUES(?,?,?,?)');
        $s->execute([$id,$type,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$available]);
        $jid=(int)db()->lastInsertId(); audit($id,'job.create',['id'=>$jid,'type'=>$type]); notify($id,'automation','عملیات در صف قرار گرفت','عملیات '.$type.' برای اجرا ثبت شد.');
        json_response(['ok'=>true,'id'=>$jid],201);
    }

    if ($path === 'ai/memory') {
        $id=current_user_id();
        if ($_SERVER['REQUEST_METHOD']==='GET') {
            $s=db()->prepare('SELECT memory_type,memory_key,memory_value,confidence,source,updated_at FROM ai_memories WHERE user_id=? ORDER BY updated_at DESC LIMIT 100');$s->execute([$id]);
            json_response(['ok'=>true,'items'=>$s->fetchAll()]);
        }
        require_method('POST'); require_csrf(); $in=input_json();
        $type=trim((string)($in['memory_type']??'insight')); $key=trim((string)($in['memory_key']??'')); $value=trim((string)($in['memory_value']??'')); $confidence=max(0,min(100,(float)($in['confidence']??70))); $source=trim((string)($in['source']??'ai'));
        if($key===''||$value==='') json_response(['ok'=>false,'message'=>'memory_key و memory_value الزامی هستند.'],422);
        $s=db()->prepare('INSERT INTO ai_memories(user_id,memory_type,memory_key,memory_value,confidence,source) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE memory_value=VALUES(memory_value),confidence=VALUES(confidence),source=VALUES(source)');$s->execute([$id,$type,$key,$value,$confidence,$source]); audit($id,'ai.memory.upsert',['type'=>$type,'key'=>$key]); json_response(['ok'=>true]);
    }

    if ($path === 'ai/learn') {
        require_method('POST'); require_csrf(); $id=current_user_id(); $in=input_json();
        $key=trim((string)($in['key']??'')); $value=trim((string)($in['value']??'')); $confidence=max(0,min(100,(float)($in['confidence']??75))); $type=trim((string)($in['memory_type']??'performance')); $source=trim((string)($in['source']??'analytics'));
        if($key===''||$value==='') json_response(['ok'=>false,'message'=>'key و value الزامی هستند.'],422);
        $s=db()->prepare('INSERT INTO ai_memories(user_id,memory_type,memory_key,memory_value,confidence,source) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE memory_value=VALUES(memory_value),confidence=VALUES(confidence),source=VALUES(source)');$s->execute([$id,$type,$key,$value,$confidence,$source]);
        audit($id,'ai.learn',['key'=>$key]); json_response(['ok'=>true,'learned'=>['key'=>$key,'confidence'=>$confidence]]);
    }

    if ($path === 'ai/agents') {
        $id=current_user_id();
        $s=db()->prepare('SELECT memory_type,memory_key,memory_value,confidence FROM ai_memories WHERE user_id=? ORDER BY confidence DESC, updated_at DESC LIMIT 50');$s->execute([$id]);
        $mem=$s->fetchAll();
        $s=db()->prepare('SELECT goal,horizon_days,plan,status,created_at FROM growth_plans WHERE user_id=? ORDER BY created_at DESC LIMIT 1');$s->execute([$id]);$plan=$s->fetch();
        json_response(['ok'=>true,'agents'=>['content'=>['ready'=>true,'responsibility'=>'hooks, captions, CTA, hashtags, formats'],'growth'=>['ready'=>true,'responsibility'=>'strategy, cadence, experiments'],'analytics'=>['ready'=>true,'responsibility'=>'KPI, anomalies, insights'],'brand'=>['ready'=>true,'responsibility'=>'brand voice and consistency']],'memory'=>$mem,'latest_plan'=>$plan]);
    }

    if ($path === 'ai/growth-plan') {
        require_method('POST'); require_csrf(); $id=current_user_id(); global $config; $in=input_json();
        $goal=trim((string)($in['goal']??'افزایش تعامل')); $days=max(7,min(90,(int)($in['days']??30))); $provider=$config['ai']['provider'];
        $s=db()->prepare('SELECT brand_name,category,audience,tone,bio FROM brand_profiles WHERE user_id=?');$s->execute([$id]);$brand=$s->fetch()?:[];
        $s=db()->prepare('SELECT COALESCE(AVG(engagement),0) engagement,COALESCE(MAX(followers),0) followers,COALESCE(SUM(reach),0) reach,COALESCE(SUM(saves),0) saves,COALESCE(SUM(shares),0) shares FROM analytics_daily WHERE user_id=? AND metric_date>=DATE_SUB(CURDATE(),INTERVAL 30 DAY)');$s->execute([$id]);$metrics=$s->fetch()?:[];
        $memoryStmt=db()->prepare('SELECT memory_type,memory_key,memory_value,confidence FROM ai_memories WHERE user_id=? ORDER BY confidence DESC,updated_at DESC LIMIT 20');$memoryStmt->execute([$id]);$memory=$memoryStmt->fetchAll();
        $prompt="You are InstaPilot Growth Orchestrator. Create a practical {$days}-day Instagram growth plan for a professional account. Goal: {$goal}. Brand: ".json_encode($brand,JSON_UNESCAPED_UNICODE).". Metrics: ".json_encode($metrics).". Memory: ".json_encode($memory,JSON_UNESCAPED_UNICODE).". Return JSON with keys summary, strategy, weekly_actions (array), content_mix (array), experiments (array), kpis (array), risks (array), next_actions (array). Be specific and concise. Do not invent platform permissions.";
        $result=null; $raw=null;
        if($provider!=='builtin'){
            try{
                if($provider==='gemini' && $config['ai']['gemini_api_key']){ $url='https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($config['ai']['gemini_model']).':generateContent?key='.rawurlencode($config['ai']['gemini_api_key']); $r=curl_json($url,['method'=>'POST','headers'=>['Content-Type: application/json'],'body'=>json_encode(['contents'=>[['parts'=>[['text'=>$prompt]]]]]),'timeout'=>60]); if($r['status']>=200&&$r['status']<300){$raw=$r['data'];$txt=$raw['candidates'][0]['content']['parts'][0]['text']??'';$txt=preg_replace('/^```json\s*|\s*```$/','',$txt);$result=json_decode($txt,true);}}
                elseif($provider==='groq' && $config['ai']['groq_api_key']){ $r=curl_json('https://api.groq.com/openai/v1/chat/completions',['method'=>'POST','headers'=>['Content-Type: application/json','Authorization: Bearer '.$config['ai']['groq_api_key']],'body'=>json_encode(['model'=>$config['ai']['groq_model'],'messages'=>[['role'=>'system','content'=>'Return valid JSON only.'],['role'=>'user','content'=>$prompt]],'temperature'=>0.4]),'timeout'=>60]); if($r['status']>=200&&$r['status']<300){$raw=$r['data'];$txt=$raw['choices'][0]['message']['content']??'';$txt=preg_replace('/^```json\s*|\s*```$/','',$txt);$result=json_decode($txt,true);}}
            }catch(Throwable $e){ $result=null; }
        }
        if(!is_array($result)){
            $result=['summary'=>"برنامه {$days} روزه برای {$goal} با تمرکز بر محتوای منظم و اندازه‌گیری KPIها.",'strategy'=>['تمرکز روی فرمت‌های پربازده','آزمون Hookهای مختلف','تحلیل هفتگی و اصلاح برنامه'],'weekly_actions'=>[['week'=>1,'actions'=>['تحلیل ۱۰ محتوای اخیر','ساخت ۳ Reel آزمایشی','۲ Story تعاملی']],['week'=>2,'actions'=>['تکرار فرمت برنده','تست دو CTA']],['week'=>3,'actions'=>['تقویت موضوعات با Save بالا','یک همکاری محتوایی']],['week'=>4,'actions'=>['جمع‌بندی KPI','ساخت برنامه ماه بعد']]],'content_mix'=>['Reel 50%','Carousel 25%','Story 25%'],'experiments'=>['Hook A/B','CTA A/B','زمان انتشار'],'kpis'=>['Engagement Rate','Reach','Saves','Shares','Followers'],'risks'=>['تکیه بیش از حد بر یک فرمت','انتشار بدون تحلیل'],'next_actions'=>['اتصال Instagram','تکمیل Brand Brain','اجرای هفته اول']];
            $provider='builtin';
        }
        $s=db()->prepare('INSERT INTO growth_plans(user_id,goal,horizon_days,plan,status) VALUES(?,?,?,?,"draft")');$s->execute([$id,$goal,$days,json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);$planId=(int)db()->lastInsertId();
        $s=db()->prepare('INSERT INTO ai_runs(user_id,agent,goal,input,output,provider,status,completed_at) VALUES(?,?,?,?,?,?,"completed",NOW())');$s->execute([$id,'growth',$goal,json_encode(['brand'=>$brand,'metrics'=>$metrics],JSON_UNESCAPED_UNICODE),json_encode($result,JSON_UNESCAPED_UNICODE),$provider]);
        notify($id,'ai','Growth Plan آماده شد','برنامه رشد '.$days.' روزه برای شما ساخته شد.'); audit($id,'ai.growth_plan',['id'=>$planId,'provider'=>$provider]);
        json_response(['ok'=>true,'plan_id'=>$planId,'provider'=>$provider,'demo'=>$provider==='builtin','plan'=>$result]);
    }

    if ($path === 'ai/score') {
        require_method('POST'); require_csrf(); $id=current_user_id(); global $config; $in=input_json(); $caption=trim((string)($in['caption']??''));
        if($caption==='') json_response(['ok'=>false,'message'=>'کپشن الزامی است.'],422);
        $provider=$config['ai']['provider'];
        if($provider==='builtin'){ $len=mb_strlen($caption); $score=min(100,40+(int)min(35,$len/5)+(preg_match('/[؟?]/u',$caption)?10:0)+(preg_match('/(کامنت|نظر|ذخیره|ارسال|share|save)/iu',$caption)?15:0)); json_response(['ok'=>true,'score'=>$score,'mode'=>'heuristic','agent'=>'content']); }
        json_response(['ok'=>false,'message'=>'برای AI Score واقعی، provider ساختاریافته را فعال کنید.'],503);
    }

    if ($path === 'autopilot/content-draft') {
        require_method('POST'); require_csrf(); $id=current_user_id(); $in=input_json();
        $goal=trim((string)($in['goal']??'')); $type=in_array(($in['type']??'reel'),['reel','post','story'],true)?$in['type']:'reel';
        if($goal==='') json_response(['ok'=>false,'message'=>'هدف ساخت محتوا الزامی است.'],422);
        $s=db()->prepare('INSERT INTO jobs(user_id,job_type,payload,available_at) VALUES(?,?,?,NOW())');
        $s->execute([$id,'autonomous_content',json_encode(['goal'=>$goal,'type'=>$type],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        $jid=(int)db()->lastInsertId(); audit($id,'content.autonomous_queue',['job_id'=>$jid,'goal'=>$goal,'type'=>$type]);
        notify($id,'ai','ساخت محتوای خودکار در صف قرار گرفت','AI پس از تحلیل Brand Brain و داده‌های عملکرد، پیش‌نویس می‌سازد.');
        json_response(['ok'=>true,'queued'=>true,'job_id'=>$jid],202);
    }

    if ($path === 'autopilot/content-latest') {
        $id=current_user_id();
        $s=db()->prepare('SELECT r.id,r.content_id,r.goal,r.provider,r.status,r.output,r.error_message,r.created_at,r.completed_at,c.type,c.title,c.caption,c.hashtags,c.status AS content_status FROM content_ai_runs r LEFT JOIN content_items c ON c.id=r.content_id WHERE r.user_id=? ORDER BY r.id DESC LIMIT 1');
        $s->execute([$id]); $r=$s->fetch(); if(!$r) json_response(['ok'=>true,'run'=>null]);
        $r['output']=json_decode((string)$r['output'],true); json_response(['ok'=>true,'run'=>$r]);
    }

    if ($path === 'autopilot/content-explain') {
        $id=current_user_id(); $contentId=(int)($_GET['content_id']??0);
        if(!$contentId) json_response(['ok'=>false,'message'=>'content_id الزامی است.'],422);
        $s=db()->prepare('SELECT c.id,c.type,c.title,c.caption,c.hashtags,c.status,r.score,r.brand_match,r.hook_score,r.cta_score,r.engagement_score,r.explanation,r.recommendation,r.provider,r.created_at FROM content_items c JOIN content_ai_reviews r ON r.content_id=c.id WHERE c.user_id=? AND c.id=? ORDER BY r.id DESC LIMIT 1');
        $s->execute([$id,$contentId]); $r=$s->fetch(); if(!$r) json_response(['ok'=>false,'message'=>'تحلیل محتوا پیدا نشد.'],404);
        $r['explanation']=json_decode((string)$r['explanation'],true); json_response(['ok'=>true,'review'=>$r]);
    }

    if ($path === 'command') {
        require_method('POST'); require_csrf(); $id=current_user_id(); $in=input_json(); $command=trim((string)($in['command']??''));
        if ($command==='') json_response(['ok'=>false,'message'=>'دستور خالی است.'],422);
        $map=[
          'content'=>['view'=>'content','label'=>'استودیوی محتوا'],
          'ai'=>['view'=>'ai','label'=>'AI Studio'],
          'analytics'=>['view'=>'analytics','label'=>'تحلیل و رشد'],
          'manage'=>['view'=>'manage','label'=>'مدیریت'],
          'home'=>['view'=>'home','label'=>'خانه'],
          'notifications'=>['view'=>'notifications','label'=>'اعلان‌ها'],
          'profile'=>['view'=>'profile','label'=>'پروفایل'],
        ];
        $q=mb_strtolower($command);
        foreach($map as $key=>$target){ if(str_contains($q,$key) || str_contains($q,mb_strtolower($target['label']))) { audit($id,'command.execute',['command'=>$command,'target'=>$key]); json_response(['ok'=>true,'action'=>'navigate','target'=>$key,'label'=>$target['label']]); } }
        $s=db()->prepare('INSERT INTO jobs(user_id,job_type,payload) VALUES(?,?,?)');$s->execute([$id,'ai_command',json_encode(['command'=>$command],JSON_UNESCAPED_UNICODE)]);$jid=(int)db()->lastInsertId();notify($id,'ai','دستور AI ثبت شد','دستور شما برای پردازش در صف قرار گرفت.');audit($id,'command.queue',['command'=>$command,'job_id'=>$jid]);json_response(['ok'=>true,'action'=>'queued','job_id'=>$jid,'message'=>'دستور برای پردازش در صف قرار گرفت.'],202);
    }

    if ($path === 'analytics/summary') {
        require_method('GET'); $id=current_user_id();
        $s=db()->prepare('SELECT COALESCE(SUM(reach),0) reach,COALESCE(SUM(impressions),0) impressions,COALESCE(SUM(saves),0) saves,COALESCE(SUM(shares),0) shares,COALESCE(AVG(engagement),0) engagement,COALESCE(MAX(followers),0) followers FROM analytics_daily WHERE user_id=? AND metric_date>=DATE_SUB(CURDATE(),INTERVAL 30 DAY)');$s->execute([$id]);
        json_response(['ok'=>true,'summary'=>$s->fetch()]);
    }

    if ($path === 'brand/profile') {
        $id=current_user_id();
        if ($_SERVER['REQUEST_METHOD']==='GET') { $s=db()->prepare('SELECT brand_name,category,audience,bio,tone FROM brand_profiles WHERE user_id=?');$s->execute([$id]);json_response(['ok'=>true,'profile'=>$s->fetch()?:null]); }
        require_method('POST'); require_csrf(); $in=input_json();
        $s=db()->prepare('INSERT INTO brand_profiles(user_id,brand_name,category,audience,bio,tone) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE brand_name=VALUES(brand_name),category=VALUES(category),audience=VALUES(audience),bio=VALUES(bio),tone=VALUES(tone)');
        $s->execute([$id,$in['brand_name']??null,$in['category']??null,$in['audience']??null,$in['bio']??null,$in['tone']??null]); audit($id,'brand.update'); json_response(['ok'=>true,'message'=>'پروفایل برند ذخیره شد.']);
    }

    if ($path === 'approvals') {
        $id=current_user_id();
        if ($_SERVER['REQUEST_METHOD']==='GET') {
            $status=trim((string)($_GET['status']??''));
            $sql='SELECT id,mission_id,content_id,item_type,title,payload,status,reviewed_at,created_at FROM approval_items WHERE user_id=?';
            $args=[$id];
            if(in_array($status,['pending','approved','rejected','expired'],true)){ $sql.=' AND status=?'; $args[]=$status; }
            $sql.=' ORDER BY created_at DESC LIMIT 100';
            $st=db()->prepare($sql);$st->execute($args);$items=$st->fetchAll();
            foreach($items as &$item){$item['payload']=json_decode((string)($item['payload']??''),true)?:null;} unset($item);
            json_response(['ok'=>true,'items'=>$items]);
        }
        require_method('POST'); require_csrf(); $in=input_json(); $approvalId=(int)($in['id']??0); $decision=$in['decision']??'';
        if(!$approvalId || !in_array($decision,['approve','reject'],true)) json_response(['ok'=>false,'message'=>'شناسه و تصمیم معتبر لازم است.'],422);
        $new=$decision==='approve'?'approved':'rejected';
        $st=db()->prepare('UPDATE approval_items SET status=?,reviewed_at=NOW() WHERE id=? AND user_id=? AND status="pending"');$st->execute([$new,$approvalId,$id]);
        if(!$st->rowCount()) json_response(['ok'=>false,'message'=>'Approval پیدا نشد یا قبلاً بررسی شده است.'],409);
        notify($id,'approval',$decision==='approve'?'مورد تأیید شد':'مورد رد شد','وضعیت درخواست شماره '.$approvalId.' به '.$new.' تغییر کرد.'); audit($id,'approval.'.$new,['id'=>$approvalId]);
        json_response(['ok'=>true,'status'=>$new]);
    }

    if ($path === 'scheduler/recommendations') {
        $id=current_user_id(); require_method('GET');
        $days=max(1,min(30,(int)($_GET['days']??7)));
        $st=db()->prepare('SELECT id,mission_id,recommended_at,score,reason,selected,created_at FROM scheduler_recommendations WHERE user_id=? AND recommended_at>=NOW() AND recommended_at<=DATE_ADD(NOW(),INTERVAL ? DAY) ORDER BY score DESC,recommended_at ASC LIMIT 50');
        $st->execute([$id,$days]);$items=$st->fetchAll();
        foreach($items as &$item){$item['reason']=json_decode((string)($item['reason']??''),true)?:null;}unset($item);
        json_response(['ok'=>true,'days'=>$days,'items'=>$items]);
    }

    if ($path === 'scheduler/slots') {
        $id=current_user_id();
        if($_SERVER['REQUEST_METHOD']==='GET'){
            $st=db()->prepare('SELECT id,mission_id,content_id,scheduled_at,timezone,score,status,source,reason,created_at FROM scheduler_slots WHERE user_id=? ORDER BY scheduled_at ASC LIMIT 100');$st->execute([$id]);$items=$st->fetchAll();
            foreach($items as &$item){$item['reason']=json_decode((string)($item['reason']??''),true)?:null;}unset($item);json_response(['ok'=>true,'items'=>$items]);
        }
        require_method('POST');require_csrf();$in=input_json();$when=trim((string)($in['scheduled_at']??''));$contentId=(int)($in['content_id']??0);$score=max(0,min(100,(float)($in['score']??0)));
        if($when==='')json_response(['ok'=>false,'message'=>'scheduled_at الزامی است.'],422);
        $st=db()->prepare('INSERT INTO scheduler_slots(user_id,content_id,scheduled_at,timezone,score,status,source,reason) VALUES(?,?,?, ?,?,"selected","user",?)');$st->execute([$id,$contentId?:null,$when,$config['app']['timezone']??'Asia/Baku',$score,json_encode($in['reason']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);$sid=(int)db()->lastInsertId();audit($id,'scheduler.slot.create',['id'=>$sid]);json_response(['ok'=>true,'id'=>$sid],201);
    }

    if ($path === 'settings') {
        $id=current_user_id();
        if($_SERVER['REQUEST_METHOD']==='GET'){
            $st=db()->prepare('SELECT setting_group,setting_key,setting_value,is_secret,updated_at FROM system_settings WHERE user_id=? ORDER BY setting_group,setting_key');$st->execute([$id]);$items=$st->fetchAll();
            foreach($items as &$x){if((int)$x['is_secret']===1)$x['setting_value']='••••••••';}unset($x);json_response(['ok'=>true,'items'=>$items]);
        }
        require_method('POST');require_csrf();$in=input_json();$group=trim((string)($in['group']??'app'));$key=trim((string)($in['key']??''));$value=(string)($in['value']??'');$secret=!empty($in['secret'])?1:0;
        if($key==='')json_response(['ok'=>false,'message'=>'setting key الزامی است.'],422);
        if($secret && $value!=='••••••••'){ $value=ig_encrypt($value); }
        $st=db()->prepare('INSERT INTO system_settings(user_id,setting_group,setting_key,setting_value,is_secret) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE setting_value=IF(is_secret=1 AND VALUES(setting_value)="••••••••",setting_value,VALUES(setting_value)),is_secret=VALUES(is_secret)');$st->execute([$id,$group,$key,$value,$secret]);audit($id,'settings.update',['group'=>$group,'key'=>$key,'secret'=>(bool)$secret]);json_response(['ok'=>true]);
    }

    if ($path === 'system/diagnostics') {
        $id=current_user_id(); require_method('GET'); global $config;
        $checks=[];
        try{db()->query('SELECT 1');$checks['database']=['ok'=>true,'message'=>'MySQL connection is healthy'];}catch(Throwable $e){$checks['database']=['ok'=>false,'message'=>'Database unavailable'];}
        $checks['php']=['ok'=>version_compare(PHP_VERSION,'8.4.0','>='),'message'=>PHP_VERSION];
        $checks['curl']=['ok'=>function_exists('curl_init'),'message'=>function_exists('curl_init')?'cURL enabled':'cURL missing'];
        $checks['openssl']=['ok'=>extension_loaded('openssl'),'message'=>extension_loaded('openssl')?'OpenSSL enabled':'OpenSSL missing'];
        $checks['csrf']=['ok'=>!empty($_SESSION['csrf']),'message'=>'Session CSRF token ready'];
        $ig=db()->prepare('SELECT status,token_expires_at,last_sync_at FROM instagram_accounts WHERE user_id=? ORDER BY id DESC LIMIT 1');$ig->execute([$id]);$acc=$ig->fetch();$checks['instagram']=['ok'=>(bool)$acc && $acc['status']==='connected','message'=>$acc?$acc['status']:'not connected'];
        json_response(['ok'=>true,'version'=>'47.0.0','checks'=>$checks,'server_time'=>date(DATE_ATOM),'timezone'=>$config['app']['timezone']??'']);
    }

    if ($path === 'notifications') {
        require_method('GET'); $id=current_user_id();
        $s=db()->prepare('SELECT id,type,title,body,read_at,created_at FROM notifications WHERE user_id=? ORDER BY created_at DESC, id DESC LIMIT 50');$s->execute([$id]);
        $items=$s->fetchAll(); $unread=0; foreach($items as $x){if(empty($x['read_at']))$unread++;}
        json_response(['ok'=>true,'items'=>$items,'unread'=>$unread]);
    }
    if ($path === 'notifications/unread') {
        require_method('GET'); $id=current_user_id();
        $s=db()->prepare('SELECT id,type,title,body,created_at FROM notifications WHERE user_id=? AND read_at IS NULL ORDER BY created_at DESC, id DESC LIMIT 20');$s->execute([$id]); $items=$s->fetchAll();
        json_response(['ok'=>true,'items'=>$items,'unread'=>count($items)]);
    }
    if ($path === 'notifications/read') {
        require_method('POST'); require_csrf(); $id=current_user_id(); $in=input_json(); $nid=(int)($in['id']??0);
        if($nid<1) json_response(['ok'=>false,'message'=>'شناسه اعلان نامعتبر است.'],422);
        $s=db()->prepare('UPDATE notifications SET read_at=NOW() WHERE id=? AND user_id=?');$s->execute([$nid,$id]);json_response(['ok'=>true,'updated'=>$s->rowCount()]);
    }
    if ($path === 'notifications/read-all') {
        require_method('POST'); require_csrf(); $id=current_user_id();
        $s=db()->prepare('UPDATE notifications SET read_at=NOW() WHERE user_id=? AND read_at IS NULL');$s->execute([$id]); json_response(['ok'=>true,'updated'=>$s->rowCount()]);
    }

    if ($path === 'analytics/overview') {
        $id=current_user_id(); $days=max(7,min(90,(int)($_GET['days']??30))); $s=db()->prepare('SELECT metric_date,followers,reach,impressions,engagement,saves,shares,profile_visits FROM analytics_daily WHERE user_id=? AND metric_date>=DATE_SUB(CURDATE(),INTERVAL ? DAY) ORDER BY metric_date ASC');$s->execute([$id,$days]);$rows=$s->fetchAll();
        $latest=end($rows) ?: null; json_response(['ok'=>true,'days'=>$days,'latest'=>$latest,'series'=>$rows]);
    }


    if ($path === 'content/upload') {
        require_method('POST'); require_csrf(); $id=current_user_id();
        if(empty($_FILES['file']) || !is_array($_FILES['file'])) json_response(['ok'=>false,'message'=>'فایلی دریافت نشد.'],422);
        $f=$_FILES['file']; if(($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) json_response(['ok'=>false,'message'=>'آپلود فایل ناموفق بود.'],422);
        if((int)($f['size']??0)>80*1024*1024) json_response(['ok'=>false,'message'=>'حجم فایل بیش از 80MB است.'],422);
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        $allowed=['image/jpeg'=>'jpg','image/png'=>'png','video/mp4'=>'mp4','video/quicktime'=>'mov'];
        if(!isset($allowed[$mime])) json_response(['ok'=>false,'message'=>'فرمت فایل پشتیبانی نمی‌شود.'],415);
        $baseDir=dirname(__DIR__,2).'/uploads/content/'.$id; if(!is_dir($baseDir) && !mkdir($baseDir,0750,true)) json_response(['ok'=>false,'message'=>'ساخت پوشه رسانه ناموفق بود.'],500);
        $name=bin2hex(random_bytes(12)).'.'.$allowed[$mime]; $dest=$baseDir.'/'.$name; if(!move_uploaded_file($f['tmp_name'],$dest)) json_response(['ok'=>false,'message'=>'ذخیره فایل ناموفق بود.'],500);
        $baseUrl=rtrim($config['app']['base_url'],'/'); $url=$baseUrl.'/uploads/content/'.$id.'/'.$name; audit($id,'content.upload',['mime'=>$mime,'size'=>(int)$f['size']]); json_response(['ok'=>true,'media_url'=>$url,'mime'=>$mime,'size'=>(int)$f['size']],201);
    }

    if ($path === 'integrations/catalog') {
        require_method('GET');
        json_response(['ok'=>true,'providers'=>[
            ['id'=>'openai','name'=>'OpenAI','capabilities'=>['text','caption','score','strategy'],'test_mode'=>'models'],
            ['id'=>'gemini','name'=>'Google Gemini','capabilities'=>['text','vision','caption','strategy'],'test_mode'=>'generate'],
            ['id'=>'groq','name'=>'Groq / Llama','capabilities'=>['text','caption','score'],'test_mode'=>'models'],
            ['id'=>'gapgpt','name'=>'GapGPT','capabilities'=>['text','caption'],'test_mode'=>'configured'],
            ['id'=>'stability','name'=>'Stability AI','capabilities'=>['image'],'test_mode'=>'account'],
            ['id'=>'deepai','name'=>'DeepAI','capabilities'=>['text','image'],'test_mode'=>'configured'],
            ['id'=>'fal','name'=>'Fal.ai','capabilities'=>['image','video'],'test_mode'=>'configured'],
            ['id'=>'kling','name'=>'Kling AI','capabilities'=>['video'],'test_mode'=>'configured'],
            ['id'=>'byteplus','name'=>'BytePlus Ark','capabilities'=>['text','multimodal'],'test_mode'=>'configured'],
            ['id'=>'piapi','name'=>'PiAPI','capabilities'=>['image','video'],'test_mode'=>'configured'],
            ['id'=>'maxrouter','name'=>'MaxRouter','capabilities'=>['routing','text'],'test_mode'=>'configured'],
            ['id'=>'cloudflare','name'=>'Cloudflare AI','capabilities'=>['edge_ai','text'],'test_mode'=>'account']
        ]]);
    }

    if ($path === 'ai/router') {
        $id=current_user_id();
        if($_SERVER['REQUEST_METHOD']==='GET'){
            $v=v24_integration_value($id,'ai_routing'); $routing=$v?json_decode($v,true):null;
            json_response(['ok'=>true,'routing'=>$routing?:['mode'=>'auto','tasks'=>[]]]);
        }
        require_method('POST'); require_csrf(); $in=input_json();
        $routing=$in['routing']??null; if(!is_array($routing)) json_response(['ok'=>false,'message'=>'routing نامعتبر است.'],422);
        $routing['mode']=in_array(($routing['mode']??'auto'),['auto','manual'],true)?$routing['mode']:'auto';
        $tasks=is_array($routing['tasks']??null)?$routing['tasks']:[];
        $allowed=['openai','gemini','groq','gapgpt','stability','deepai','fal','kling','byteplus','piapi','maxrouter','cloudflare','builtin','auto'];
        foreach($tasks as $k=>$p) if(!in_array((string)$p,$allowed,true)) $tasks[$k]='auto';
        $routing['tasks']=$tasks;
        $st=db()->prepare('INSERT INTO system_settings(user_id,setting_group,setting_key,setting_value,is_secret) VALUES(?,?,?,?,0) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_secret=0');$st->execute([$id,'integrations','ai_routing',json_encode($routing,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        audit($id,'ai.router.update',['mode'=>$routing['mode']]); json_response(['ok'=>true,'routing'=>$routing]);
    }

    if ($path === 'integrations/test') {
        require_method('POST'); require_csrf(); $id=current_user_id(); $in=input_json(); $provider=trim((string)($in['provider']??''));
        $key=v24_integration_value($id,$provider.'_api_key');
        if($provider==='cloudflare'){
            $account=v24_integration_value($id,'cloudflare_account_id');
            if(!$key||!$account) json_response(['ok'=>false,'message'=>'Cloudflare Account ID و API Token تنظیم نشده‌اند.'],422);
            $r=curl_json('https://api.cloudflare.com/client/v4/accounts/'.rawurlencode($account).'/ai/models/search',['headers'=>['Authorization: Bearer '.$key,'Accept: application/json'],'timeout'=>20]);
            if($r['status']<200||$r['status']>=300) json_response(['ok'=>false,'message'=>'Cloudflare پاسخ HTTP '.$r['status']],502);
            audit($id,'integration.test',['provider'=>$provider]); json_response(['ok'=>true,'provider'=>$provider,'message'=>'Cloudflare API فعال است.']);
        }
        if($provider==='openai'){
            if(!$key) json_response(['ok'=>false,'message'=>'OpenAI API Key تنظیم نشده است.'],422);
            $r=curl_json('https://api.openai.com/v1/models',['headers'=>['Authorization: Bearer '.$key,'Accept: application/json'],'timeout'=>20]);
        } elseif($provider==='gemini') {
            if(!$key) json_response(['ok'=>false,'message'=>'Gemini API Key تنظیم نشده است.'],422);
            $model=v24_integration_value($id,'gemini_model')?:'gemini-2.5-flash';
            $r=curl_json('https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent?key='.rawurlencode($key),['method'=>'POST','headers'=>['Content-Type: application/json'],'body'=>json_encode(['contents'=>[['parts'=>[['text'=>'Reply with OK only.']]]]],JSON_UNESCAPED_UNICODE),'timeout'=>20]);
        } elseif($provider==='groq') {
            if(!$key) json_response(['ok'=>false,'message'=>'Groq API Key تنظیم نشده است.'],422);
            $r=curl_json('https://api.groq.com/openai/v1/models',['headers'=>['Authorization: Bearer '.$key,'Accept: application/json'],'timeout'=>20]);
        } elseif($provider==='stability') {
            if(!$key) json_response(['ok'=>false,'message'=>'Stability API Key تنظیم نشده است.'],422);
            $r=curl_json('https://api.stability.ai/v1/user/account',['headers'=>['Authorization: Bearer '.$key,'Accept: application/json'],'timeout'=>20]);
        } else {
            if(!$key) json_response(['ok'=>false,'message'=>'API Key تنظیم نشده است.'],422);
            // Providers with vendor-specific/non-uniform health APIs are safely validated here.
            $base=v24_integration_value($id,$provider.'_base_url');
            if($base){
                $headers=['Authorization: Bearer '.$key,'Accept: application/json'];
                $r=curl_json(rtrim($base,'/'),['headers'=>$headers,'timeout'=>20]);
                if($r['status']<200||$r['status']>=300) json_response(['ok'=>false,'message'=>'Provider پاسخ HTTP '.$r['status']],502);
                audit($id,'integration.test',['provider'=>$provider,'mode'=>'base_url']); json_response(['ok'=>true,'provider'=>$provider,'message'=>'Provider endpoint پاسخ داد.']);
            }
            audit($id,'integration.test',['provider'=>$provider,'mode'=>'configured']);
            json_response(['ok'=>true,'provider'=>$provider,'message'=>'کلید ذخیره شده و Provider برای اتصال اختصاصی آماده است.','live_test'=>false]);
        }
        if(($r['status']??0)<200||($r['status']??0)>=300) json_response(['ok'=>false,'provider'=>$provider,'message'=>'Provider پاسخ HTTP '.($r['status']??0)],502);
        audit($id,'integration.test',['provider'=>$provider,'mode'=>'live']); json_response(['ok'=>true,'provider'=>$provider,'message'=>'اتصال Provider موفق است.','live_test'=>true]);
    }

    if ($path === 'ai/generate') {
        require_method('POST'); require_csrf(); $id=current_user_id(); $in=input_json(); global $config; $provider=$config['ai']['provider']; $sv=db()->prepare('SELECT setting_key,setting_value,is_secret FROM system_settings WHERE user_id=? AND setting_group=\"integrations\"');$sv->execute([$id]);$iset=[];foreach($sv->fetchAll() as $row){$iset[$row['setting_key']]=((int)$row['is_secret']===1)?ig_decrypt((string)$row['setting_value']):(string)$row['setting_value'];} if(!empty($iset['ai_provider']))$provider=$iset['ai_provider']; $topic=trim((string)($in['topic']??'معرفی محصول جدید'));$tone=trim((string)($in['tone']??'حرفه‌ای و صمیمی'));$type=$in['type']??'reel';
        $prompt="برای یک پیج اینستاگرام، یک پکیج {$type} درباره «{$topic}» با لحن «{$tone}» تولید کن. خروجی شامل hook، caption، CTA و 8 hashtag باشد. فارسی و کاربردی بنویس.";
        if ($provider==='builtin') json_response(['ok'=>true,'provider'=>'builtin','demo'=>true,'result'=>['hook'=>'شروع قدرتمند متناسب با موضوع','caption'=>"{$topic}؛ یک متن کوتاه و کاربردی با لحن {$tone}.",'cta'=>'نظر خودت را بنویس.','hashtags'=>['#instagram','#content','#{$type}']]]);
        $url='';$headers=['Content-Type: application/json'];$body=[];
        if($provider==='gemini' && (($iset['gemini_api_key']??$config['ai']['gemini_api_key'])!=='') ){$key=($iset['gemini_api_key']??$config['ai']['gemini_api_key']);$url='https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode(($iset['gemini_model']??$config['ai']['gemini_model'])).':generateContent?key='.rawurlencode($key);$body=['contents'=>[['parts'=>[['text'=>$prompt]]]]];}
        elseif($provider==='groq' && (($iset['groq_api_key']??$config['ai']['groq_api_key'])!=='') ){$url='https://api.groq.com/openai/v1/chat/completions';$headers[]='Authorization: Bearer '.($iset['groq_api_key']??$config['ai']['groq_api_key']);$body=['model'=>($iset['groq_model']??$config['ai']['groq_model']),'messages'=>[['role'=>'user','content'=>$prompt]],'temperature'=>0.7];}
        elseif($provider==='gapgpt' && !empty($iset['gapgpt_api_key']) && !empty($iset['gapgpt_base_url'])){$url=rtrim($iset['gapgpt_base_url'],'/').'/chat/completions';$headers[]='Authorization: Bearer '.$iset['gapgpt_api_key'];$body=['model'=>($iset['gapgpt_model']??'gpt-4o-mini'),'messages'=>[['role'=>'user','content'=>$prompt]],'temperature'=>0.7];}
        elseif($provider==='openai' && !empty($iset['openai_api_key'])){$url='https://api.openai.com/v1/chat/completions';$headers[]='Authorization: Bearer '.$iset['openai_api_key'];$body=['model'=>($iset['openai_model']??'gpt-4o-mini'),'messages'=>[['role'=>'user','content'=>$prompt]],'temperature'=>0.7];}
        else json_response(['ok'=>false,'message'=>'AI provider هنوز در config فعال نشده است.'],503);
        $r=curl_json($url,['method'=>'POST','headers'=>$headers,'body'=>json_encode($body),'timeout'=>60]); if($r['status']<200||$r['status']>=300)json_response(['ok'=>false,'message'=>'AI provider error','provider_status'=>$r['status']],502);audit($id,'ai.generate',['provider'=>$provider]);json_response(['ok'=>true,'provider'=>$provider,'raw'=>$r['data']]);
    }

    if ($path === 'ai/score') {
        require_method('POST'); require_csrf(); $id=current_user_id();$in=input_json();$caption=trim((string)($in['caption']??''));$len=mb_strlen($caption);$score=min(100,40+(int)min(35,$len/5)+(preg_match('/[؟?]/u',$caption)?10:0)+(preg_match('/(کامنت|نظر|ذخیره|ارسال|share|save)/iu',$caption)?15:0));audit($id,'ai.score',['score'=>$score]);json_response(['ok'=>true,'score'=>$score,'mode'=>'heuristic','message'=>'برای امتیازدهی هوش مصنوعی واقعی، provider را فعال کنید.']);
    }

    if ($path === 'analytics/sync-content-performance') {
        require_method('POST'); require_csrf(); $id=current_user_id();
        $s=db()->prepare('SELECT * FROM instagram_accounts WHERE user_id=? AND status="connected" ORDER BY id DESC LIMIT 1'); $s->execute([$id]); $acc=$s->fetch();
        if(!$acc) json_response(['ok'=>false,'message'=>'Instagram متصل نیست.'],404);
        $limit=max(1,min(100,(int)(input_json()['limit']??($config['instagram']['media_sync_limit']??25))));
        $delay=max(0,min(2000,(int)($config['instagram']['media_sync_delay_ms']??250)));
        $run=db()->prepare('INSERT INTO media_sync_runs(user_id,instagram_account_id,status) VALUES(?, ?, "running")'); $run->execute([$id,$acc['id']]); $runId=(int)db()->lastInsertId();
        $scanned=0;$synced=0;$skipped=0;$failed=0;$errors=[];
        try{
            $token=ig_decrypt($acc['access_token']); $media=ig_media($acc['ig_user_id'],$token,$limit);
            foreach(($media['data']??[]) as $m){
                $scanned++; $mediaId=trim((string)($m['id']??'')); if($mediaId===''){ $skipped++; continue; }
                $q=db()->prepare('SELECT id,type,status FROM content_items WHERE user_id=? AND external_id=? LIMIT 1');$q->execute([$id,$mediaId]);$content=$q->fetch();
                if(!$content){$skipped++;continue;}
                try{
                    $ins=ig_media_insights($mediaId,$token,(string)($m['media_type']??'')); $map=ig_media_insight_map($ins);
                    $reach=(int)round($map['reach']??0); if($reach<=0){$skipped++;continue;}
                    $views=(int)round($map['views']??0);$likes=(int)round($map['likes']??($m['like_count']??0));$comments=(int)round($map['comments']??($m['comments_count']??0));$saves=(int)round($map['saved']??0);$shares=(int)round($map['shares']??0);$total=(int)round($map['total_interactions']??($likes+$comments+$saves+$shares));
                    $payload=json_encode(['media'=>$m,'insights'=>$ins],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                    db()->prepare('INSERT INTO content_performance_snapshots(user_id,content_id,external_media_id,views,reach,likes,comments,saves,shares,total_interactions,raw_payload) VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,$content['id'],$mediaId,$views,$reach,$likes,$comments,$saves,$shares,$total,$payload]);
                    $date=date('Y-m-d'); db()->prepare('INSERT INTO content_performance(user_id,content_id,external_media_id,metric_date,reach,impressions,likes,comments,saves,shares,source,raw_payload) VALUES(?,?,?,?,?,?,?,?,?,?,"meta",?) ON DUPLICATE KEY UPDATE external_media_id=VALUES(external_media_id),reach=VALUES(reach),impressions=VALUES(impressions),likes=VALUES(likes),comments=VALUES(comments),saves=VALUES(saves),shares=VALUES(shares),source="meta",raw_payload=VALUES(raw_payload),updated_at=NOW()')->execute([$id,$content['id'],$mediaId,$date,$reach,$views,$likes,$comments,$saves,$shares,$payload]);
                    learning_apply_for_content($id,(int)$content['id']); $synced++; if($delay>0) usleep($delay*1000);
                }catch(Throwable $e){$failed++;if(count($errors)<5)$errors[]=['media_id'=>$mediaId,'message'=>$e->getMessage()];}
            }
            $status=$failed>0?($synced>0?'partial':'failed'):'completed'; db()->prepare('UPDATE media_sync_runs SET scanned=?,synced=?,skipped=?,failed=?,status=?,error_message=?,completed_at=NOW() WHERE id=?')->execute([$scanned,$synced,$skipped,$failed,$status,$errors?json_encode($errors,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,$runId]);
            audit($id,'instagram.media_performance_sync',['scanned'=>$scanned,'synced'=>$synced,'skipped'=>$skipped,'failed'=>$failed]); notify($id,'analytics','عملکرد محتوا به‌روزرسانی شد',"{$synced} محتوای متصل به Media ID از Insights واقعی Instagram همگام شد.");
            json_response(['ok'=>true,'run_id'=>$runId,'scanned'=>$scanned,'synced'=>$synced,'skipped'=>$skipped,'failed'=>$failed,'errors'=>$errors,'source'=>'instagram_media_insights']);
        }catch(Throwable $e){ db()->prepare('UPDATE media_sync_runs SET scanned=?,synced=?,skipped=?,failed=?,status="failed",error_message=?,completed_at=NOW() WHERE id=?')->execute([$scanned,$synced,$skipped,$failed,$e->getMessage(),$runId]); audit($id,'instagram.media_performance_sync_error',['message'=>$e->getMessage()]); json_response(['ok'=>false,'message'=>$e->getMessage(),'run_id'=>$runId],502); }
    }

    if ($path === 'analytics/media-sync-status') {
        require_method('GET'); $id=current_user_id(); $s=db()->prepare('SELECT id,scanned,synced,skipped,failed,status,error_message,created_at,completed_at FROM media_sync_runs WHERE user_id=? ORDER BY id DESC LIMIT 10');$s->execute([$id]);json_response(['ok'=>true,'items'=>$s->fetchAll()]);
    }

    if ($path === 'analytics/sync-instagram') {
        require_method('POST'); require_csrf(); $id=current_user_id();
        $s=db()->prepare('SELECT * FROM instagram_accounts WHERE user_id=? AND status="connected" ORDER BY id DESC LIMIT 1'); $s->execute([$id]); $acc=$s->fetch();
        if(!$acc) json_response(['ok'=>false,'message'=>'Instagram متصل نیست.'],404);
        $days=max(1,min(30,(int)(input_json()['days']??30)));
        $log=db()->prepare('INSERT INTO analytics_sync_log(user_id,instagram_account_id,period_days,status) VALUES(?,?,?,"running")');$log->execute([$id,$acc['id'],$days]);$logId=(int)db()->lastInsertId();
        try{
            $token=ig_decrypt($acc['access_token']); $data=ig_insights($acc['ig_user_id'],$token,$days); $rows=[];
            foreach(($data['data']??[]) as $metric){
                $name=(string)($metric['name']??'');
                foreach(($metric['values']??[]) as $v){
                    $date=substr((string)($v['end_time']??date('Y-m-d')),0,10); $value=$v['value']??0;
                    if(is_array($value)) continue;
                    $row=['metric_date'=>$date,'followers'=>0,'reach'=>0,'impressions'=>0,'engagement'=>0,'saves'=>0,'shares'=>0,'profile_visits'=>0];
                    if($name==='follower_count')$row['followers']=(int)$value;
                    elseif($name==='reach')$row['reach']=(int)$value;
                    elseif(in_array($name,['total_interactions','accounts_engaged'],true))$row['engagement']=(float)$value;
                    elseif($name==='profile_views')$row['profile_visits']=(int)$value;
                    $rows[$date]=array_merge($rows[$date]??[],$row);
                }
            }
            foreach($rows as $date=>$row){
                $q=db()->prepare('INSERT INTO analytics_daily(user_id,metric_date,followers,reach,impressions,engagement,saves,shares,profile_visits) VALUES(?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE followers=VALUES(followers),reach=VALUES(reach),impressions=VALUES(impressions),engagement=VALUES(engagement),saves=VALUES(saves),shares=VALUES(shares),profile_visits=VALUES(profile_visits)');
                $q->execute([$id,$date,$row['followers']??0,$row['reach']??0,$row['impressions']??0,$row['engagement']??0,$row['saves']??0,$row['shares']??0,$row['profile_visits']??0]);
            }
            db()->prepare('UPDATE analytics_sync_log SET metrics=?,status="completed",completed_at=NOW() WHERE id=?')->execute([json_encode(array_keys($rows),JSON_UNESCAPED_UNICODE),$logId]);
            audit($id,'analytics.instagram_sync',['rows'=>count($rows)]); notify($id,'analytics','Analytics به‌روزرسانی شد','داده‌های قابل‌دسترسی Instagram وارد داشبورد شد.');
            json_response(['ok'=>true,'rows'=>count($rows),'source'=>'instagram_insights']);
        }catch(Throwable $e){db()->prepare('UPDATE analytics_sync_log SET status="failed",error_message=?,completed_at=NOW() WHERE id=?')->execute([$e->getMessage(),$logId]);audit($id,'analytics.instagram_sync_error',['message'=>$e->getMessage()]);json_response(['ok'=>false,'message'=>$e->getMessage()],502);}
    }

    if ($path === 'autopilot/run') {
        require_method('POST'); require_csrf(); $id=current_user_id(); $in=input_json();
        $goal=trim((string)($in['goal']??'')); $days=max(7,min(90,(int)($in['days']??30)));
        if($goal==='')json_response(['ok'=>false,'message'=>'هدف Growth Autopilot الزامی است.'],422);
        $s=db()->prepare('INSERT INTO jobs(user_id,job_type,payload,available_at) VALUES(?,?,?,NOW())');$s->execute([$id,'autopilot_run',json_encode(['goal'=>$goal,'days'=>$days],JSON_UNESCAPED_UNICODE)]);$jobId=(int)db()->lastInsertId();
        audit($id,'autopilot.queue',['job_id'=>$jobId,'goal'=>$goal,'days'=>$days]); notify($id,'ai','Autopilot در صف قرار گرفت','سیستم تحلیل و یادگیری پیج را آغاز خواهد کرد.');
        json_response(['ok'=>true,'queued'=>true,'job_id'=>$jobId],202);
    }

    if ($path === 'autopilot/latest') {
        $id=current_user_id();$s=db()->prepare('SELECT id,goal,horizon_days,provider,status,output,created_at,completed_at FROM autopilot_runs WHERE user_id=? ORDER BY id DESC LIMIT 1');$s->execute([$id]);$r=$s->fetch();if(!$r)json_response(['ok'=>true,'run'=>null]);$r['output']=json_decode((string)$r['output'],true);json_response(['ok'=>true,'run'=>$r]);
    }

    if ($path === 'learning/overview') {
        require_method('GET'); $id=current_user_id(); json_response(['ok'=>true,'learning'=>learning_overview($id)]);
    }

    if ($path === 'learning/performance') {
        require_method('POST'); require_csrf(); $id=current_user_id(); $in=input_json();
        $contentId=(int)($in['content_id']??0); if(!$contentId) json_response(['ok'=>false,'message'=>'content_id الزامی است.'],422);
        $s=db()->prepare('SELECT id FROM content_items WHERE id=? AND user_id=?'); $s->execute([$contentId,$id]); if(!$s->fetch()) json_response(['ok'=>false,'message'=>'محتوا متعلق به کاربر نیست.'],403);
        $date=trim((string)($in['metric_date']??date('Y-m-d'))); if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)) json_response(['ok'=>false,'message'=>'metric_date نامعتبر است.'],422);
        $source=(string)($in['source']??'meta'); if($source!=='meta') json_response(['ok'=>false,'message'=>'برای موتور یادگیری V11 فقط داده verified از Meta پذیرفته می‌شود.'],422);
        $vals=[]; foreach(['reach','impressions','likes','comments','saves','shares'] as $k){$vals[$k]=max(0,(int)($in[$k]??0));}
        if($vals['reach']<=0) json_response(['ok'=>false,'message'=>'برای یادگیری، reach واقعی Meta باید بیشتر از صفر باشد.'],422);
        $s=$db->prepare('INSERT INTO content_performance(user_id,content_id,external_media_id,metric_date,reach,impressions,likes,comments,saves,shares,source,raw_payload) VALUES(?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE external_media_id=VALUES(external_media_id),reach=VALUES(reach),impressions=VALUES(impressions),likes=VALUES(likes),comments=VALUES(comments),saves=VALUES(saves),shares=VALUES(shares),source="meta",raw_payload=VALUES(raw_payload),updated_at=NOW()');
        $s->execute([$id,$contentId,trim((string)($in['external_media_id']??''))?:null,$date,$vals['reach'],$vals['impressions'],$vals['likes'],$vals['comments'],$vals['saves'],$vals['shares'],'meta',json_encode($in,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        $learn=learning_apply_for_content($id,$contentId); audit($id,'learning.performance.ingest',['content_id'=>$contentId,'source'=>'meta','learning'=>$learn]);
        json_response(['ok'=>true,'learning'=>$learn]);
    }

    if ($path === 'learning/history') {
        require_method('GET'); $id=current_user_id(); $limit=max(1,min(100,(int)($_GET['limit']??30)));
        $s=$db->prepare('SELECT e.*,c.title,c.type FROM learning_events e JOIN content_items c ON c.id=e.content_id WHERE e.user_id=? ORDER BY e.id DESC LIMIT '.$limit);$s->execute([$id]);
        $rows=$s->fetchAll(); foreach($rows as &$r){$r['evidence']=json_decode((string)$r['evidence'],true);} unset($r); json_response(['ok'=>true,'items'=>$rows]);
    }

    if ($path === 'decision/next') {
        require_method('GET'); $id=current_user_id(); json_response(['ok'=>true,'decision'=>decision_next($id)]);
    }

    if ($path === 'decision/rebuild') {
        require_method('POST'); require_csrf(); $id=current_user_id(); attribution_build($id); $decision=decision_next($id); audit($id,'decision.rebuild',['sample_count'=>$decision['sample_count']??0,'confidence'=>$decision['confidence']??0]); json_response(['ok'=>true,'decision'=>$decision]);
    }

    if ($path === 'attribution/overview') {
        require_method('GET'); $id=current_user_id(); json_response(['ok'=>true,'attribution'=>attribution_overview($id)]);
    }

    if ($path === 'attribution/rebuild') {
        require_method('POST'); require_csrf(); $id=current_user_id(); $result=attribution_build($id); if(($result['status']??'')==='insufficient_data') json_response(['ok'=>false]+$result,422); audit($id,'attribution.rebuild',['sample_count'=>$result['sample_count']??0]); notify($id,'analytics','AI Attribution به‌روزرسانی شد','وزن عوامل عملکرد با داده واقعی Meta محاسبه شد.'); json_response(['ok'=>true,'attribution'=>$result]);
    }

    if ($path === 'ab/experiments') {
        $id=current_user_id();
        if($_SERVER['REQUEST_METHOD']==='GET'){
            $s=$db->prepare('SELECT * FROM ab_experiments WHERE user_id=? ORDER BY id DESC LIMIT 30');$s->execute([$id]);$items=$s->fetchAll();
            foreach($items as &$e){$v=$db->prepare('SELECT id,variant_key,label,content_id,exposure_count,reach,interactions,engagement_rate FROM ab_variants WHERE experiment_id=? ORDER BY id');$v->execute([$e['id']]);$e['variants']=$v->fetchAll();} unset($e);json_response(['ok'=>true,'items'=>$items]);
        }
        require_method('POST'); require_csrf(); $in=input_json(); $name=trim((string)($in['name']??''));$dim=(string)($in['test_dimension']??'hook');$metric=(string)($in['metric_key']??'engagement_rate');
        if($name===''||!in_array($dim,['hook','cta','format','time'],true))json_response(['ok'=>false,'message'=>'نام و test_dimension معتبر الزامی است.'],422);
        $s=$db->prepare('INSERT INTO ab_experiments(user_id,name,test_dimension,metric_key,status) VALUES(?,?,?,?,"draft")');$s->execute([$id,$name,$dim,$metric]);$eid=(int)$db->lastInsertId();
        foreach([['A','نسخه A'],['B','نسخه B']] as $v){$q=$db->prepare('INSERT INTO ab_variants(experiment_id,variant_key,label) VALUES(?,?,?)');$q->execute([$eid,$v[0],$v[1]]);} audit($id,'ab.experiment.create',['id'=>$eid,'dimension'=>$dim]); json_response(['ok'=>true,'id'=>$eid],201);
    }

    if ($path === 'ab/experiments/start') {
        require_method('POST'); require_csrf(); $id=current_user_id(); $in=input_json(); $eid=(int)($in['experiment_id']??0);if(!$eid)json_response(['ok'=>false,'message'=>'experiment_id الزامی است.'],422);
        $s=$db->prepare('UPDATE ab_experiments SET status="running",started_at=NOW() WHERE id=? AND user_id=? AND status="draft"');$s->execute([$eid,$id]);if(!$s->rowCount())json_response(['ok'=>false,'message'=>'آزمایش پیدا نشد یا قبلاً اجرا شده است.'],409);audit($id,'ab.experiment.start',['id'=>$eid]);json_response(['ok'=>true]);
    }

    if ($path === 'ab/experiments/evaluate') {
        require_method('POST'); require_csrf(); $id=current_user_id(); $in=input_json(); $eid=(int)($in['experiment_id']??0);if(!$eid)json_response(['ok'=>false,'message'=>'experiment_id الزامی است.'],422);
        $s=$db->prepare('SELECT * FROM ab_experiments WHERE id=? AND user_id=?');$s->execute([$eid,$id]);$exp=$s->fetch();if(!$exp)json_response(['ok'=>false,'message'=>'آزمایش پیدا نشد.'],404);
        $s=$db->prepare('SELECT * FROM ab_variants WHERE experiment_id=? ORDER BY engagement_rate DESC');$s->execute([$eid]);$vars=$s->fetchAll();$valid=array_values(array_filter($vars,fn($v)=>$v['engagement_rate']!==null));
        if(count($valid)<2)json_response(['ok'=>false,'message'=>'برای تعیین برنده حداقل دو Variant با داده عملکرد واقعی Meta لازم است.','status'=>'insufficient_data'],422);
        $a=$valid[0];$b=$valid[1];$winner=$a['engagement_rate']>$b['engagement_rate']?$a:$b;$diff=abs((float)$a['engagement_rate']-(float)$b['engagement_rate']);$conf=max(0,min(100,50+$diff*8));
        $s=$db->prepare('UPDATE ab_experiments SET status="completed",winner_variant_id=?,confidence=?,ended_at=NOW() WHERE id=? AND user_id=?');$s->execute([$winner['id'],$conf,$eid,$id]);audit($id,'ab.experiment.evaluate',['id'=>$eid,'winner'=>$winner['variant_key'],'confidence'=>$conf]);json_response(['ok'=>true,'winner'=>$winner['variant_key'],'confidence'=>round($conf,1),'difference'=>round($diff,3)]);
    }

    if ($path === 'instagram/status') {
        $id=current_user_id();$s=db()->prepare('SELECT ig_user_id,username,account_type,status,last_sync_at,token_expires_at FROM instagram_accounts WHERE user_id=? ORDER BY id DESC LIMIT 1');$s->execute([$id]);$row=$s->fetch();json_response(['ok'=>true,'connected'=>(bool)$row,'account'=>$row?:null]);
    }

    if ($path === 'instagram/connect') {
        require_method('GET');
        $id=current_user_id(); global $config;
        $metaAppId=v24_integration_value($id,'meta_app_id') ?: (string)$config['instagram']['app_id'];
        $metaSecret=v24_integration_value($id,'meta_app_secret') ?: (string)$config['instagram']['app_secret'];
        if(!$metaAppId||!$metaSecret) json_response(['ok'=>false,'message'=>'Meta App ID و App Secret را در API Command Center تنظیم کنید.'],503);
        $state=bin2hex(random_bytes(32));
        $_SESSION['ig_oauth_state']=$state; $_SESSION['ig_oauth_user_id']=$id;
        $url=rtrim($config['instagram']['oauth_authorize_url'],'?').'?'.http_build_query([
            'client_id'=>$metaAppId,
            'redirect_uri'=>$config['instagram']['redirect_uri'],
            'response_type'=>'code',
            'scope'=>$config['instagram']['scopes'],
            'state'=>$state
        ]);
        json_response(['ok'=>true,'authorize_url'=>$url]);
    }

    if ($path === 'instagram/callback') {
        global $config;
        $state=(string)($_GET['state']??''); $code=(string)($_GET['code']??'');
        $sessionState=(string)($_SESSION['ig_oauth_state']??''); $id=(int)($_SESSION['ig_oauth_user_id']??0);
        if(!$id || !$state || !$sessionState || !hash_equals($sessionState,$state)) json_response(['ok'=>false,'message'=>'OAuth state نامعتبر است.'],400);
        if(isset($_GET['error'])) json_response(['ok'=>false,'message'=>'کاربر اتصال Instagram را لغو کرد.','error'=>$_GET['error']],400);
        if($code==='') json_response(['ok'=>false,'message'=>'OAuth code دریافت نشد.'],400);
        try {
            $metaAppId=v24_integration_value($id,'meta_app_id') ?: (string)$config['instagram']['app_id'];
            $metaSecret=v24_integration_value($id,'meta_app_secret') ?: (string)$config['instagram']['app_secret'];
            if(!$metaAppId||!$metaSecret) throw new RuntimeException('Meta App ID/Secret تنظیم نشده است.');
            $config['instagram']['app_id']=$metaAppId; $config['instagram']['app_secret']=$metaSecret;
            $short=ig_exchange_code($code);
            if($short['status']<200||$short['status']>=300) throw new RuntimeException('Code exchange failed: '.($short['data']['error_message']??$short['data']['error']['message']??'HTTP '.$short['status']));
            $shortToken=(string)($short['data']['access_token']??'');
            if($shortToken==='') throw new RuntimeException('Instagram access token در پاسخ وجود ندارد.');
            $long=ig_long_lived_token($shortToken);
            if($long['status']<200||$long['status']>=300) throw new RuntimeException('Long-lived token exchange failed: '.($long['data']['error']['message']??'HTTP '.$long['status']));
            $token=(string)($long['data']['access_token']??''); $expires=(int)($long['data']['expires_in']??5184000);
            if($token==='') throw new RuntimeException('Long-lived token دریافت نشد.');
            $profile=ig_profile($token);
            $igId=(string)($profile['id']??$short['data']['user_id']??''); if($igId==='') throw new RuntimeException('Instagram user id دریافت نشد.');
            $encrypted=ig_encrypt($token); $expiresAt=date('Y-m-d H:i:s',time()+$expires);
            $stmt=db()->prepare('INSERT INTO instagram_accounts(user_id,ig_user_id,username,account_type,access_token,token_expires_at,status,last_sync_at) VALUES(?,?,?,?,?,?,"connected",NOW()) ON DUPLICATE KEY UPDATE username=VALUES(username),account_type=VALUES(account_type),access_token=VALUES(access_token),token_expires_at=VALUES(token_expires_at),status="connected",last_sync_at=NOW()');
            $stmt->execute([$id,$igId,$profile['username']??null,$profile['account_type']??null,$encrypted,$expiresAt]);
            audit($id,'instagram.connected',['ig_user_id'=>$igId]); notify($id,'instagram','Instagram متصل شد','حساب @'.($profile['username']??''). ' با موفقیت متصل و Sync شد.');
            unset($_SESSION['ig_oauth_state'],$_SESSION['ig_oauth_user_id']);
            header('Location: '.rtrim($config['app']['base_url'],'/').'/?instagram=connected'); exit;
        } catch(Throwable $e) {
            audit($id,'instagram.oauth_error',['message'=>$e->getMessage()]);
            json_response(['ok'=>false,'message'=>$e->getMessage()],502);
        }
    }

    if ($path === 'instagram/sync') {
        require_method('POST'); require_csrf(); $id=current_user_id();
        $s=db()->prepare('SELECT * FROM instagram_accounts WHERE user_id=? AND status="connected" ORDER BY id DESC LIMIT 1'); $s->execute([$id]); $acc=$s->fetch();
        if(!$acc) json_response(['ok'=>false,'message'=>'هیچ حساب Instagram متصلی وجود ندارد.'],404);
        try { $token=ig_decrypt($acc['access_token']); $profile=ig_profile($token); $media=ig_media($acc['ig_user_id'],$token,25); db()->prepare('UPDATE instagram_accounts SET username=?,account_type=?,status="connected",last_sync_at=NOW() WHERE id=?')->execute([$profile['username']??$acc['username'],$profile['account_type']??$acc['account_type'],$acc['id']]); audit($id,'instagram.sync',['media_count'=>count($media['data']??[])]); json_response(['ok'=>true,'profile'=>$profile,'media'=>$media['data']??[],'paging'=>$media['paging']??null]); }
        catch(Throwable $e){ db()->prepare('UPDATE instagram_accounts SET status="error" WHERE id=?')->execute([$acc['id']]); json_response(['ok'=>false,'message'=>$e->getMessage()],502); }
    }

    if ($path === 'instagram/refresh') {
        require_method('POST'); require_csrf(); $id=current_user_id();
        $s=db()->prepare('SELECT * FROM instagram_accounts WHERE user_id=? AND status IN ("connected","expired") ORDER BY id DESC LIMIT 1');$s->execute([$id]);$acc=$s->fetch();if(!$acc)json_response(['ok'=>false,'message'=>'حساب Instagram پیدا نشد.'],404);
        try{$token=ig_decrypt($acc['access_token']);$r=ig_refresh_token($token);if($r['status']<200||$r['status']>=300)throw new RuntimeException($r['data']['error']['message']??'Token refresh failed');$new=(string)($r['data']['access_token']??'');$expires=(int)($r['data']['expires_in']??5184000);if(!$new)throw new RuntimeException('توکن جدید دریافت نشد.');db()->prepare('UPDATE instagram_accounts SET access_token=?,token_expires_at=?,status="connected" WHERE id=?')->execute([ig_encrypt($new),date('Y-m-d H:i:s',time()+$expires),$acc['id']]);audit($id,'instagram.token_refresh');notify($id,'security','توکن Instagram تمدید شد','توکن اتصال با موفقیت تمدید شد.');json_response(['ok'=>true,'expires_at'=>date(DATE_ATOM,time()+$expires)]);}catch(Throwable $e){json_response(['ok'=>false,'message'=>$e->getMessage()],502);}
    }

    if ($path === 'instagram/publish') {
        require_method('POST'); require_csrf(); $id=current_user_id(); $in=input_json(); $contentId=(int)($in['content_id']??0); $type=$in['type']??'IMAGE'; $caption=trim((string)($in['caption']??'')); $mediaUrl=trim((string)($in['media_url']??''));
        if(!in_array($type,['IMAGE','REELS','STORIES'],true) || $mediaUrl==='') json_response(['ok'=>false,'message'=>'type و media_url معتبر الزامی است.'],422);
        if(!filter_var($mediaUrl,FILTER_VALIDATE_URL)) json_response(['ok'=>false,'message'=>'media_url باید یک URL عمومی معتبر باشد.'],422);
        $s=db()->prepare('SELECT * FROM instagram_accounts WHERE user_id=? AND status="connected" ORDER BY id DESC LIMIT 1');$s->execute([$id]);$acc=$s->fetch();if(!$acc)json_response(['ok'=>false,'message'=>'Instagram متصل نیست.'],404);
        try{$token=ig_decrypt($acc['access_token']);$params=['media_type'=>$type];if($type!=='STORIES')$params['caption']=$caption;$params[$type==='IMAGE'||$type==='STORIES'?'image_url':'video_url']=$mediaUrl;$container=ig_publish_container($acc['ig_user_id'],$token,$params);$cid=(string)($container['id']??'');if(!$cid)throw new RuntimeException('Container ID دریافت نشد.');$deadline=time()+180;$status=['status_code'=>'IN_PROGRESS'];while(time()<$deadline){sleep(3);$status=ig_container_status($cid,$token);if(in_array($status['status_code']??'', ['FINISHED','ERROR','EXPIRED','PUBLISHED'],true))break;}if(($status['status_code']??'')!=='FINISHED')throw new RuntimeException('Media processing did not finish: '.($status['status_code']??'UNKNOWN'));$published=ig_publish_container_id($acc['ig_user_id'],$cid,$token); $mediaId=(string)($published['id']??''); if($contentId>0){$u=db()->prepare('UPDATE content_items SET status="published",published_at=NOW(),external_id=?,error_message=NULL WHERE id=? AND user_id=?');$u->execute([$mediaId?:null,$contentId,$id]);} audit($id,'instagram.publish',['container_id'=>$cid,'media_id'=>$mediaId,'content_id'=>$contentId?:null]);notify($id,'publish','محتوا منتشر شد','محتوای شما با موفقیت به Instagram ارسال شد و برای اندازه‌گیری عملکرد ثبت گردید.');json_response(['ok'=>true,'container'=>$container,'status'=>$status,'published'=>$published,'content_id'=>$contentId?:null]);}catch(Throwable $e){audit($id,'instagram.publish_error',['message'=>$e->getMessage()]);json_response(['ok'=>false,'message'=>$e->getMessage()],502);}
    }


    if ($path === 'mission/plan') {
        require_method('POST'); require_csrf(); $id=current_user_id(); $in=input_json();
        $mid=(int)($in['mission_id']??0);
        if(!$mid) json_response(['ok'=>false,'message'=>'mission_id الزامی است.'],422);
        $s=db()->prepare('SELECT id,goal,horizon_days,decision FROM mission_sessions WHERE id=? AND user_id=?');$s->execute([$mid,$id]);$m=$s->fetch();
        if(!$m) json_response(['ok'=>false,'message'=>'Mission پیدا نشد.'],404);
        $decision=json_decode((string)($m['decision']??'{}'),true)?:[];
        $r=v17_build_plan($id,$mid,(string)$m['goal'],(int)$m['horizon_days'],$decision);
        $queued=v17_queue_content_batch($id,$mid,$r['plan']);
        $ap=db()->prepare('INSERT INTO approval_items(user_id,mission_id,item_type,title,payload,status) VALUES(?,?,"decision",?,?,"pending")');
        $ap->execute([$id,$mid,'AI Mission Plan — تأیید برنامه انتشار',json_encode(['plan_id'=>$r['plan_id'],'queued_drafts'=>$queued,'kpis'=>$r['plan']['kpis'],'schedule'=>$r['plan']['schedule']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        audit($id,'mission.plan',['mission_id'=>$mid,'plan_id'=>$r['plan_id']]);
        json_response(['ok'=>true,'mission_id'=>$mid,'plan_id'=>$r['plan_id'],'plan'=>$r['plan']]);
    }

    if ($path === 'mission/plans') {
        require_method('GET'); $id=current_user_id();
        $s=db()->prepare('SELECT id,mission_id,status,plan,created_at,updated_at FROM mission_plans WHERE user_id=? ORDER BY id DESC LIMIT 10');$s->execute([$id]);
        $items=$s->fetchAll(); foreach($items as &$x)$x['plan']=json_decode((string)$x['plan'],true)?:[];
        json_response(['ok'=>true,'items'=>$items]);
    }

    if ($path === 'approvals') {
        $id=current_user_id();
        if($_SERVER['REQUEST_METHOD']==='GET'){ $s=db()->prepare('SELECT id,mission_id,content_id,item_type,title,payload,status,created_at FROM approval_items WHERE user_id=? ORDER BY id DESC LIMIT 30');$s->execute([$id]);$items=$s->fetchAll();foreach($items as &$x)$x['payload']=json_decode((string)$x['payload'],true)?:[];json_response(['ok'=>true,'items'=>$items]); }
        require_method('POST'); require_csrf(); $in=input_json(); $aid=(int)($in['approval_id']??0); $decision=$in['decision']??'';
        if(!$aid||!in_array($decision,['approved','rejected'],true))json_response(['ok'=>false,'message'=>'approval_id و decision معتبر الزامی است.'],422);
        $s=db()->prepare('SELECT * FROM approval_items WHERE id=? AND user_id=? AND status="pending"');$s->execute([$aid,$id]);$a=$s->fetch();if(!$a)json_response(['ok'=>false,'message'=>'Approval مورد نظر پیدا نشد.'],404);
        db()->prepare('UPDATE approval_items SET status=?,reviewed_at=NOW() WHERE id=? AND user_id=?')->execute([$decision,$aid,$id]);
        if($a['mission_id'])db()->prepare('UPDATE mission_sessions SET status=?,current_step=? WHERE id=? AND user_id=?')->execute([$decision==='approved'?'scheduled':'failed',$decision==='approved'?5:4,(int)$a['mission_id'],$id]);
        audit($id,'approval.review',['approval_id'=>$aid,'decision'=>$decision]); json_response(['ok'=>true,'status'=>$decision]);
    }

    if ($path === 'scheduler/recommendations') {
        require_method('GET'); $id=current_user_id();
        $s=db()->prepare('SELECT id,mission_id,recommended_at,score,reason,selected FROM scheduler_recommendations WHERE user_id=? AND recommended_at>=NOW() ORDER BY score DESC,recommended_at ASC LIMIT 12');$s->execute([$id]);$items=$s->fetchAll();foreach($items as &$x)$x['reason']=json_decode((string)$x['reason'],true)?:[];json_response(['ok'=>true,'items'=>$items]);
    }

    if ($path === 'mission/start') {
        require_method('POST'); require_csrf(); $id=current_user_id(); $in=input_json();
        $goal=trim((string)($in['goal']??'')); $days=max(7,min(90,(int)($in['days']??30)));
        if($goal==='') json_response(['ok'=>false,'message'=>'هدف Mission الزامی است.'],422);
        $decision=decision_next($id);
        $s=db()->prepare('INSERT INTO mission_sessions(user_id,goal,horizon_days,status,current_step,decision,approval_required) VALUES(?,?,?,"planning",1,?,1)');
        $s->execute([$id,$goal,$days,json_encode($decision,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        $mid=(int)db()->lastInsertId();
        audit($id,'mission.start',['mission_id'=>$mid,'goal'=>$goal,'days'=>$days]);
        notify($id,'ai','Mission جدید ایجاد شد','مرکز فرماندهی AI آماده اجرای Mission شماست.');
        json_response(['ok'=>true,'mission_id'=>$mid,'step'=>1,'decision'=>$decision]);
    }

    if ($path === 'mission/prepare') {
        require_method('POST'); require_csrf(); $id=current_user_id(); $in=input_json(); $mid=(int)($in['mission_id']??0);
        if(!$mid) json_response(['ok'=>false,'message'=>'mission_id الزامی است.'],422);
        $s=db()->prepare('SELECT * FROM mission_sessions WHERE id=? AND user_id=? LIMIT 1'); $s->execute([$mid,$id]); $m=$s->fetch();
        if(!$m) json_response(['ok'=>false,'message'=>'Mission پیدا نشد.'],404);
        $type=in_array(($in['type']??'reel'),['reel','post','story'],true)?$in['type']:'reel';
        $goal=(string)$m['goal'];
        $j=db()->prepare('INSERT INTO jobs(user_id,job_type,payload,available_at) VALUES(?,?,?,NOW())');
        $j->execute([$id,'autonomous_content',json_encode(['goal'=>$goal,'type'=>$type,'mission_id'=>$mid],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        $jid=(int)db()->lastInsertId();
        db()->prepare('UPDATE mission_sessions SET status="drafting",current_step=3,blueprint=? WHERE id=? AND user_id=?')->execute([json_encode(['queued'=>true,'job_id'=>$jid,'type'=>$type],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$mid,$id]);
        audit($id,'mission.prepare',['mission_id'=>$mid,'job_id'=>$jid]);
        json_response(['ok'=>true,'mission_id'=>$mid,'job_id'=>$jid,'status'=>'drafting','step'=>3]);
    }

    if ($path === 'mission/approve') {
        require_method('POST'); require_csrf(); $id=current_user_id(); $in=input_json(); $mid=(int)($in['mission_id']??0); $contentId=(int)($in['content_id']??0);
        if(!$mid) json_response(['ok'=>false,'message'=>'mission_id الزامی است.'],422);
        $s=db()->prepare('SELECT id FROM mission_sessions WHERE id=? AND user_id=?');$s->execute([$mid,$id]);if(!$s->fetch())json_response(['ok'=>false,'message'=>'Mission پیدا نشد.'],404);
        if($contentId){$c=db()->prepare('SELECT id FROM content_items WHERE id=? AND user_id=?');$c->execute([$contentId,$id]);if(!$c->fetch())json_response(['ok'=>false,'message'=>'محتوا متعلق به کاربر نیست.'],403);}
        db()->prepare('UPDATE mission_sessions SET status="awaiting_approval",current_step=4,content_id=COALESCE(?,content_id) WHERE id=? AND user_id=?')->execute([$contentId?:null,$mid,$id]);
        audit($id,'mission.approve',['mission_id'=>$mid,'content_id'=>$contentId?:null]);
        json_response(['ok'=>true,'mission_id'=>$mid,'status'=>'awaiting_approval','step'=>4,'approval_required'=>true]);
    }

    if ($path === 'mission/schedule') {
        require_method('POST'); require_csrf(); $id=current_user_id(); $in=input_json(); $mid=(int)($in['mission_id']??0); $contentId=(int)($in['content_id']??0); $when=trim((string)($in['scheduled_at']??''));
        if(!$mid||!$contentId||$when==='')json_response(['ok'=>false,'message'=>'mission_id، content_id و scheduled_at الزامی هستند.'],422);
        $dt=DateTime::createFromFormat('Y-m-d H:i:s',$when);if(!$dt)json_response(['ok'=>false,'message'=>'فرمت زمان باید YYYY-MM-DD HH:MM:SS باشد.'],422);
        $c=db()->prepare('SELECT id FROM content_items WHERE id=? AND user_id=?');$c->execute([$contentId,$id]);if(!$c->fetch())json_response(['ok'=>false,'message'=>'محتوا متعلق به کاربر نیست.'],403);
        db()->prepare('UPDATE content_items SET scheduled_at=?,status="queued" WHERE id=? AND user_id=?')->execute([$when,$contentId,$id]);
        db()->prepare('INSERT INTO jobs(user_id,job_type,payload,available_at) VALUES(?,?,?,?)')->execute([$id,'publish_content',json_encode(['content_id'=>$contentId,'mission_id'=>$mid],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$when]);
        db()->prepare('UPDATE mission_sessions SET status="scheduled",current_step=5,content_id=?,scheduled_at=? WHERE id=? AND user_id=?')->execute([$contentId,$when,$mid,$id]);
        notify($id,'schedule','Mission زمان‌بندی شد','محتوای تأییدشده در صف انتشار قرار گرفت.'); audit($id,'mission.schedule',['mission_id'=>$mid,'content_id'=>$contentId,'scheduled_at'=>$when]);
        json_response(['ok'=>true,'mission_id'=>$mid,'status'=>'scheduled','step'=>5,'scheduled_at'=>$when]);
    }

    if ($path === 'mission/status') {
        require_method('GET'); $id=current_user_id();
        $s=db()->prepare('SELECT id,goal,horizon_days,status,current_step,content_id,scheduled_at,published_media_id,created_at,updated_at,completed_at FROM mission_sessions WHERE user_id=? ORDER BY id DESC LIMIT 12');
        $s->execute([$id]); json_response(['ok'=>true,'items'=>$s->fetchAll()]);
    }

    if ($path === 'mission/log') {
        require_method('POST'); require_csrf(); $id=current_user_id(); $in=input_json();
        $action=trim((string)($in['action']??''));
        if ($action==='') json_response(['ok'=>false,'message'=>'action الزامی است.'],422);
        $s=db()->prepare('INSERT INTO mission_runs(user_id,action,status,payload,completed_at) VALUES(?,?,"done",?,NOW())');
        $s->execute([$id,$action,json_encode($in,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        audit($id,'mission.log',['action'=>$action]); json_response(['ok'=>true,'id'=>(int)db()->lastInsertId()]);
    }


    if ($path === 'ai/providers/test') {
        require_method('POST'); require_csrf(); $id=current_user_id(); $in=input_json();
        $providers=$in['providers']??['gemini','groq','openai','gapgpt']; if(!is_array($providers))$providers=[];
        $allowed=['gemini','groq','openai','gapgpt']; $providers=array_values(array_intersect($allowed,array_map('strval',$providers)));
        $rows=[];
        foreach($providers as $provider){
            $key=''; $url=''; $headers=['Content-Type: application/json']; $body=[]; $configured=false;
            try {
                $iset=[]; $q=db()->prepare('SELECT setting_key,setting_value,is_secret FROM system_settings WHERE user_id=? AND setting_group="integrations" AND setting_key LIKE ?');$q->execute([$id,$provider.'%']);foreach($q->fetchAll() as $r){$iset[$r['setting_key']]=((int)$r['is_secret']===1 && $r['setting_value']!=='••••••••')?ig_decrypt($r['setting_value']):(string)$r['setting_value'];}
                if($provider==='gemini'){$key=$iset['gemini_api_key']??$config['ai']['gemini_api_key']??'';$configured=$key!=='';$url='https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($iset['gemini_model']??$config['ai']['gemini_model']).':generateContent?key='.rawurlencode($key);$body=['contents'=>[['parts'=>[['text'=>'Reply with OK only.']]]]];}
                elseif($provider==='groq'){$key=$iset['groq_api_key']??$config['ai']['groq_api_key']??'';$configured=$key!=='';$url='https://api.groq.com/openai/v1/chat/completions';$headers[]='Authorization: Bearer '.$key;$body=['model'=>$iset['groq_model']??$config['ai']['groq_model'],'messages'=>[['role'=>'user','content'=>'Reply with OK only.']],'max_tokens'=>3];}
                elseif($provider==='openai'){$key=$iset['openai_api_key']??'';$configured=$key!=='';$url='https://api.openai.com/v1/chat/completions';$headers[]='Authorization: Bearer '.$key;$body=['model'=>$iset['openai_model']??'gpt-4o-mini','messages'=>[['role'=>'user','content'=>'Reply with OK only.']],'max_tokens'=>3];}
                else {$key=$iset['gapgpt_api_key']??$config['ai']['gapgpt_api_key']??'';$base=$iset['gapgpt_base_url']??$config['ai']['gapgpt_base_url']??'';$configured=$key!==''&&$base!=='';$url=rtrim($base,'/').'/chat/completions';$headers[]='Authorization: Bearer '.$key;$body=['model'=>$iset['gapgpt_model']??'gpt-4o-mini','messages'=>[['role'=>'user','content'=>'Reply with OK only.']],'max_tokens'=>3];}
                if(!$configured){$rows[]=['provider'=>$provider,'ok'=>false,'message'=>'Credential تنظیم نشده است.'];continue;}
                $started=microtime(true);$r=curl_json($url,['method'=>'POST','headers'=>$headers,'body'=>json_encode($body,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'timeout'=>20]);$ms=(int)round((microtime(true)-$started)*1000);$ok=$r['status']>=200&&$r['status']<300;$rows[]=['provider'=>$provider,'ok'=>$ok,'status'=>$r['status'],'latency_ms'=>$ms,'message'=>$ok?'پاسخ معتبر دریافت شد.':'Provider پاسخ معتبر نداد.'];
                audit($id,'ai.provider.test',['provider'=>$provider,'ok'=>$ok,'status'=>$r['status'],'latency_ms'=>$ms]);
            } catch(Throwable $e){$rows[]=['provider'=>$provider,'ok'=>false,'message'=>'خطا در تست Provider.'];audit($id,'ai.provider.test_error',['provider'=>$provider]);}
        }
        json_response(['ok'=>true,'items'=>$rows]);
    }

    if ($path === 'system/health/deep') {
        require_method('GET'); $id=current_user_id(); $items=[];
        $items[]=['label'=>'PHP','status'=>PHP_VERSION,'percent'=>version_compare(PHP_VERSION,'8.4.0','>=')?100:0];
        try{$dbStart=microtime(true);db()->query('SELECT 1');$ms=(int)round((microtime(true)-$dbStart)*1000);$items[]=['label'=>'Database','status'=>'OK • '.$ms.'ms','percent'=>100];}catch(Throwable $e){$items[]=['label'=>'Database','status'=>'ERROR','percent'=>0];}
        $items[]=['label'=>'Session','status'=>session_status()===PHP_SESSION_ACTIVE?'OK':'INACTIVE','percent'=>session_status()===PHP_SESSION_ACTIVE?100:0];
        try{$q=db()->prepare('SELECT COUNT(*) FROM jobs WHERE user_id=? AND status IN ("queued","processing")');$q->execute([$id]);$items[]=['label'=>'Queue','status'=>(int)$q->fetchColumn().' active','percent'=>100];}catch(Throwable $e){$items[]=['label'=>'Queue','status'=>'UNKNOWN','percent'=>0];}
        try{$q=db()->prepare('SELECT status FROM instagram_accounts WHERE user_id=? ORDER BY id DESC LIMIT 1');$q->execute([$id]);$ig=$q->fetchColumn();$items[]=['label'=>'Instagram','status'=>$ig?:'NOT CONNECTED','percent'=>$ig==='connected'?100:35];}catch(Throwable $e){$items[]=['label'=>'Instagram','status'=>'UNKNOWN','percent'=>0];}
        audit($id,'system.deep_health',['items'=>$items]); json_response(['ok'=>true,'items'=>$items,'time'=>date(DATE_ATOM)]);
    }

    /* ===== V41 Intelligence Core ===== */
    if ($path === 'growth/forecast') {
        require_method('GET'); $id=current_user_id();
        $s=db()->prepare('SELECT metric_date,followers,reach,engagement,saves,shares FROM analytics_daily WHERE user_id=? ORDER BY metric_date DESC LIMIT 30'); $s->execute([$id]); $rows=$s->fetchAll();
        if(count($rows)<2){json_response(['ok'=>true,'score'=>50,'direction'=>'insufficient_data','message'=>'برای پیش‌بینی واقعی حداقل دو نقطه داده لازم است.','forecast'=>[]]);}
        $latest=$rows[0]; $prev=$rows[1];
        $growth=function($x,$y){$x=(float)$x;$y=(float)$y; return $y==0?0:(($x-$y)/abs($y))*100;};
        $rf=$growth($latest['reach'],$prev['reach']); $ef=$growth($latest['engagement'],$prev['engagement']); $ff=$growth($latest['followers'],$prev['followers']);
        $score=max(0,min(100,round(55+$rf*.25+$ef*.25+$ff*.5))); $dir=$score>=58?'up':($score<=42?'down':'stable');
        $message=$dir==='up'?'روند فعلی مثبت است؛ روی قالب‌های موفق تمرکز و یک آزمایش کنترل‌شده اجرا کن.':($dir==='down'?'روند افت دارد؛ قبل از افزایش حجم انتشار، علت افت Reach و Engagement را بررسی کن.':'روند نسبتاً پایدار است؛ یک آزمایش کوچک برای پیدا کردن اهرم رشد بعدی اجرا کن.');
        json_response(['ok'=>true,'score'=>$score,'direction'=>$dir,'message'=>$message,'changes'=>['reach'=>round($rf,1),'engagement'=>round($ef,1),'followers'=>round($ff,1)],'forecast'=>['next_window'=>'7 days','confidence'=>count($rows)>=7?72:48]]);
    }
    if ($path === 'growth/trends') {
        require_method('GET'); $id=current_user_id();
        $s=db()->prepare('SELECT type,content_type,AVG(overall_score) avg_score,COUNT(*) samples FROM content_quality_reviews WHERE user_id=? GROUP BY type,content_type ORDER BY avg_score DESC LIMIT 12'); $s->execute([$id]); $rows=$s->fetchAll();
        $signals=[]; foreach($rows as $r){$signals[]=['label'=>'فرمت '.$r['content_type'],'score'=>round((float)$r['avg_score'],1),'samples'=>(int)$r['samples'],'reason'=>'از سابقه بازبینی محتوای همین پیج استخراج شده است.'];}
        if(!$signals)$signals=[['label'=>'Reel آموزشی','score'=>84,'samples'=>0,'reason'=>'سیگنال پایه؛ پس از اتصال داده واقعی شخصی‌سازی می‌شود.'],['label'=>'Hook مسئله‌محور','score'=>81,'samples'=>0,'reason'=>'فرضیه قابل آزمایش، نه تضمین اکسپلور.'],['label'=>'CTA ذخیره/ارسال','score'=>78,'samples'=>0,'reason'=>'برای آزمایش تعامل عمیق پیشنهاد شده است.']];
        json_response(['ok'=>true,'signals'=>$signals,'source'=>'first-party account data + explicit hypotheses']);
    }
    if ($path === 'growth/competitors') {
        $id=current_user_id();
        if($_SERVER['REQUEST_METHOD']==='GET'){require_method('GET');$s=db()->prepare('SELECT id,handle,display_name,status,last_snapshot_at,metadata,created_at FROM competitor_profiles WHERE user_id=? ORDER BY created_at DESC');$s->execute([$id]);$rows=$s->fetchAll();foreach($rows as &$r)$r['metadata']=json_decode((string)$r['metadata'],true)?:[];json_response(['ok'=>true,'items'=>$rows]);}
        require_method('POST'); require_csrf(); $in=input_json(); $handle=ltrim(trim((string)($in['handle']??'')),'@'); if($handle==='')json_response(['ok'=>false,'message'=>'شناسه رقیب الزامی است.'],422); if(!preg_match('/^[A-Za-z0-9._]{1,60}$/',$handle))json_response(['ok'=>false,'message'=>'شناسه رقیب معتبر نیست.'],422);
        $st=db()->prepare('INSERT INTO competitor_profiles(user_id,handle,display_name,metadata) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE display_name=VALUES(display_name),status="active"');$st->execute([$id,$handle,trim((string)($in['display_name']??'')),json_encode(['source'=>'user_declared'],JSON_UNESCAPED_UNICODE)]);audit($id,'growth.competitor.add',['handle'=>$handle]);json_response(['ok'=>true]);
    }
    if ($path === 'growth/experiments/update') {
        require_method('POST'); require_csrf(); $id=current_user_id(); $in=input_json(); $eid=(int)($in['id']??0); if($eid<1)json_response(['ok'=>false,'message'=>'شناسه آزمایش نامعتبر است.'],422);
        $winner=in_array(($in['winner']??''),['A','B'],true)?$in['winner']:null; $status=in_array(($in['status']??''),['draft','running','completed','cancelled'],true)?$in['status']:'running'; $conf=max(0,min(100,(float)($in['confidence']??0)));
        $st=db()->prepare('UPDATE growth_experiments SET status=?,winner=?,confidence=?,ended_at=IF(?="completed",NOW(),ended_at) WHERE id=? AND user_id=?');$st->execute([$status,$winner,$conf,$status,$eid,$id]); if(!$st->rowCount())json_response(['ok'=>false,'message'=>'آزمایش پیدا نشد.'],404);audit($id,'growth.experiment.update',['id'=>$eid,'status'=>$status,'winner'=>$winner]);json_response(['ok'=>true]);
    }

    /* ===== V40 Growth OS ===== */
    if ($path === 'growth/intelligence') {
        require_method('GET'); $id=current_user_id();
        $dna=db()->prepare('SELECT * FROM profile_dna WHERE user_id=? LIMIT 1'); $dna->execute([$id]); $dna=$dna->fetch()?:null;
        $a=db()->prepare('SELECT metric_date,followers,reach,impressions,engagement,saves,shares,profile_visits FROM analytics_daily WHERE user_id=? ORDER BY metric_date DESC LIMIT 14'); $a->execute([$id]); $days=$a->fetchAll();
        $latest=$days[0]??null; $prev=$days[1]??null;
        $eng=$latest?(float)$latest['engagement']:0; $saveRate=($latest&&$latest['reach']>0)?((float)$latest['saves']/(float)$latest['reach']*100):0; $shareRate=($latest&&$latest['reach']>0)?((float)$latest['shares']/(float)$latest['reach']*100):0; $nonFollower=0;
        $growthScore=(int)round(min(100,max(0,45+$eng*8+$saveRate*8+$shareRate*10+($latest?min(12,$latest['followers']/10000):0))));
        $recs=db()->prepare('SELECT id,recommendation_type,title,rationale,score,priority,status,payload,created_at FROM growth_recommendations WHERE user_id=? AND status="new" ORDER BY FIELD(priority,"critical","high","medium","low"),score DESC,created_at DESC LIMIT 8'); $recs->execute([$id]); $items=$recs->fetchAll(); foreach($items as &$r)$r['payload']=json_decode((string)$r['payload'],true)?:[];
        if(!$items){
            $items=[
              ['id'=>0,'recommendation_type'=>'content','title'=>'یک Reel اصیل با Hook قوی بسازید','rationale'=>'برای توصیه‌شدن، روی ارزش واقعی، اصالت و شروع سریع تمرکز کنید؛ این توصیه تضمین اکسپلور نیست.','score'=>91,'priority'=>'high','status'=>'new','payload'=>['action'=>'create_reel'],'created_at'=>date('Y-m-d H:i:s')],
              ['id'=>0,'recommendation_type'=>'experiment','title'=>'دو Hook را آزمایش کنید','rationale'=>'به‌جای حدس، یک متغیر را در دو نسخه آزمایش و نتیجه را ثبت کنید.','score'=>86,'priority'=>'medium','status'=>'new','payload'=>['action'=>'ab_test'],'created_at'=>date('Y-m-d H:i:s')],
              ['id'=>0,'recommendation_type'=>'timing','title'=>'زمان انتشار را از داده خود پیج یاد بگیر','rationale'=>'بهترین زمان باید از عملکرد تاریخی همین حساب استخراج شود، نه یک ساعت ثابت برای همه.','score'=>82,'priority'=>'medium','status'=>'new','payload'=>['action'=>'smart_schedule'],'created_at'=>date('Y-m-d H:i:s')]
            ];
        }
        json_response(['ok'=>true,'growth_score'=>$growthScore,'dna'=>$dna,'latest'=>$latest,'previous'=>$prev,'days'=>$days,'recommendations'=>$items,'principle'=>'Signal-based recommendations, not guaranteed Explore access.']);
    }

    if ($path === 'growth/dna') {
        $id=current_user_id();
        if($_SERVER['REQUEST_METHOD']==='GET'){require_method('GET');$s=db()->prepare('SELECT * FROM profile_dna WHERE user_id=? LIMIT 1');$s->execute([$id]);$r=$s->fetch();if($r){foreach(['pillars','banned_terms','preferred_terms','best_formats','best_topics','best_hooks'] as $k)$r[$k]=json_decode((string)$r[$k],true)?:[];}json_response(['ok'=>true,'dna'=>$r?:null]);}
        require_method('POST'); require_csrf(); $in=input_json();
        $json=function($v){return json_encode(is_array($v)?$v:[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);};
        $st=db()->prepare('INSERT INTO profile_dna(user_id,niche,audience,tone,pillars,banned_terms,preferred_terms,visual_style,cta_style,best_formats,best_topics,best_hooks,confidence) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE niche=VALUES(niche),audience=VALUES(audience),tone=VALUES(tone),pillars=VALUES(pillars),banned_terms=VALUES(banned_terms),preferred_terms=VALUES(preferred_terms),visual_style=VALUES(visual_style),cta_style=VALUES(cta_style),best_formats=VALUES(best_formats),best_topics=VALUES(best_topics),best_hooks=VALUES(best_hooks),confidence=VALUES(confidence),version=version+1');
        $st->execute([$id,trim((string)($in['niche']??'')),trim((string)($in['audience']??'')),trim((string)($in['tone']??'')),$json($in['pillars']??[]),$json($in['banned_terms']??[]),$json($in['preferred_terms']??[]),trim((string)($in['visual_style']??'')),trim((string)($in['cta_style']??'')),$json($in['best_formats']??[]),$json($in['best_topics']??[]),$json($in['best_hooks']??[]),max(0,min(100,(float)($in['confidence']??70)))]);
        audit($id,'growth.dna.save'); json_response(['ok'=>true,'message'=>'Profile DNA ذخیره شد.']);
    }

    if ($path === 'growth/next-action') {
        require_method('GET'); $id=current_user_id();
        $st=db()->prepare('SELECT id,title,rationale,score,priority,payload FROM growth_recommendations WHERE user_id=? AND status="new" ORDER BY score DESC LIMIT 1');$st->execute([$id]);$r=$st->fetch();
        if(!$r)$r=['id'=>0,'title'=>'امروز یک محتوای اصیل و قابل اشتراک بسازید','rationale'=>'اول Hook، سپس ارزش محتوا، سپس CTA؛ بعد نتیجه را اندازه بگیرید و مدل را به‌روزرسانی کنید.','score'=>88,'priority'=>'high','payload'=>['action'=>'create_reel']];
        $r['payload']=json_decode((string)($r['payload']??''),true)?:($r['payload']??[]); json_response(['ok'=>true,'action'=>$r]);
    }

    if ($path === 'content/review') {
        require_method('POST'); require_csrf(); $id=current_user_id(); $in=input_json(); $text=trim((string)($in['text']??'')); $type=strtolower(trim((string)($in['type']??'reel'))); if($text==='')json_response(['ok'=>false,'message'=>'متن یا کپشن برای بررسی لازم است.'],422);
        $len=mb_strlen($text); $hasQuestion=(bool)preg_match('/[?؟]/u',$text); $hasCta=(bool)preg_match('/(نظر|ذخیره|ارسال|share|save|کامنت|follow|دنبال)/iu',$text); $hasHook=$len>=35 && (bool)preg_match('/(^|[.!؟])\s*[^.!؟]{8,70}([!?؟]|$)/u',$text); $originality=max(35,min(100,72+($len%17))); $audience=max(35,min(100,70+($hasQuestion?12:0)+($hasCta?8:0))); $hook=max(30,min(100,58+($hasHook?30:0)+($len>70?7:0))); $share=max(30,min(100,60+($hasQuestion?15:0)+($len>80?10:0))); $save=max(30,min(100,58+($hasCta?20:0))); $rec=max(30,min(100,78-($len>180?12:0)+($hasHook?8:0))); $cta=max(25,min(100,45+($hasCta?45:0))); $overall=round(($hook+$share+$save+$originality+$audience+$rec+$cta)/7,1); $risk=$rec<60?'high':($rec<78?'medium':'low'); $findings=[]; if(!$hasHook)$findings[]='شروع محتوا باید در جمله اول کنجکاوی یا ارزش مشخص ایجاد کند.'; if(!$hasCta)$findings[]='یک CTA طبیعی برای کامنت، ذخیره یا اشتراک‌گذاری اضافه کنید.'; if($len>180)$findings[]='کپشن طولانی است؛ بخش اول را فشرده‌تر و مستقیم‌تر کنید.'; if(!$hasQuestion)$findings[]='در صورت سازگاری با موضوع، سؤال مشخصی برای گفت‌وگو اضافه کنید.';
        $st=db()->prepare('INSERT INTO content_quality_reviews(user_id,content_id,content_type,overall_score,hook_score,share_score,save_score,originality_score,audience_fit_score,recommendation_score,cta_score,risk_level,findings) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');$st->execute([$id,(int)($in['content_id']??0)?:null,$type,$overall,$hook,$share,$save,$originality,$audience,$rec,$cta,$risk,json_encode($findings,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]); audit($id,'content.review',['overall'=>$overall,'risk'=>$risk]); json_response(['ok'=>true,'review'=>['overall'=>$overall,'hook'=>$hook,'share'=>$share,'save'=>$save,'originality'=>$originality,'audience_fit'=>$audience,'recommendation'=>$rec,'cta'=>$cta,'risk'=>$risk,'findings'=>$findings]]);
    }

    if ($path === 'growth/experiments') {
        $id=current_user_id();
        if($_SERVER['REQUEST_METHOD']==='GET'){require_method('GET');$s=db()->prepare('SELECT id,name,hypothesis,variable_key,status,variant_a,variant_b,winner,confidence,started_at,ended_at,created_at FROM growth_experiments WHERE user_id=? ORDER BY id DESC LIMIT 50');$s->execute([$id]);$rows=$s->fetchAll();foreach($rows as &$r){$r['variant_a']=json_decode((string)$r['variant_a'],true)?:[];$r['variant_b']=json_decode((string)$r['variant_b'],true)?:[];}json_response(['ok'=>true,'items'=>$rows]);}
        require_method('POST'); require_csrf(); $in=input_json(); $name=trim((string)($in['name']??'')); if($name==='')json_response(['ok'=>false,'message'=>'نام آزمایش الزامی است.'],422);$st=db()->prepare('INSERT INTO growth_experiments(user_id,name,hypothesis,variable_key,status,variant_a,variant_b,started_at) VALUES(?,?,?,?,"running",?,?,NOW())');$st->execute([$id,$name,trim((string)($in['hypothesis']??'')),trim((string)($in['variable_key']??'hook')),json_encode($in['variant_a']??[],JSON_UNESCAPED_UNICODE),json_encode($in['variant_b']??[],JSON_UNESCAPED_UNICODE)]);json_response(['ok'=>true,'id'=>(int)db()->lastInsertId()],201);
    }

    if ($path === 'v42/instagram/sync') {
        require_method('POST'); require_csrf(); $id=current_user_id(); $in=input_json();
        $s=db()->prepare('SELECT * FROM instagram_accounts WHERE user_id=? AND status="connected" ORDER BY id DESC LIMIT 1');$s->execute([$id]);$acc=$s->fetch();
        if(!$acc)json_response(['ok'=>false,'message'=>'ابتدا یک حساب Instagram متصل کنید.'],404);
        $limit=max(1,min(100,(int)($in['limit']??50)));$run=db()->prepare('INSERT INTO instagram_sync_logs(user_id,instagram_account_id,sync_type,status) VALUES(?,?,"media_insights","running")');$run->execute([$id,$acc['id']]);$runId=(int)db()->lastInsertId();$scanned=0;$synced=0;
        try{$token=ig_decrypt($acc['access_token']);$media=ig_media($acc['ig_user_id'],$token,$limit);foreach(($media['data']??[]) as $m){$scanned++;$mid=(string)($m['id']??'');if(!$mid)continue;$q=db()->prepare('SELECT id FROM content_items WHERE user_id=? AND external_id=? LIMIT 1');$q->execute([$id,$mid]);$cid=$q->fetchColumn();if(!$cid){ $ins=db()->prepare('INSERT INTO content_items(user_id,type,title,caption,status,external_id,published_at) VALUES(?,?,?,?,?,?,?)');$type=($m['media_type']??'IMAGE')==='VIDEO'?'reel':(($m['media_type']??'IMAGE')==='CAROUSEL_ALBUM'?'post':'post');$ins->execute([$id,$type,'Instagram '.date('Y-m-d',strtotime($m['timestamp']??'now')),(string)($m['caption']??''),'published',$mid,date('Y-m-d H:i:s',strtotime($m['timestamp']??'now'))]);$cid=(int)$db->lastInsertId();}$insights=ig_media_insights($mid,$token,(string)($m['media_type']??''));$map=ig_media_insight_map($insights);$reach=(int)($map['reach']??0);$likes=(int)($map['likes']??($m['like_count']??0));$comments=(int)($map['comments']??($m['comments_count']??0));$saves=(int)($map['saved']??0);$shares=(int)($map['shares']??0);$views=(int)($map['views']??0);$total=(int)($map['total_interactions']??($likes+$comments+$saves+$shares));$payload=v42_json(['media'=>$m,'insights'=>$insights]);$db->prepare('INSERT INTO content_performance_snapshots(user_id,content_id,external_media_id,views,reach,likes,comments,saves,shares,total_interactions,raw_payload) VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute([$id,$cid,$mid,$views,$reach,$likes,$comments,$saves,$shares,$total,$payload]);$date=date('Y-m-d',strtotime($m['timestamp']??'now'));$db->prepare('INSERT INTO content_performance(user_id,content_id,external_media_id,metric_date,reach,impressions,likes,comments,saves,shares,source,raw_payload) VALUES(?,?,?,?,?,?,?,?,?,?,"meta",?) ON DUPLICATE KEY UPDATE reach=VALUES(reach),impressions=VALUES(impressions),likes=VALUES(likes),comments=VALUES(comments),saves=VALUES(saves),shares=VALUES(shares),raw_payload=VALUES(raw_payload),updated_at=NOW()')->execute([$id,$cid,$mid,$date,$reach,$views,$likes,$comments,$saves,$shares,$payload]);if($cid){try{learning_apply_for_content($id,(int)$cid);}catch(Throwable $ignore){}}$synced++;}$db->prepare('UPDATE instagram_accounts SET last_sync_at=NOW(),status="connected" WHERE id=?')->execute([$acc['id']]);$db->prepare('UPDATE instagram_sync_logs SET status="completed",items_scanned=?,items_synced=?,completed_at=NOW() WHERE id=?')->execute([$scanned,$synced,$runId]);audit($id,'v42.instagram.sync',['scanned'=>$scanned,'synced'=>$synced]);json_response(['ok'=>true,'run_id'=>$runId,'scanned'=>$scanned,'synced'=>$synced,'source'=>'instagram_graph_api']);}catch(Throwable $e){$db->prepare('UPDATE instagram_sync_logs SET status="failed",items_scanned=?,items_synced=?,error_message=?,completed_at=NOW() WHERE id=?')->execute([$scanned,$synced,$e->getMessage(),$runId]);json_response(['ok'=>false,'message'=>$e->getMessage(),'run_id'=>$runId],502);}
    }

    if ($path === 'v42/predict') {
        require_method('POST'); require_csrf(); $id=current_user_id(); $in=input_json(); $cid=(int)($in['content_id']??0); if(!$cid)json_response(['ok'=>false,'message'=>'content_id الزامی است.'],422); json_response(['ok'=>true,'prediction'=>v42_predict_content($id,$cid)]);
    }

    if ($path === 'v42/readiness') {
        require_method('POST'); require_csrf(); $id=current_user_id(); $in=input_json(); $cid=isset($in['content_id'])?(int)$in['content_id']:null; json_response(['ok'=>true,'readiness'=>v42_readiness($id,$cid,$in)]);
    }

    if ($path === 'v42/smart-slots') {
        require_method('GET'); $id=current_user_id(); $slots=v42_smart_slots($id,(int)($_GET['days']??7)); json_response(['ok'=>true,'items'=>$slots]);
    }

    if ($path === 'v42/attribution') {
        require_method('GET'); $id=current_user_id(); json_response(['ok'=>true]+v42_attribution($id));
    }

    if ($path === 'v42/sync-status') {
        require_method('GET'); $id=current_user_id();$s=db()->prepare('SELECT id,sync_type,status,items_scanned,items_synced,error_message,started_at,completed_at FROM instagram_sync_logs WHERE user_id=? ORDER BY id DESC LIMIT 8');$s->execute([$id]);json_response(['ok'=>true,'items'=>$s->fetchAll()]);
    }

    if ($path === 'v19/schema/status') {
        require_method('GET'); $id=current_user_id();
        $required=['users','brand_profiles','instagram_accounts','content_items','analytics_daily','notifications','jobs','audit_logs','instagram_webhook_events','ai_runs','ai_memories','growth_plans','autopilot_runs','analytics_sync_log','content_ai_reviews','content_ai_runs','content_performance_snapshots','media_sync_runs','content_predictions','content_performance','learning_events','learning_profiles','ab_experiments','ab_variants','mission_sessions','attribution_factor_weights','attribution_events','decision_recommendations','mission_runs','mission_plans','approval_items','scheduler_recommendations','scheduler_slots','publish_queue','publish_attempts','mission_events','system_settings'];
        $rows=[]; foreach($required as $t){$st=db()->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');$st->execute([$t]);$rows[]=['table'=>$t,'present'=>(bool)$st->fetchColumn()];}
        json_response(['ok'=>true,'database'=>db()->query('SELECT DATABASE()')->fetchColumn(),'items'=>$rows]);
    }

    if ($path === 'v19/scheduler/calendar') {
        $id=current_user_id();
        if($_SERVER['REQUEST_METHOD']==='GET'){
            $from=trim((string)($_GET['from']??date('Y-m-d 00:00:00'))); $to=trim((string)($_GET['to']??date('Y-m-d 23:59:59',strtotime('+30 days'))));
            $s=db()->prepare('SELECT s.id,s.content_id,s.mission_id,s.scheduled_at,s.timezone,s.score,s.status,s.source,s.reason,c.title,c.type FROM scheduler_slots s LEFT JOIN content_items c ON c.id=s.content_id WHERE s.user_id=? AND s.scheduled_at BETWEEN ? AND ? ORDER BY s.scheduled_at ASC LIMIT 200');$s->execute([$id,$from,$to]);$items=$s->fetchAll();foreach($items as &$x)$x['reason']=json_decode((string)$x['reason'],true)?:[];json_response(['ok'=>true,'items'=>$items]);
        }
        require_method('POST'); require_csrf(); $in=input_json(); $when=trim((string)($in['scheduled_at']??'')); $score=max(0,min(100,(float)($in['score']??0))); $mission=(int)($in['mission_id']??0); $content=(int)($in['content_id']??0); $tz=trim((string)($in['timezone']??'Asia/Baku'));
        $dt=DateTime::createFromFormat('Y-m-d H:i:s',$when); if(!$dt) json_response(['ok'=>false,'message'=>'scheduled_at نامعتبر است.'],422);
        if($content){$c=db()->prepare('SELECT id FROM content_items WHERE id=? AND user_id=?');$c->execute([$content,$id]);if(!$c->fetch())json_response(['ok'=>false,'message'=>'محتوا متعلق به کاربر نیست.'],403);}
        $reason=json_encode($in['reason']??['source'=>'v19'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $st=db()->prepare('INSERT INTO scheduler_slots(user_id,mission_id,content_id,scheduled_at,timezone,score,status,source,reason) VALUES(?,?,?,?,?,? ,"selected","user",?)');$st->execute([$id,$mission?:null,$content?:null,$when,$tz,$score,$reason]); $slotId=(int)db()->lastInsertId();
        audit($id,'v19.scheduler.slot.create',['slot_id'=>$slotId]); json_response(['ok'=>true,'slot_id'=>$slotId,'scheduled_at'=>$when],201);
    }

    if ($path === 'v19/publish-queue') {
        $id=current_user_id();
        if($_SERVER['REQUEST_METHOD']==='GET'){
            $st=db()->prepare('SELECT q.id,q.content_id,q.mission_id,q.instagram_account_id,q.scheduled_at,q.status,q.attempts,q.max_attempts,q.next_attempt_at,q.published_media_id,q.last_error,q.created_at,c.title,c.type FROM publish_queue q LEFT JOIN content_items c ON c.id=q.content_id WHERE q.user_id=? ORDER BY q.scheduled_at ASC LIMIT 100');$st->execute([$id]);json_response(['ok'=>true,'items'=>$st->fetchAll()]);
        }
        require_method('POST'); require_csrf(); $in=input_json(); $content=(int)($in['content_id']??0); $mission=(int)($in['mission_id']??0); $when=trim((string)($in['scheduled_at']??'')); if(!$content||!$when)json_response(['ok'=>false,'message'=>'content_id و scheduled_at الزامی است.'],422);
        $c=db()->prepare('SELECT id,status FROM content_items WHERE id=? AND user_id=?');$c->execute([$content,$id]);$row=$c->fetch();if(!$row)json_response(['ok'=>false,'message'=>'محتوا پیدا نشد.'],404);
        $a=db()->prepare('SELECT id FROM instagram_accounts WHERE user_id=? AND status="connected" ORDER BY id DESC LIMIT 1');$a->execute([$id]);$ig=$a->fetch();
        $key='content-'.$content.'-'.$when; $st=db()->prepare('INSERT INTO publish_queue(user_id,content_id,mission_id,instagram_account_id,scheduled_at,status,idempotency_key,next_attempt_at) VALUES(?,?,?,?,?,"queued",?,?) ON DUPLICATE KEY UPDATE scheduled_at=VALUES(scheduled_at),status=IF(status IN ("failed","cancelled"),"queued",status),next_attempt_at=VALUES(next_attempt_at),updated_at=NOW()');$st->execute([$id,$content,$mission?:null,$ig['id']??null,$when,$key,$when]);
        db()->prepare('UPDATE content_items SET status="queued",scheduled_at=? WHERE id=? AND user_id=?')->execute([$when,$content,$id]); audit($id,'v19.publish.queue',['content_id'=>$content,'scheduled_at'=>$when]); json_response(['ok'=>true,'idempotency_key'=>$key]);
    }

    if ($path === 'v19/publish/cancel') {
        require_method('POST'); require_csrf(); $id=current_user_id(); $in=input_json(); $qid=(int)($in['queue_id']??0); if(!$qid)json_response(['ok'=>false,'message'=>'queue_id الزامی است.'],422);
        $st=db()->prepare('UPDATE publish_queue SET status="cancelled",updated_at=NOW() WHERE id=? AND user_id=? AND status IN ("queued","retrying")');$st->execute([$qid,$id]);if(!$st->rowCount())json_response(['ok'=>false,'message'=>'آیتم قابل لغو نیست.'],409);audit($id,'v19.publish.cancel',['queue_id'=>$qid]);json_response(['ok'=>true]);
    }

    if ($path === 'v19/publish/retry') {
        require_method('POST'); require_csrf(); $id=current_user_id(); $in=input_json(); $qid=(int)($in['queue_id']??0); if(!$qid)json_response(['ok'=>false,'message'=>'queue_id الزامی است.'],422);
        $st=db()->prepare('UPDATE publish_queue SET status="queued",next_attempt_at=NOW(),last_error=NULL,updated_at=NOW() WHERE id=? AND user_id=? AND status IN ("failed","retrying")');$st->execute([$qid,$id]);if(!$st->rowCount())json_response(['ok'=>false,'message'=>'آیتم قابل Retry نیست.'],409);audit($id,'v19.publish.retry',['queue_id'=>$qid]);json_response(['ok'=>true]);
    }

    if ($path === 'v19/mission/events') {
        require_method('GET'); $id=current_user_id(); $mid=(int)($_GET['mission_id']??0); if(!$mid)json_response(['ok'=>false,'message'=>'mission_id الزامی است.'],422);
        $st=db()->prepare('SELECT event_type,from_status,to_status,message,payload,created_at FROM mission_events WHERE user_id=? AND mission_id=? ORDER BY id DESC LIMIT 100');$st->execute([$id,$mid]);$items=$st->fetchAll();foreach($items as &$x)$x['payload']=json_decode((string)$x['payload'],true)?:[];json_response(['ok'=>true,'items'=>$items]);
    }

    json_response(['ok'=>false,'message'=>'Endpoint پیدا نشد.','path'=>$path],404);
} catch (Throwable $e) {
    error_log('[InstaPilot] '.$e->getMessage());
    json_response(['ok'=>false,'message'=>'خطای داخلی سرور.'],500);
}
