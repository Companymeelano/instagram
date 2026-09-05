<?php
declare(strict_types=1);

function v43_clamp(float $v,float $a=0,float $b=100):float{return round(max($a,min($b,$v)),1);}
function v43_json(array $v):string{return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}
function v43_user_setting(int $uid,string $key,string $default=''):string{
    $s=db()->prepare('SELECT setting_value FROM system_settings WHERE user_id=? AND setting_group="autopilot" AND setting_key=? LIMIT 1');$s->execute([$uid,$key]);$v=$s->fetchColumn();return $v===false?$default:(string)$v;
}
function v43_set_setting(int $uid,string $key,string $value):void{
    $s=db()->prepare('INSERT INTO system_settings(user_id,setting_group,setting_key,setting_value,is_secret) VALUES(?,?,?,?,0) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)');$s->execute([$uid,'autopilot',$key,$value]);
}
function v43_metrics(int $uid):array{
    $db=db();
    $q=$db->prepare('SELECT COUNT(*) n, AVG((likes+comments+saves+shares)/NULLIF(reach,0)*100) eng, AVG(saves/NULLIF(reach,0)*100) save_rate, AVG(shares/NULLIF(reach,0)*100) share_rate, AVG(reach) reach FROM content_performance WHERE user_id=? AND source="meta" AND reach>0');$q->execute([$uid]);$r=$q->fetch()?:[];
    $a=$db->prepare('SELECT COUNT(*) n, AVG(engagement) eng, AVG(reach) reach FROM analytics_daily WHERE user_id=? AND metric_date>=DATE_SUB(CURDATE(),INTERVAL 30 DAY)');$a->execute([$uid]);$x=$a->fetch()?:[];
    return ['content_samples'=>(int)($r['n']??0),'engagement'=>round((float)($r['eng']??0),3),'save_rate'=>round((float)($r['save_rate']??0),3),'share_rate'=>round((float)($r['share_rate']??0),3),'reach'=>round((float)($r['reach']??0),1),'daily_samples'=>(int)($x['n']??0),'daily_engagement'=>round((float)($x['eng']??0),3),'daily_reach'=>round((float)($x['reach']??0),1)];
}
function v43_brain(int $uid):array{
    $db=db();$m=v43_metrics($uid);
    $dataScore=v43_clamp(35+min(35,$m['content_samples']*4)+min(20,$m['daily_samples']*2));
    $forecast=50;try{$q=$db->prepare('SELECT score FROM growth_daily_snapshots WHERE user_id=? ORDER BY snapshot_date DESC LIMIT 2');$q->execute([$uid]);$rows=$q->fetchAll(PDO::FETCH_COLUMN);if(count($rows)>=2)$forecast=v43_clamp(50+((float)$rows[0]-(float)$rows[1])*2);}catch(Throwable $e){}
    $quality=50;try{$q=$db->prepare('SELECT AVG(overall_score) FROM content_quality_reviews WHERE user_id=?');$q->execute([$uid]);$quality=v43_clamp((float)($q->fetchColumn()?:50));}catch(Throwable $e){}
    $signals=[];
    if($m['content_samples']<5)$signals[]=['key'=>'data','title'=>'بستن شکاف داده','priority'=>95,'why'=>'نمونه Performance واقعی هنوز کم است؛ Sync را اجرا کن تا تصمیم‌ها قابل اتکاتر شوند.'];
    if($quality<75)$signals[]=['key'=>'quality','title'=>'ارتقای Quality Gate','priority'=>88,'why'=>'امتیاز کیفیت محتوای اخیر پایین‌تر از آستانه مطلوب است.'];
    if($m['save_rate']>0 && $m['share_rate']<$m['save_rate'])$signals[]=['key'=>'share','title'=>'افزایش Share Intent','priority'=>78,'why'=>'ذخیره‌سازی از اشتراک‌گذاری قوی‌تر است؛ محتوا را برای ارسال به دیگران طراحی کن.'];
    $signals[]=['key'=>'experiment','title'=>'یک آزمایش تک‌متغیره اجرا کن','priority'=>72,'why'=>'برای یادگیری سریع، فقط Hook یا Timing را تغییر بده و نتیجه را با Baseline مقایسه کن.'];
    $signals[]=['key'=>'calendar','title'=>'بهینه‌سازی زمان انتشار','priority'=>68,'why'=>'Smart Calendar باید با داده‌های جدید دوباره محاسبه شود.'];
    usort($signals,fn($a,$b)=>$b['priority']<=>$a['priority']);
    $mode=v43_user_setting($uid,'mode','assist');
    $approval=v43_user_setting($uid,'publish_approval','required')==='required';
    $score=v43_clamp($dataScore*.35+$quality*.25+$forecast*.2+(count($signals)>0?65:50)*.2);
    return ['score'=>$score,'mode'=>$mode,'publish_approval_required'=>$approval,'confidence'=>v43_clamp(35+$dataScore*.45+$quality*.1),'metrics'=>$m,'next_action'=>$signals[0],'signals'=>array_slice($signals,0,5),'guardrails'=>['no_scraping'=>true,'official_api_only'=>true,'publish_requires_approval'=>$approval,'no_algorithm_guarantee'=>true]];
}
function v43_create_mission(int $uid,string $type,?string $title=null,?array $payload=null):array{
    $brain=v43_brain($uid);$titles=['analyze'=>'تحلیل وضعیت پیج','content'=>'ساخت محتوای بعدی','optimize'=>'بهینه‌سازی انتشار','experiment'=>'اجرای آزمایش رشد','sync'=>'همگام‌سازی Instagram'];$t=$title?:($titles[$type]??'مأموریت رشد');
    $db=db();$s=$db->prepare('INSERT INTO growth_missions(user_id,mission_type,title,status,priority,payload,decision_snapshot) VALUES(?,?,?,?,?,?,?)');$s->execute([$uid,$type,$t,'planned',(int)($brain['next_action']['priority']??70),v43_json($payload?:[]),v43_json($brain)]);$id=(int)$db->lastInsertId();return ['id'=>$id,'type'=>$type,'title'=>$t,'status'=>'planned','priority'=>(int)($brain['next_action']['priority']??70),'decision'=>$brain['next_action']];
}
function v43_missions(int $uid):array{$s=db()->prepare('SELECT id,mission_type,title,status,priority,created_at,approved_at,completed_at,result FROM growth_missions WHERE user_id=? ORDER BY priority DESC,created_at DESC LIMIT 20');$s->execute([$uid]);$out=[];foreach($s->fetchAll() as $r){$r['result']=$r['result']?json_decode($r['result'],true):null;$out[]=$r;}return $out;}
function v43_approve(int $uid,int $id):array{$db=db();$s=$db->prepare('SELECT * FROM growth_missions WHERE id=? AND user_id=? LIMIT 1');$s->execute([$id,$uid]);$m=$s->fetch();if(!$m)throw new RuntimeException('مأموریت پیدا نشد.');if($m['status']!=='planned')throw new RuntimeException('این مأموریت در وضعیت قابل تأیید نیست.');$u=$db->prepare('UPDATE growth_missions SET status="approved",approved_at=NOW() WHERE id=? AND user_id=?');$u->execute([$id,$uid]);$j=$db->prepare('INSERT INTO jobs(user_id,job_type,payload,status) VALUES(?,?,?,"queued")');$j->execute([$uid,'growth.mission',v43_json(['mission_id'=>$id])]);return ['id'=>$id,'status'=>'approved','job_id'=>(int)$db->lastInsertId()];}
function v43_run(int $uid,int $id):array{$db=db();$s=$db->prepare('SELECT * FROM growth_missions WHERE id=? AND user_id=? LIMIT 1');$s->execute([$id,$uid]);$m=$s->fetch();if(!$m)throw new RuntimeException('مأموریت پیدا نشد.');if(!in_array($m['status'],['approved','planned'],true))throw new RuntimeException('وضعیت مأموریت اجازه اجرا نمی‌دهد.');$brain=v43_brain($uid);$result=['executed'=>true,'action'=>$brain['next_action'],'mode'=>$brain['mode'],'note'=>'این اجرا تصمیم‌گیری را انجام می‌دهد؛ انتشار خارجی فقط با اتصال رسمی و در صورت فعال‌بودن مجوز انتشار انجام می‌شود.'];$u=$db->prepare('UPDATE growth_missions SET status="completed",started_at=COALESCE(started_at,NOW()),completed_at=NOW(),result=? WHERE id=? AND user_id=?');$u->execute([v43_json($result),$id,$uid]);$e=$db->prepare('INSERT INTO learning_events(user_id,content_id,event_type,metric_key,confidence,evidence) VALUES(?,?,"brain_decision","growth_action",0,?,?)');$contentId=(int)($db->query('SELECT id FROM content_items WHERE user_id='.(int)$uid.' ORDER BY id DESC LIMIT 1')->fetchColumn()?:0);if($contentId)$e->execute([$uid,$contentId,v43_json($result)]);return ['id'=>$id,'status'=>'completed','result'=>$result];}
