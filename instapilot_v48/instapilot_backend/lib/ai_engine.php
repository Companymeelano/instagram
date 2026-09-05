<?php
declare(strict_types=1);

function ai_generate_json(string $system, string $prompt, string $provider = ''): array {
    global $config;
    $provider = $provider ?: (string)($config['ai']['provider'] ?? 'builtin');
    if ($provider === 'builtin') return ['demo'=>true,'provider'=>'builtin','result'=>[
        'summary'=>'حالت Builtin فعال است؛ برای اجرای AI واقعی Provider را تنظیم کنید.',
        'strategy'=>['تحلیل عملکرد واقعی پیج','تمرکز روی محتوای برتر','آزمایش زمان و CTA'],
        'weekly_actions'=>['۲ ریلز آموزشی','۲ استوری تعاملی','۱ محتوای اعتمادساز'],
        'content_mix'=>['Reel 50%','Story 30%','Carousel 20%'],
        'experiments'=>['A/B Hook','دو بازه انتشار'],
        'kpis'=>['Engagement Rate','Reach','Saves','Shares'],
        'risks'=>['داده کافی نیست','نیاز به اتصال واقعی Instagram'],
        'next_actions'=>['اتصال Instagram','Sync Insights','اجرای اولین آزمایش']
    ]];
    $text=''; $raw=null;
    if ($provider==='groq' && !empty($config['ai']['groq_api_key'])) {
        $r=curl_json('https://api.groq.com/openai/v1/chat/completions',[
            'method'=>'POST','headers'=>['Content-Type: application/json','Authorization: Bearer '.$config['ai']['groq_api_key']],
            'body'=>json_encode(['model'=>$config['ai']['groq_model'],'messages'=>[['role'=>'system','content'=>$system],['role'=>'user','content'=>$prompt]],'temperature'=>0.35]),'timeout'=>90]);
        if($r['status']>=200&&$r['status']<300){$raw=$r['data'];$text=(string)($raw['choices'][0]['message']['content']??'');}
        else throw new RuntimeException('Groq AI request failed.');
    } elseif ($provider==='gemini' && !empty($config['ai']['gemini_api_key'])) {
        $model=$config['ai']['gemini_model'];
        $url='https://generativelanguage.googleapis.com/v1beta/models/'.rawurlencode($model).':generateContent?key='.rawurlencode($config['ai']['gemini_api_key']);
        $r=curl_json($url,['method'=>'POST','headers'=>['Content-Type: application/json'],'body'=>json_encode(['systemInstruction'=>['parts'=>[['text'=>$system]]],'contents'=>[['parts'=>[['text'=>$prompt]]]],'generationConfig'=>['temperature'=>0.35,'responseMimeType'=>'application/json']]),'timeout'=>90]);
        if($r['status']>=200&&$r['status']<300){$raw=$r['data'];$text=(string)($raw['candidates'][0]['content']['parts'][0]['text']??'');}
        else throw new RuntimeException('Gemini AI request failed.');
    } else {
        return ['demo'=>true,'provider'=>$provider,'result'=>['summary'=>'Provider انتخاب‌شده Credential معتبر ندارد.','next_actions'=>['تنظیم API Key در config.php']]];
    }
    $text=preg_replace('/^```(?:json)?\s*|\s*```$/i','',trim($text));
    $result=json_decode($text,true);
    if(!is_array($result)) throw new RuntimeException('AI پاسخ JSON معتبر برنگرداند.');
    return ['demo'=>false,'provider'=>$provider,'result'=>$result,'raw'=>$raw];
}

