<?php
declare(strict_types=1);

function v42_safe_score(float $v): float { return round(max(0,min(100,$v)),1); }
function v42_json(array $v): string { return json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }

function v42_predict_content(int $userId, int $contentId): array {
    $db=db();
    $c=$db->prepare('SELECT id,type,caption,title FROM content_items WHERE id=? AND user_id=?'); $c->execute([$contentId,$userId]); $content=$c->fetch();
    if(!$content) throw new RuntimeException('محتوا پیدا نشد.');
    $hist=$db->prepare('SELECT c.type, AVG((p.likes+p.comments+p.saves+p.shares)/NULLIF(p.reach,0)*100) eng, COUNT(*) n FROM content_performance p JOIN content_items c ON c.id=p.content_id WHERE p.user_id=? AND p.source="meta" AND p.reach>0 GROUP BY c.type'); $hist->execute([$userId]);
    $byType=[]; foreach($hist->fetchAll() as $r)$byType[$r['type']]=['eng'=>(float)$r['eng'],'n'=>(int)$r['n']];
    $all=$db->prepare('SELECT AVG((likes+comments+saves+shares)/NULLIF(reach,0)*100), COUNT(*) FROM content_performance WHERE user_id=? AND source="meta" AND reach>0');$all->execute([$userId]);$row=$all->fetch(PDO::FETCH_NUM);$baseline=(float)($row[0]??0);$samples=(int)($row[1]??0);
    $type=$content['type'];$typeBase=$byType[$type]['eng']??$baseline;$quality=0;
    $r=$db->prepare('SELECT overall_score,hook_score,share_score,save_score,originality_score,audience_fit_score,recommendation_score,cta_score FROM content_quality_reviews WHERE user_id=? AND content_id=? ORDER BY id DESC LIMIT 1');$r->execute([$userId,$contentId]);$review=$r->fetch();
    if($review)$quality=(float)$review['overall_score']; else $quality=60+min(25,strlen((string)$content['caption'])/12);
    $pred=v42_safe_score(($typeBase*0.55)+($quality*0.45));
    $confidence=v42_safe_score(45+min(45,$samples*3)+($review?8:0));
    $basis=['baseline_engagement_rate'=>round($baseline,3),'format_engagement_rate'=>round($typeBase,3),'quality_score'=>round($quality,1),'samples'=>$samples,'source'=>$samples?'first_party_performance':'demo_baseline'];
    $st=$db->prepare('INSERT INTO content_predictions(user_id,content_id,metric_key,predicted_value,confidence,basis) VALUES(?,?,?,?,?,?)');$st->execute([$userId,$contentId,'engagement_rate',$pred,$confidence,v42_json($basis)]);
    return ['content_id'=>$contentId,'metric_key'=>'engagement_rate','predicted_value'=>$pred,'confidence'=>$confidence,'basis'=>$basis];
}

function v42_readiness(int $userId, ?int $contentId, array $input=[]): array {
    $db=db(); $text=trim((string)($input['text']??'')); $type=(string)($input['type']??'reel');
    if($contentId){$s=$db->prepare('SELECT caption,type FROM content_items WHERE id=? AND user_id=?');$s->execute([$contentId,$userId]);$c=$s->fetch();if(!$c)throw new RuntimeException('محتوا پیدا نشد.');$text=(string)($c['caption']??$text);$type=(string)($c['type']??$type);}
    $quality=$input['quality_score']??null;
    if($quality===null && $contentId){$s=$db->prepare('SELECT overall_score FROM content_quality_reviews WHERE user_id=? AND content_id=? ORDER BY id DESC LIMIT 1');$s->execute([$userId,$contentId]);$quality=$s->fetchColumn();}
    $quality=$quality!==false&&$quality!==null?(float)$quality:60;
    $originality=100; $reasons=[];
    if(mb_strlen($text)<25){$quality-=12;$reasons[]='کپشن بسیار کوتاه است؛ ارزش افزوده و زمینه بیشتری اضافه کنید.';}
    if(preg_match('/(?:repost|re-upload|کپی|بازنشر)/iu',$text)){$originality-=25;$reasons[]='متن نشانه‌ای از بازنشر دارد؛ اصالت محتوا را بررسی کنید.';}
    if(preg_match('/(?:follow.?for.?follow|f4f|لایک کن لایک می کنم|فالو کن فالو)/iu',$text)){$quality-=18;$reasons[]='CTA تعامل اجباری می‌تواند کیفیت توزیع را کاهش دهد.';}
    $safety=100; if(preg_match('/(?:قمار|کلاهبرداری|scam|تضمینی 100 درصد)/iu',$text)){$safety=45;$reasons[]='عبارت پرریسک شناسایی شد؛ قبل از انتشار بررسی دستی لازم است.';}
    $quality=v42_safe_score($quality);$originality=v42_safe_score($originality);$safety=v42_safe_score($safety);$score=v42_safe_score($quality*.4+$originality*.3+$safety*.3);
    $status=$safety<60?'blocked':($score>=78?'ready':'review'); if(!$reasons)$reasons[]='سیگنال بحرانی شناسایی نشد؛ نتیجه واقعی پس از انتشار باید اندازه‌گیری شود.';
    if($contentId){$st=$db->prepare('INSERT INTO recommendation_readiness(user_id,content_id,readiness_score,status,originality_score,quality_score,safety_score,reasons) VALUES(?,?,?,?,?,?,?,?)');$st->execute([$userId,$contentId,$score,$status,$originality,$quality,$safety,v42_json($reasons)]);}
    return ['score'=>$score,'status'=>$status,'originality'=>$originality,'quality'=>$quality,'safety'=>$safety,'reasons'=>$reasons,'type'=>$type,'source'=>$contentId?'content':'preview'];
}

