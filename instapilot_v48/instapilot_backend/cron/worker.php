<?php
declare(strict_types=1);
require __DIR__.'/../lib/bootstrap.php';
require __DIR__.'/../lib/learning.php';
require __DIR__.'/../lib/attribution.php';

global $config;
$lockFile=sys_get_temp_dir().'/instapilot-worker.lock';
$fp=fopen($lockFile,'c'); if(!$fp || !flock($fp,LOCK_EX|LOCK_NB)) exit("worker already running\n");

// Refresh tokens that are within the configured expiry window.
$days=(int)($config['instagram']['refresh_before_days']??7);
try{
  $rows=db()->query("SELECT * FROM instagram_accounts WHERE status='connected' AND token_expires_at IS NOT NULL AND token_expires_at <= DATE_ADD(NOW(), INTERVAL ".$days." DAY)")->fetchAll();
  foreach($rows as $acc){
    try{
      $token=ig_decrypt($acc['access_token']); $r=ig_refresh_token($token);
      if($r['status']>=200&&$r['status']<300&& !empty($r['data']['access_token'])){
        $expires=(int)($r['data']['expires_in']??5184000);
        db()->prepare('UPDATE instagram_accounts SET access_token=?,token_expires_at=?,status="connected" WHERE id=?')->execute([ig_encrypt($r['data']['access_token']),date('Y-m-d H:i:s',time()+$expires),$acc['id']]);
        notify((int)$acc['user_id'],'security','توکن Instagram تمدید شد','تمدید خودکار اتصال Instagram انجام شد.');
      }
    }catch(Throwable $e){ db()->prepare('UPDATE instagram_accounts SET status="error" WHERE id=?')->execute([$acc['id']]); audit((int)$acc['user_id'],'instagram.auto_refresh_error',['message'=>$e->getMessage()]); }
  }
}catch(Throwable $e){error_log('[InstaPilot worker] '.$e->getMessage());}