function autopilot_context(int $userId, int $days=30): array {
    $db=db();
    $s=$db->prepare('SELECT * FROM brand_profiles WHERE user_id=?');$s->execute([$userId]);$brand=$s->fetch()?:[];
    $s=$db->prepare('SELECT metric_date,followers,reach,impressions,engagement,saves,shares,profile_visits FROM analytics_daily WHERE user_id=? AND metric_date>=DATE_SUB(CURDATE(),INTERVAL ? DAY) ORDER BY metric_date ASC');$s->execute([$userId,$days]);$series=$s->fetchAll();
    $s=$db->prepare('SELECT memory_type,memory_key,memory_value,confidence,source FROM ai_memories WHERE user_id=? ORDER BY confidence DESC,updated_at DESC LIMIT 30');$s->execute([$userId]);$memory=$s->fetchAll();
    $s=$db->prepare('SELECT goal,horizon_days,plan,status,created_at FROM growth_plans WHERE user_id=? ORDER BY created_at DESC LIMIT 3');$s->execute([$userId]);$plans=$s->fetchAll();
    $s=$db->prepare('SELECT type,status,scheduled_at,published_at FROM content_items WHERE user_id=? ORDER BY created_at DESC LIMIT 20');$s->execute([$userId]);$content=$s->fetchAll();
    return compact('brand','series','memory','plans','content');
}