function v42_smart_slots(int $userId,int $days=7): array {
    $db=db();$days=max(1,min(30,$days));$s=$db->prepare('SELECT HOUR(created_at) h, AVG((likes+comments+saves+shares)/NULLIF(reach,0)*100) eng, COUNT(*) n FROM content_performance WHERE user_id=? AND source="meta" AND reach>0 AND created_at>=DATE_SUB(NOW(),INTERVAL 90 DAY) GROUP BY HOUR(created_at) HAVING n>=1 ORDER BY eng DESC LIMIT 8');$s->execute([$userId]);$hours=$s->fetchAll();
    if(!$hours)$hours=[['h'=>12,'eng'=>0,'n'=>0],['h'=>19,'eng'=>0,'n'=>0],['h'=>21,'eng'=>0,'n'=>0]];
    $max=max(array_map(fn($x)=>(float)$x['eng'],$hours));$slots=[];
    for($d=0;$d<$days;$d++){foreach($hours as $x){$hour=(int)$x['h'];$dt=new DateTime('today');$dt->modify("+{$d} day");$dt->setTime($hour,0);if($dt<=new DateTime())continue;$score=$max>0?v42_safe_score(((float)$x['eng']/$max)*100):60;$conf=v42_safe_score(45+min(45,(int)$x['n']*5));$reason=['historical_engagement_rate'=>round((float)$x['eng'],3),'samples'=>(int)$x['n'],'hour'=>$hour];$slots[]=['slot_start'=>$dt->format('Y-m-d H:i:s'),'slot_end'=>$dt->modify('+45 minutes')->format('Y-m-d H:i:s'),'score'=>$score,'confidence'=>$conf,'reason'=>$reason];}}
    usort($slots,fn($a,$b)=>$b['score']<=>$a['score']);$slots=array_slice($slots,0,12);
    foreach($slots as $x){$st=$db->prepare('INSERT INTO smart_publish_slots(user_id,slot_start,slot_end,score,confidence,reason) VALUES(?,?,?,?,?,?)');$st->execute([$userId,$x['slot_start'],$x['slot_end'],$x['score'],$x['confidence'],v42_json($x['reason'])]);}
    return $slots;
}

function v42_attribution(int $userId): array {
    $db=db();$s=$db->prepare('SELECT c.id,c.type,c.title,p.reach,p.likes,p.comments,p.saves,p.shares,r.overall_score,r.hook_score,r.originality_score,r.recommendation_score FROM content_performance p JOIN content_items c ON c.id=p.content_id LEFT JOIN content_quality_reviews r ON r.content_id=c.id WHERE p.user_id=? AND p.source="meta" AND p.reach>0 ORDER BY p.metric_date DESC LIMIT 50');$s->execute([$userId]);$rows=$s->fetchAll();$out=[];
    foreach($rows as $r){$eng=((float)$r['likes']+(float)$r['comments']+(float)$r['saves']+(float)$r['shares'])/max(1,(float)$r['reach'])*100;$factors=['format'=>$r['type'],'quality'=>round((float)($r['overall_score']??0),1),'hook'=>round((float)($r['hook_score']??0),1),'originality'=>round((float)($r['originality_score']??0),1),'recommendation'=>round((float)($r['recommendation_score']??0),1)];foreach(['format','quality','hook','originality','recommendation'] as $k){$v=$k==='format'?($r['type']==='reel'?1.15:1.0):max(.4,min(1.2,($factors[$k]?:50)/70));$st=$db->prepare('INSERT INTO content_attribution(user_id,content_id,outcome_metric,outcome_value,factor_key,factor_weight,evidence) VALUES(?,?,?,?,?,?,?)');$st->execute([$userId,$r['id'],'engagement_rate',$eng,$k,$v,v42_json(['factors'=>$factors])]);}$out[]=['content_id'=>(int)$r['id'],'engagement_rate'=>round($eng,3),'factors'=>$factors];}
    $agg=[];foreach($out as $o)foreach($o['factors'] as $k=>$v){if($k==='format')continue;$agg[$k][]=$o['engagement_rate']*(($v?:50)/100);} $summary=[];foreach($agg as $k=>$vals)$summary[]=['factor'=>$k,'impact'=>round(array_sum($vals)/max(1,count($vals)),2)];usort($summary,fn($a,$b)=>$b['impact']<=>$a['impact']);return ['samples'=>count($out),'items'=>array_slice($out,0,12),'factors'=>$summary];
}
