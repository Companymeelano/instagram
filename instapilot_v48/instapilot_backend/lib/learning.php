<?php
declare(strict_types=1);

function learning_percentile(float $v): float { return max(0.0,min(100.0,$v)); }

function learning_apply_for_content(int $userId, int $contentId): array {
    $db=db();
    $s=$db->prepare('SELECT p.*, c.type, c.title, r.score, r.provider FROM content_performance p JOIN content_items c ON c.id=p.content_id LEFT JOIN content_ai_reviews r ON r.content_id=p.content_id WHERE p.user_id=? AND p.content_id=? AND p.source="meta" ORDER BY p.metric_date DESC,p.id DESC LIMIT 1');
    $s->execute([$userId,$contentId]); $p=$s->fetch();
    if(!$p) return ['status'=>'insufficient_data','reason'=>'No verified Meta performance is available for this content.'];
    $reach=(int)$p['reach']; $interactions=(int)$p['likes']+(int)$p['comments']+(int)$p['saves']+(int)$p['shares'];
    if($reach<=0) return ['status'=>'insufficient_data','reason'=>'Verified reach is zero or unavailable.'];
    $actual=($interactions/$reach)*100;
    $recent=$db->prepare('SELECT actual_value FROM learning_events WHERE user_id=? AND content_id=? AND event_type="performance_learning" AND created_at>=DATE_SUB(NOW(), INTERVAL 6 HOUR) ORDER BY id DESC LIMIT 1');
    $recent->execute([$userId,$contentId]); $recentValue=$recent->fetchColumn();
    if($recentValue!==false && abs((float)$recentValue-$actual)<0.0001) return ['status'=>'already_processed','actual_engagement_rate'=>round($actual,3)];
    $s=$db->prepare('SELECT predicted_value,confidence FROM content_predictions WHERE content_id=? AND metric_key="engagement_rate" ORDER BY id DESC LIMIT 1'); $s->execute([$contentId]); $pred=$s->fetch();
    $predictionError=null; $direction='unrated'; $confidence=0;
    if($pred && $pred['predicted_value']!==null){
        $predictionError=abs($actual-(float)$pred['predicted_value']);
        $direction=$actual >= (float)$pred['predicted_value'] ? 'overperformed':'underperformed';
        $confidence=(float)$pred['confidence'];
    }
    $benchmarkStmt=$db->prepare('SELECT AVG((likes+comments+saves+shares)/NULLIF(reach,0)*100) avg_eng FROM content_performance WHERE user_id=? AND source="meta" AND reach>0 AND content_id<>?'); $benchmarkStmt->execute([$userId,$contentId]); $benchmark=(float)($benchmarkStmt->fetchColumn()?:0);
    $winner=$benchmark>0 ? ($actual >= $benchmark*1.15 ? 'winner' : ($actual <= $benchmark*0.85 ? 'loser':'neutral')) : 'neutral';
    $factors=['format'=>$p['type'],'actual_engagement_rate'=>round($actual,3),'benchmark_engagement_rate'=>round($benchmark,3),'winner_status'=>$winner];
    $s=$db->prepare('INSERT INTO learning_events(user_id,content_id,event_type,metric_key,predicted_value,actual_value,error_value,confidence,evidence,created_at) VALUES(?,?,?,?,?,?,?,?,?,NOW())');
    $s->execute([$userId,$contentId,'performance_learning','engagement_rate',$pred['predicted_value']??null,$actual,$predictionError,$confidence,json_encode($factors,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    $memKey='format:'.$p['type'].':engagement'; $memValue='نرخ تعامل واقعی این فرمت: '.number_format($actual,2).'٪؛ وضعیت: '.$winner.'.';
    $memConfidence=$benchmark>0?learning_percentile(60+min(35,abs($actual-$benchmark)*8)):55;
    $s=$db->prepare('INSERT INTO ai_memories(user_id,memory_type,memory_key,memory_value,confidence,source) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE memory_value=VALUES(memory_value),confidence=LEAST(100,(confidence*0.65)+(VALUES(confidence)*0.35)),source=VALUES(source),updated_at=NOW()');
    $s->execute([$userId,'performance',$memKey,$memValue,$memConfidence,'meta_learning']);
    $s=$db->prepare('INSERT INTO learning_profiles(user_id,metric_key,sample_count,mean_absolute_error,winner_count,loser_count,confidence,updated_at) VALUES(?,?,?,?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE mean_absolute_error=((mean_absolute_error*sample_count)+VALUES(mean_absolute_error))/NULLIF(sample_count+1,0), winner_count=winner_count+VALUES(winner_count), loser_count=loser_count+VALUES(loser_count), confidence=LEAST(100,confidence+2), sample_count=sample_count+1, updated_at=NOW()');
    $s->execute([$userId,'engagement_rate',1,$predictionError??0,$winner==='winner'?1:0,$winner==='loser'?1:0,$predictionError===null?45:learning_percentile(100-min(70,$predictionError*8))]);
    return ['status'=>'learned','actual_engagement_rate'=>round($actual,3),'benchmark'=>round($benchmark,3),'winner'=>$winner,'prediction_error'=>$predictionError,'confidence'=>round($confidence,1)];
}

function learning_overview(int $userId): array {
    $db=db();
    $s=$db->prepare('SELECT * FROM learning_profiles WHERE user_id=? ORDER BY updated_at DESC');$s->execute([$userId]);$profiles=$s->fetchAll();
    $s=$db->prepare('SELECT COUNT(*) FROM learning_events WHERE user_id=?');$s->execute([$userId]);$events=(int)$s->fetchColumn();
    $s=$db->prepare('SELECT COUNT(*) FROM content_performance WHERE user_id=? AND source="meta"');$s->execute([$userId]);$verified=(int)$s->fetchColumn();
    $s=$db->prepare('SELECT COUNT(*) FROM learning_events WHERE user_id=? AND event_type="performance_learning" AND error_value IS NOT NULL');$s->execute([$userId]);$predCount=(int)$s->fetchColumn();
    $s=$db->prepare('SELECT COUNT(*) FROM learning_events WHERE user_id=? AND JSON_UNQUOTE(JSON_EXTRACT(evidence,"$.winner_status"))="winner"');$s->execute([$userId]);$winners=(int)$s->fetchColumn();
    $s=$db->prepare('SELECT COUNT(*) FROM learning_events WHERE user_id=? AND JSON_UNQUOTE(JSON_EXTRACT(evidence,"$.winner_status"))="loser"');$s->execute([$userId]);$losers=(int)$s->fetchColumn();
    $accuracy=null; if($predCount){$s=$db->prepare('SELECT AVG(error_value) FROM learning_events WHERE user_id=? AND error_value IS NOT NULL');$s->execute([$userId]);$mae=(float)$s->fetchColumn();$accuracy=round(max(0,100-min(100,$mae*10)),1);} else $mae=null;
    $s=$db->prepare('SELECT memory_key,memory_value,confidence,updated_at FROM ai_memories WHERE user_id=? AND memory_type="performance" ORDER BY confidence DESC,updated_at DESC LIMIT 12');$s->execute([$userId]);$memory=$s->fetchAll();
    $s=$db->prepare('SELECT e.created_at,e.content_id,e.actual_value,e.predicted_value,e.error_value,e.confidence,e.evidence,c.title,c.type FROM learning_events e JOIN content_items c ON c.id=e.content_id WHERE e.user_id=? ORDER BY e.id DESC LIMIT 20');$s->execute([$userId]);$history=$s->fetchAll();
    return ['events'=>$events,'verified_samples'=>$verified,'prediction_samples'=>$predCount,'accuracy'=>$accuracy,'mean_absolute_error'=>$mae,'winners'=>$winners,'losers'=>$losers,'profiles'=>$profiles,'memory'=>$memory,'history'=>$history];
}
