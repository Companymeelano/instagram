<?php
declare(strict_types=1);

function attribution_clamp(float $v,float $a=-100,float $b=100):float{return max($a,min($b,$v));}

function attribution_factor_set(array $content,array $review=[]):array{
    $f=[];
    $type=(string)($content['type']??'unknown'); if($type!=='')$f['format']=$type;
    $caption=(string)($content['caption']??''); $len=mb_strlen($caption,'UTF-8');
    $f['caption_length']=$len<80?'short':($len<220?'medium':'long');
    if(!empty($content['published_at'])){ $h=(int)date('G',strtotime((string)$content['published_at'])); $f['publish_window']=$h<6?'night':($h<12?'morning':($h<17?'afternoon':($h<21?'evening':'late_evening'))); $f['weekday']=date('N',strtotime((string)$content['published_at'])); }
    if($review){
      foreach(['hook_score'=>'hook_quality','cta_score'=>'cta_quality','brand_match'=>'brand_match','score'=>'ai_score','engagement_score'=>'ai_engagement_score'] as $src=>$key){ if(isset($review[$src])&&$review[$src]!==null){$v=(float)$review[$src];$f[$key]=$v>=85?'high':($v>=70?'medium':'low');}}
    }
    if(str_contains($caption,'؟')||preg_match('/\b(نظر|کامنت|ذخیره|اشتراک|بگو|ارسال)\b/iu',$caption))$f['cta_presence']='present'; else $f['cta_presence']='not_detected';
    return $f;
}

function attribution_build(int $userId):array{
 $db=db();
 $baseQ=$db->prepare('SELECT AVG((likes+comments+saves+shares)/NULLIF(reach,0)*100) FROM content_performance WHERE user_id=? AND source="meta" AND reach>0');$baseQ->execute([$userId]);$baseline=(float)($baseQ->fetchColumn()?:0);
 if($baseline<=0)return ['status'=>'insufficient_data','message'=>'برای Attribution حداقل داده Performance واقعی Meta لازم است.'];
 $q=$db->prepare('SELECT c.id,c.type,c.title,c.caption,c.published_at,p.reach,p.likes,p.comments,p.saves,p.shares, r.score,r.brand_match,r.hook_score,r.cta_score,r.engagement_score FROM content_items c JOIN content_performance p ON p.content_id=c.id AND p.source="meta" LEFT JOIN content_ai_reviews r ON r.content_id=c.id AND r.id=(SELECT MAX(r2.id) FROM content_ai_reviews r2 WHERE r2.content_id=c.id) WHERE c.user_id=? AND p.reach>0 ORDER BY c.id DESC');$q->execute([$userId]);$rows=$q->fetchAll();
 if(count($rows)<3)return ['status'=>'insufficient_data','message'=>'برای Attribution حداقل ۳ محتوای دارای Performance واقعی Meta لازم است.','sample_count'=>count($rows)];
 $groups=[];
 foreach($rows as $r){$actual=((int)$r['likes']+(int)$r['comments']+(int)$r['saves']+(int)$r['shares'])/(int)$r['reach']*100;$f=attribution_factor_set($r,$r);foreach($f as $k=>$v){$key=$k.'|'.$v;$groups[$key]['factor_key']=$k;$groups[$key]['factor_value']=$v;$groups[$key]['values'][]=$actual;$groups[$key]['content_ids'][]=(int)$r['id'];}}
 $weights=[];$totalAbs=0;
 foreach($groups as $g){$n=count($g['values']);$mean=array_sum($g['values'])/$n;$lift=$baseline>0?(($mean-$baseline)/$baseline*100):0;$shrink=$n/($n+5);$weighted=$lift*$shrink;$abs=abs($weighted);$totalAbs+=$abs;$weights[]=['factor_key'=>$g['factor_key'],'factor_value'=>$g['factor_value'],'sample_count'=>$n,'mean_performance'=>round($mean,4),'baseline_performance'=>round($baseline,4),'lift_pct'=>round($lift,4),'raw_weight'=>abs($weighted),'confidence'=>round(min(100,25+$n*10+$shrink*40),1),'content_ids'=>$g['content_ids']];}
 foreach($weights as &$w){$w['weight_pct']=$totalAbs>0?round(($w['raw_weight']/$totalAbs)*100,2):0;unset($w['raw_weight']);$s=$db->prepare('INSERT INTO attribution_factor_weights(user_id,metric_key,factor_key,factor_value,sample_count,mean_performance,baseline_performance,lift_pct,weight_pct,confidence) VALUES(?,?,?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE sample_count=VALUES(sample_count),mean_performance=VALUES(mean_performance),baseline_performance=VALUES(baseline_performance),lift_pct=VALUES(lift_pct),weight_pct=VALUES(weight_pct),confidence=VALUES(confidence),updated_at=NOW()');$s->execute([$userId,'engagement_rate',$w['factor_key'],$w['factor_value'],$w['sample_count'],$w['mean_performance'],$w['baseline_performance'],$w['lift_pct'],$w['weight_pct'],$w['confidence']]);}
 unset($w);
 $top=$weights;usort($top,fn($a,$b)=>abs($b['lift_pct'])<=>abs($a['lift_pct']));
 foreach(array_slice($rows,0,30) as $r){$actual=((int)$r['likes']+(int)$r['comments']+(int)$r['saves']+(int)$r['shares'])/(int)$r['reach']*100;$f=attribution_factor_set($r,$r);$parts=[];$conf=[];foreach($f as $k=>$v){$found=null;foreach($weights as $w){if($w['factor_key']===$k&&$w['factor_value']===$v){$found=$w;break;}}if($found){$parts[$k]=['value'=>$v,'lift_pct'=>$found['lift_pct'],'weight_pct'=>$found['weight_pct'],'confidence'=>$found['confidence']];$conf[]=$found['confidence'];}}$ec=$conf?array_sum($conf)/count($conf):0;$ins=$db->prepare('INSERT INTO attribution_events(user_id,content_id,metric_key,actual_value,baseline_value,attribution,confidence) VALUES(?,?,?,?,?,?,?)');$ins->execute([$userId,$r['id'],'engagement_rate',$actual,$baseline,json_encode($parts,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$ec]);}
 return ['status'=>'built','sample_count'=>count($rows),'baseline'=>round($baseline,3),'factors'=>$top,'top_positive'=>array_values(array_filter($top,fn($x)=>$x['lift_pct']>0)),'top_negative'=>array_values(array_filter($top,fn($x)=>$x['lift_pct']<0))];
}

function attribution_overview(int $userId):array{
 $db=db();$s=$db->prepare('SELECT * FROM attribution_factor_weights WHERE user_id=? AND metric_key="engagement_rate" ORDER BY ABS(lift_pct) DESC,confidence DESC LIMIT 30');$s->execute([$userId]);$f=$s->fetchAll();$s=$db->prepare('SELECT COUNT(*) FROM attribution_events WHERE user_id=?');$s->execute([$userId]);$events=(int)$s->fetchColumn();$s=$db->prepare('SELECT created_at,content_id,actual_value,baseline_value,attribution,confidence FROM attribution_events WHERE user_id=? ORDER BY id DESC LIMIT 12');$s->execute([$userId]);$h=$s->fetchAll();foreach($h as &$x)$x['attribution']=json_decode((string)$x['attribution'],true);return ['events'=>$events,'factors'=>$f,'history'=>$h];
}
