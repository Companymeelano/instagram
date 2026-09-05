<?php
declare(strict_types=1);

function v17_parse_goal(string $goal): array {
    $g=mb_strtolower($goal,'UTF-8');
    $kpis=[];
    if(str_contains($g,'save')||str_contains($g,'ذخیره')) $kpis[]='saves';
    if(str_contains($g,'share')||str_contains($g,'اشتراک')) $kpis[]='shares';
    if(str_contains($g,'reach')||str_contains($g,'ریچ')||str_contains($g,'دیده')) $kpis[]='reach';
    if(str_contains($g,'فالو')||str_contains($g,'follower')) $kpis[]='followers';
    if(str_contains($g,'تعامل')||str_contains($g,'engagement')) $kpis[]='engagement';
    if(!$kpis)$kpis=['engagement','saves'];
    $type='reel';
    if(str_contains($g,'پست')||str_contains($g,'post'))$type='post';
    if(str_contains($g,'استوری')||str_contains($g,'story'))$type='story';
    return ['kpis'=>array_values(array_unique($kpis)),'preferred_type'=>$type];
}

function v17_scheduler_slots(int $days, int $count=6): array {
    $slots=[]; $now=new DateTimeImmutable('now');
    $patterns=[['18:30',.92],['20:30',.96],['12:30',.78],['21:00',.88],['17:45',.84]];
    for($i=0;$i<$count;$i++){
        $day=2+(int)floor(($i*max(2,$days-2))/max(1,$count-1));
        $d=$now->modify('+'.$day.' days'); $p=$patterns[$i%count($patterns)];
        [$h,$m]=array_map('intval',explode(':',$p[0]));
        $d=$d->setTime($h,$m,0);
        $slots[]=['at'=>$d->format('Y-m-d H:i:s'),'score'=>$p[1]*100,'reason'=>['window'=>$p[0],'basis'=>'historical-performance-and-safe-default','confidence'=>round($p[1]*100,1)]];
    }
    return $slots;
}

function v17_build_plan(int $userId, int $missionId, string $goal, int $days, array $decision=[]): array {
    $parsed=v17_parse_goal($goal); $slots=v17_scheduler_slots($days,6);
    $plan=['goal'=>$goal,'horizon_days'=>$days,'kpis'=>$parsed['kpis'],'preferred_format'=>$parsed['preferred_type'],'content_count'=>count($slots),'approval_gate'=>true,'steps'=>['analyze','decide','create','approve','schedule','publish','measure','learn'],'schedule'=>$slots,'decision'=>$decision,'created_at'=>date(DATE_ATOM)];
    $stmt=db()->prepare('INSERT INTO mission_plans(user_id,mission_id,plan,status) VALUES(?,?,?,"planned")');
    $stmt->execute([$userId,$missionId,json_encode($plan,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    $planId=(int)db()->lastInsertId();
    foreach($slots as $slot){$s=db()->prepare('INSERT INTO scheduler_recommendations(user_id,mission_id,recommended_at,score,reason) VALUES(?,?,?,?,?)');$s->execute([$userId,$missionId,$slot['at'],$slot['score'],json_encode($slot['reason'],JSON_UNESCAPED_UNICODE)]);}
    db()->prepare('UPDATE mission_sessions SET status="drafting",current_step=3,blueprint=? WHERE id=? AND user_id=?')->execute([json_encode(['plan_id'=>$planId,'kpis'=>$parsed['kpis'],'format'=>$parsed['preferred_type']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$missionId,$userId]);
    return ['plan_id'=>$planId,'plan'=>$plan];
}

function v17_queue_content_batch(int $userId,int $missionId,array $plan): int {
    $n=0; $type=(string)($plan['preferred_format']??'reel');
    foreach(($plan['schedule']??[]) as $slot){
        $payload=['goal'=>(string)($plan['goal']??'افزایش تعامل'),'type'=>$type,'mission_id'=>$missionId,'scheduled_at'=>$slot['at']];
        $j=db()->prepare('INSERT INTO jobs(user_id,job_type,payload,available_at) VALUES(?,?,?,NOW())');
        $j->execute([$userId,'v17_content_draft',json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]); $n++;
    }
    return $n;
}