function autopilot_execute(int $userId,string $goal,int $days): array {
    $ctx=autopilot_context($userId,$days);
    $system='You are the InstaPilot Growth Autopilot. Use only supplied data; never claim a metric is real if missing. Return valid JSON with keys: summary,strategy,weekly_actions,content_mix,experiments,kpis,risks,next_actions,learning. learning must be an array of objects with memory_key,memory_value,confidence,source. Prioritize measurable actions and clearly label missing data.';
    $prompt='Goal: '.json_encode($goal,JSON_UNESCAPED_UNICODE)."\nHorizon: {$days} days\nContext:\n".json_encode($ctx,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $r=ai_generate_json($system,$prompt);
    $result=$r['result'];
    foreach(($result['learning']??[]) as $m){
        $key=trim((string)($m['memory_key']??''));$val=trim((string)($m['memory_value']??''));if($key===''||$val==='')continue;
        $conf=max(0,min(100,(float)($m['confidence']??70)));$src=trim((string)($m['source']??'autopilot'));
        $s=db()->prepare('INSERT INTO ai_memories(user_id,memory_type,memory_key,memory_value,confidence,source) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE memory_value=VALUES(memory_value),confidence=VALUES(confidence),source=VALUES(source),updated_at=NOW()');$s->execute([$userId,'autopilot',$key,$val,$conf,$src]);
    }
    $s=db()->prepare('INSERT INTO growth_plans(user_id,goal,horizon_days,plan,status) VALUES(?,?,?,?,"active")');$s->execute([$userId,$goal,$days,json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
    $planId=(int)db()->lastInsertId();
    $s=db()->prepare('INSERT INTO autopilot_runs(user_id,growth_plan_id,goal,horizon_days,context,output,provider,status,completed_at) VALUES(?,?,?,?,?,?,?,?,NOW())');$s->execute([$userId,$planId,$goal,$days,json_encode($ctx,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),json_encode($result,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$r['provider'],'completed']);
    notify($userId,'ai','AI Growth Autopilot اجرا شد','تحلیل، برنامه رشد و یادگیری جدید ثبت شد.');
    audit($userId,'autopilot.execute',['plan_id'=>$planId,'provider'=>$r['provider'],'demo'=>$r['demo']]);
    return ['plan_id'=>$planId,'demo'=>$r['demo'],'provider'=>$r['provider'],'plan'=>$result];
}

function autonomous_content_execute(int $userId,string $goal,string $type='reel'): array {
    $type=in_array($type,['reel','post','story'],true)?$type:'reel';
    $ctx=autopilot_context($userId,30);
    $system='You are InstaPilot Autonomous Content Agent. Create ONE publish-ready draft, but NEVER publish it automatically. Use only supplied data. Return valid JSON keys: title,hook,caption,hashtags,cta,format,reasoning (array),best_time,score_factors (object with brand_match,hook_score,cta_score,engagement_score,overall),predicted_engagement_rate,prediction_confidence,prediction_basis,recommendation,risks. The reasoning must explain why the content fits the supplied brand and data. Do not invent metrics; if data is missing say so.';
    $prompt='Goal: '.json_encode($goal,JSON_UNESCAPED_UNICODE)."\nRequested type: {$type}\nContext:\n".json_encode($ctx,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $run=db()->prepare('INSERT INTO content_ai_runs(user_id,goal,context,status) VALUES(?,?,?,"running")');$run->execute([$userId,$goal,json_encode($ctx,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);$runId=(int)db()->lastInsertId();
    try {
        $r=ai_generate_json($system,$prompt); $o=$r['result'];
        $title=trim((string)($o['title']??'پیشنهاد محتوای AI')); $hook=trim((string)($o['hook']??'')); $caption=trim((string)($o['caption']??''));
        if($hook!=='' && $caption!=='' && !str_contains($caption,$hook)) $caption=$hook."\n\n".$caption;
        $hashtags=is_array($o['hashtags']??null)?implode(' ',array_map('strval',$o['hashtags'])):(string)($o['hashtags']??'');
        $score=$o['score_factors']??[]; $overall=max(0,min(100,(float)($score['overall']??0)));
        $s=db()->prepare('INSERT INTO content_items(user_id,type,title,caption,hashtags,status) VALUES(?,?,?,?,?,"draft")');$s->execute([$userId,$type,$title,$caption,$hashtags]);$contentId=(int)db()->lastInsertId();
        $s=db()->prepare('INSERT INTO content_ai_reviews(user_id,content_id,score,brand_match,hook_score,cta_score,engagement_score,explanation,recommendation,provider) VALUES(?,?,?,?,?,?,?,?,?,?)');
        $s->execute([$userId,$contentId,$overall,(float)($score['brand_match']??0),(float)($score['hook_score']??0),(float)($score['cta_score']??0),(float)($score['engagement_score']??0),json_encode($o['reasoning']??[],JSON_UNESCAPED_UNICODE),$o['recommendation']??null,$r['provider']]);
        $pred=isset($o['predicted_engagement_rate']) && is_numeric($o['predicted_engagement_rate']) ? max(0,(float)$o['predicted_engagement_rate']) : null;
        $predConf=isset($o['prediction_confidence']) && is_numeric($o['prediction_confidence']) ? max(0,min(100,(float)$o['prediction_confidence'])) : 0;
        if($pred!==null){
            $ps=db()->prepare('INSERT INTO content_predictions(user_id,content_id,metric_key,predicted_value,confidence,basis) VALUES(?,?,?,?,?,?)');
            $ps->execute([$userId,$contentId,'engagement_rate',$pred,$predConf,json_encode($o['prediction_basis']??[],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
        }
        $o['content_id']=$contentId; $o['score']=$overall; $o['prediction_saved']=$pred!==null;
        db()->prepare('UPDATE content_ai_runs SET content_id=?,output=?,provider=?,status="completed",completed_at=NOW() WHERE id=?')->execute([$contentId,json_encode($o,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$r['provider'],$runId]);
        notify($userId,'ai','پیش‌نویس هوشمند آماده شد','AI یک محتوای پیشنهادی ساخته و دلیل انتخاب آن را ثبت کرده است.'); audit($userId,'content.autonomous_draft',['content_id'=>$contentId,'score'=>$overall,'provider'=>$r['provider']]);
        return ['content_id'=>$contentId,'run_id'=>$runId,'provider'=>$r['provider'],'demo'=>$r['demo'],'content'=>$o];
    } catch(Throwable $e){
        db()->prepare('UPDATE content_ai_runs SET status="failed",error_message=?,completed_at=NOW() WHERE id=?')->execute([$e->getMessage(),$runId]); audit($userId,'content.autonomous_draft_error',['message'=>$e->getMessage()]); throw $e;
    }
}