// Periodic real Instagram media-performance sync. Lifetime media insights are snapshotted,
// then the latest verified values feed the V11 learning engine. We throttle to avoid unnecessary API calls.
try{
  $accounts=db()->query("SELECT a.* FROM instagram_accounts a LEFT JOIN (SELECT instagram_account_id,MAX(created_at) last_run FROM media_sync_runs GROUP BY instagram_account_id) r ON r.instagram_account_id=a.id WHERE a.status='connected' AND (r.last_run IS NULL OR r.last_run < DATE_SUB(NOW(), INTERVAL 6 HOUR)) ORDER BY COALESCE(r.last_run,'1970-01-01') ASC LIMIT 5")->fetchAll();
  foreach($accounts as $acc){
    $uid=(int)$acc['user_id']; $limit=max(1,min(100,(int)($config['instagram']['media_sync_limit']??25))); $delay=max(0,min(2000,(int)($config['instagram']['media_sync_delay_ms']??250)));
    $run=db()->prepare('INSERT INTO media_sync_runs(user_id,instagram_account_id,status) VALUES(?,? ,"running")');$run->execute([$uid,$acc['id']]);$runId=(int)db()->lastInsertId();$scanned=0;$synced=0;$skipped=0;$failed=0;$errors=[];
    try{
      $token=ig_decrypt($acc['access_token']);$media=ig_media($acc['ig_user_id'],$token,$limit);
      foreach(($media['data']??[]) as $m){$scanned++;$mediaId=trim((string)($m['id']??''));if($mediaId===''){$skipped++;continue;}
        $q=db()->prepare('SELECT id FROM content_items WHERE user_id=? AND external_id=? LIMIT 1');$q->execute([$uid,$mediaId]);$content=$q->fetch();if(!$content){$skipped++;continue;}
        try{$ins=ig_media_insights($mediaId,$token,(string)($m['media_type']??''));$map=ig_media_insight_map($ins);$reach=(int)round($map['reach']??0);if($reach<=0){$skipped++;continue;}$views=(int)round($map['views']??0);$likes=(int)round($map['likes']??($m['like_count']??0));$comments=(int)round($map['comments']??($m['comments_count']??0));$saves=(int)round($map['saved']??0);$shares=(int)round($map['shares']??0);$total=(int)round($map['total_interactions']??($likes+$comments+$saves+$shares));$payload=json_encode(['media'=>$m,'insights'=>$ins],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
          db()->prepare('INSERT INTO content_performance_snapshots(user_id,content_id,external_media_id,views,reach,likes,comments,saves,shares,total_interactions,raw_payload) VALUES(?,?,?,?,?,?,?,?,?,?,?)')->execute([$uid,$content['id'],$mediaId,$views,$reach,$likes,$comments,$saves,$shares,$total,$payload]);$date=date('Y-m-d');db()->prepare('INSERT INTO content_performance(user_id,content_id,external_media_id,metric_date,reach,impressions,likes,comments,saves,shares,source,raw_payload) VALUES(?,?,?,?,?,?,?,?,?,?,"meta",?) ON DUPLICATE KEY UPDATE external_media_id=VALUES(external_media_id),reach=VALUES(reach),impressions=VALUES(impressions),likes=VALUES(likes),comments=VALUES(comments),saves=VALUES(saves),shares=VALUES(shares),source="meta",raw_payload=VALUES(raw_payload),updated_at=NOW()')->execute([$uid,$content['id'],$mediaId,$date,$reach,$views,$likes,$comments,$saves,$shares,$payload]);learning_apply_for_content($uid,(int)$content['id']);$synced++;if($delay>0)usleep($delay*1000);
        }catch(Throwable $e){$failed++;if(count($errors)<5)$errors[]=['media_id'=>$mediaId,'message'=>$e->getMessage()];}
      }
      $status=$failed>0?($synced>0?'partial':'failed'):'completed';db()->prepare('UPDATE media_sync_runs SET scanned=?,synced=?,skipped=?,failed=?,status=?,error_message=?,completed_at=NOW() WHERE id=?')->execute([$scanned,$synced,$skipped,$failed,$status,$errors?json_encode($errors,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null,$runId]);if($synced>0)notify($uid,'analytics','یادگیری عملکرد به‌روزرسانی شد',"{$synced} محتوای منتشرشده با داده واقعی Instagram بررسی شد.");
    }catch(Throwable $e){db()->prepare('UPDATE media_sync_runs SET scanned=?,synced=?,skipped=?,failed=?,status="failed",error_message=?,completed_at=NOW() WHERE id=?')->execute([$scanned,$synced,$skipped,$failed,$e->getMessage(),$runId]);audit($uid,'instagram.media_performance_sync_error',['message'=>$e->getMessage()]);}
  }
}catch(Throwable $e){error_log('[InstaPilot media sync] '.$e->getMessage());}

// Rebuild attribution for users with recent verified Meta performance.
try{
  $uids=db()->query("SELECT DISTINCT user_id FROM content_performance WHERE source='meta' AND updated_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) LIMIT 10")->fetchAll(PDO::FETCH_COLUMN);
  foreach($uids as $uid){ try{ attribution_build((int)$uid); }catch(Throwable $e){ audit((int)$uid,'attribution.worker_error',['message'=>$e->getMessage()]); } }
}catch(Throwable $e){ error_log('[InstaPilot attribution] '.$e->getMessage()); }

// V19 real publish queue: idempotent, retryable and backed by publish_attempts.
for($i=0;$i<5;$i++){
  $db=db(); $db->beginTransaction(); $qrow=null;
  try{
    $q=$db->prepare("SELECT * FROM publish_queue WHERE status IN ('queued','retrying') AND scheduled_at<=NOW() AND (next_attempt_at IS NULL OR next_attempt_at<=NOW()) AND attempts<max_attempts ORDER BY scheduled_at ASC,id ASC LIMIT 1 FOR UPDATE");$q->execute();$qrow=$q->fetch();
    if(!$qrow){$db->commit();break;}
    $attempt=(int)$qrow['attempts']+1;
    $db->prepare("UPDATE publish_queue SET status='processing',attempts=?,locked_at=NOW(),updated_at=NOW() WHERE id=?")->execute([$attempt,$qrow['id']]);
    $db->prepare('INSERT INTO publish_attempts(publish_queue_id,user_id,attempt_no,stage,status) VALUES(?,?,?,"instagram_publish","started")')->execute([$qrow['id'],$qrow['user_id'],$attempt]);
    $db->commit();
    try{
      $result=publish_content_real((int)$qrow['user_id'],(int)$qrow['content_id'],(int)$qrow['id']);
      if(!empty($qrow['mission_id'])){
        db()->prepare('UPDATE mission_sessions SET status="published",current_step=6,published_media_id=? WHERE id=? AND user_id=?')->execute([$result['media_id']??null,$qrow['mission_id'],$qrow['user_id']]);
        db()->prepare('INSERT INTO mission_events(user_id,mission_id,event_type,from_status,to_status,message,payload) VALUES(?,?,?,?,?,?,?)')->execute([$qrow['user_id'],$qrow['mission_id'],'publish','scheduled','published','انتشار واقعی با موفقیت انجام شد.',json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
      }
    }catch(Throwable $e){
      $delay=min(3600,300*$attempt); $next=date('Y-m-d H:i:s',time()+$delay); $final=$attempt>=(int)$qrow['max_attempts'];
      db()->prepare('UPDATE publish_queue SET status=?,next_attempt_at=?,last_error=?,updated_at=NOW() WHERE id=? AND user_id=?')->execute([$final?'failed':'retrying',$final?null:$next,$e->getMessage(),$qrow['id'],$qrow['user_id']]);
      db()->prepare('UPDATE publish_attempts SET status="failed",provider_message=?,finished_at=NOW() WHERE publish_queue_id=? AND attempt_no=?')->execute([$e->getMessage(),$qrow['id'],$attempt]);
      audit((int)$qrow['user_id'],'v19.publish.failed',['queue_id'=>$qrow['id'],'attempt'=>$attempt,'final'=>$final,'message'=>$e->getMessage()]);
      if($final) notify((int)$qrow['user_id'],'publish','انتشار ناموفق بود','تعداد تلاش‌های مجاز تمام شد؛ می‌توانید از Operations Center دوباره Retry کنید.');
    }
  }catch(Throwable $e){if($db->inTransaction())$db->rollBack();error_log('[InstaPilot V19 publish queue] '.$e->getMessage());break;}
}

// Process a small queue slice. Publishing jobs are delegated to the API-safe publishing service.
for($i=0;$i<10;$i++){
  $db=db(); $db->beginTransaction();
  try{
    $q=$db->prepare("SELECT * FROM jobs WHERE status='queued' AND available_at<=NOW() ORDER BY id ASC LIMIT 1 FOR UPDATE");$q->execute();$job=$q->fetch();
    if(!$job){$db->commit();break;}
    $db->prepare("UPDATE jobs SET status='processing',attempts=attempts+1,locked_at=NOW() WHERE id=?")->execute([$job['id']]);$db->commit();
    $payload=json_decode($job['payload'],true)?:[];
    if($job['job_type']==='ai_command'){
      $command=trim((string)($payload['command']??''));
      if(preg_match('/(رشد|تعامل|engagement|growth|کمپین|campaign)/iu',$command)){
        $db->prepare("INSERT INTO jobs(user_id,job_type,payload,available_at) VALUES(?,?,?,NOW())")->execute([(int)$job['user_id'],'ai_growth_plan',json_encode(['goal'=>$command,'days'=>30],JSON_UNESCAPED_UNICODE)]);
      }
      notify((int)$job['user_id'],'ai','دستور AI پردازش شد','فرمان شما توسط Orchestrator دریافت و برای مرحله بعدی ثبت شد.');
    } elseif($job['job_type']==='ai_growth_plan'){
      $goal=trim((string)($payload['goal']??'افزایش تعامل'));$days=max(7,min(90,(int)($payload['days']??30)));
      autopilot_execute((int)$job['user_id'],$goal,$days);
    } elseif($job['job_type']==='autopilot_run'){
      $goal=trim((string)($payload['goal']??'افزایش تعامل'));$days=max(7,min(90,(int)($payload['days']??30)));
      autopilot_execute((int)$job['user_id'],$goal,$days);
    } elseif($job['job_type']==='v17_content_draft'){
      $goal=trim((string)($payload['goal']??'ایجاد محتوای پربازده'));$type=in_array(($payload['type']??'reel'),['reel','post','story'],true)?$payload['type']:'reel';$mid=(int)($payload['mission_id']??0);$scheduledAt=trim((string)($payload['scheduled_at']??''));
      $r=autonomous_content_execute((int)$job['user_id'],$goal,$type); $cid=(int)($r['content_id']??0);
      if($cid){ if($scheduledAt) db()->prepare('UPDATE content_items SET scheduled_at=? WHERE id=? AND user_id=?')->execute([$scheduledAt,$cid,(int)$job['user_id']]);
        db()->prepare('INSERT INTO approval_items(user_id,mission_id,content_id,item_type,title,payload,status) VALUES(?,?,?,"content",?, ?,"pending")')->execute([(int)$job['user_id'],$mid,$cid,(string)($r['content']['title']??'پیش‌نویس AI'),json_encode(['score'=>$r['content']['score']??0,'best_time'=>$r['content']['best_time']??null,'scheduled_at'=>$scheduledAt],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        if($mid) db()->prepare('UPDATE mission_sessions SET status="awaiting_approval",current_step=4,content_id=? WHERE id=? AND user_id=?')->execute([$cid,$mid,(int)$job['user_id']]);
      }
      notify((int)$job['user_id'],'ai','Draft Mission آماده شد','یک پیش‌نویس برای بررسی و تأیید شما آماده شده است.');
    } elseif($job['job_type']==='autonomous_content'){
      $goal=trim((string)($payload['goal']??'ایجاد محتوای پربازده'));$type=in_array(($payload['type']??'reel'),['reel','post','story'],true)?$payload['type']:'reel';
      autonomous_content_execute((int)$job['user_id'],$goal,$type);
    } elseif($job['job_type']==='publish_content'){
      $cid=(int)($payload['content_id']??0); $mid=(int)($payload['mission_id']??0); if(!$cid) throw new RuntimeException('content_id برای publish_content الزامی است.');
      $when=trim((string)($payload['scheduled_at']??$job['available_at']??date('Y-m-d H:i:s'))); $key='content-'.$cid.'-'.$when;
      db()->prepare('INSERT INTO publish_queue(user_id,content_id,mission_id,instagram_account_id,scheduled_at,status,idempotency_key,next_attempt_at) SELECT ?,?,?,id,?,"queued",?,? FROM instagram_accounts WHERE user_id=? AND status="connected" ORDER BY id DESC LIMIT 1 ON DUPLICATE KEY UPDATE updated_at=NOW()')->execute([(int)$job['user_id'],$cid,$mid?:null,$when,$key,$when,(int)$job['user_id']]);
    }
    $db->prepare("UPDATE jobs SET status='done',updated_at=NOW() WHERE id=?")->execute([$job['id']]);
  }catch(Throwable $e){
    if($db->inTransaction())$db->rollBack();
    try{$db->prepare("UPDATE jobs SET status=IF(attempts<3,'queued','failed'),error_message=?,available_at=DATE_ADD(NOW(),INTERVAL 5 MINUTE) WHERE id=?")->execute([$e->getMessage(),$job['id']??0]);}catch(Throwable $ignore){}
  }
}
flock($fp,LOCK_UN);fclose($fp);
