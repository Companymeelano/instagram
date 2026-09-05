<?php
declare(strict_types=1);

function decision_next(int $userId): array {
    $db = db();
    $s = $db->prepare('SELECT COUNT(*) FROM content_performance WHERE user_id=? AND source="meta" AND reach>0');
    $s->execute([$userId]);
    $sample = (int)$s->fetchColumn();
    $s = $db->prepare('SELECT AVG((likes+comments+saves+shares)/NULLIF(reach,0)*100) FROM content_performance WHERE user_id=? AND source="meta" AND reach>0');
    $s->execute([$userId]);
    $baseline = (float)($s->fetchColumn() ?: 0);
    $s = $db->prepare('SELECT factor_key,factor_value,sample_count,lift_pct,weight_pct,confidence FROM attribution_factor_weights WHERE user_id=? AND metric_key="engagement_rate" ORDER BY ABS(lift_pct) DESC, confidence DESC LIMIT 12');
    $s->execute([$userId]);
    $factors = $s->fetchAll();
    if (!$factors || $sample < 3) {
        return ['status'=>'insufficient_data','sample_count'=>$sample,'baseline'=>round($baseline,3),'confidence'=>0,'factors'=>[], 'recommendation'=>['title'=>'داده بیشتری لازم است','summary'=>'حداقل ۳ محتوای دارای Performance واقعی Meta برای تصمیم‌گیری لازم است.','reason'=>'Decision Engine فقط از داده واقعی و قابل ردیابی استفاده می‌کند.']];
    }
    $positive = array_values(array_filter($factors, fn($f)=>(float)$f['lift_pct']>0));
    usort($positive, fn($a,$b)=>(float)$b['lift_pct'] <=>(float)$a['lift_pct']);
    $top = $positive[0] ?? $factors[0];
    $map = [
        'format'=>'فرمت', 'hook_quality'=>'Hook', 'cta_quality'=>'CTA', 'brand_match'=>'Brand Match',
        'ai_score'=>'AI Score', 'ai_engagement_score'=>'Engagement Score', 'caption_length'=>'طول کپشن',
        'publish_window'=>'بازه انتشار', 'weekday'=>'روز انتشار', 'cta_presence'=>'وجود CTA'
    ];
    $label = $map[$top['factor_key']] ?? $top['factor_key'];
    $confidence = min(96, max(45, (float)$top['confidence'] * .7 + min(30, $sample*2)));
    $title = 'تمرکز بعدی: '.$label.' — '.$top['factor_value'];
    $summary = 'این انتخاب بر اساس بالاترین Lift مشاهده‌شده در داده‌های واقعی Performance است.';
    $reason = 'عامل «'.$label.'» با مقدار «'.$top['factor_value'].'» در '.$top['sample_count'].' نمونه، Lift برابر '.number_format((float)$top['lift_pct'],1).'٪ نشان داده است. این نتیجه همبستگی مشاهده‌شده است، نه اثبات علت قطعی.';
    return ['status'=>'ready','sample_count'=>$sample,'baseline'=>round($baseline,3),'confidence'=>round($confidence,1),'factors'=>$factors,'recommendation'=>['title'=>$title,'summary'=>$summary,'reason'=>$reason]];
}
