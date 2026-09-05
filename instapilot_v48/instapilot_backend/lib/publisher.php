<?php
declare(strict_types=1);

function publish_content_real(int $userId, int $contentId, ?int $queueId = null): array {
    $s=db()->prepare('SELECT * FROM content_items WHERE id=? AND user_id=? LIMIT 1'); $s->execute([$contentId,$userId]); $content=$s->fetch();
    if(!$content) throw new RuntimeException('محتوا پیدا نشد.');
    $mediaUrl=trim((string)($content['media_url']??''));
    if($mediaUrl==='' || !filter_var($mediaUrl,FILTER_VALIDATE_URL)) throw new RuntimeException('media_url عمومی معتبر برای این محتوا ثبت نشده است.');
    $s=db()->prepare('SELECT * FROM instagram_accounts WHERE user_id=? AND status="connected" ORDER BY id DESC LIMIT 1');$s->execute([$userId]);$acc=$s->fetch();
    if(!$acc) throw new RuntimeException('Instagram متصل نیست.');
    $type=strtoupper((string)($content['media_type']??''));
    if(!in_array($type,['IMAGE','REELS','STORIES'],true)) $type=$content['type']==='reel'?'REELS':($content['type']==='story'?'STORIES':'IMAGE');
    $token=ig_decrypt($acc['access_token']);
    $params=['media_type'=>$type];
    if($type!=='STORIES') $params['caption']=(string)($content['caption']??'');
    $params[$type==='IMAGE' || $type==='STORIES'?'image_url':'video_url']=$mediaUrl;
    $container=ig_publish_container($acc['ig_user_id'],$token,$params); $containerId=(string)($container['id']??''); if(!$containerId) throw new RuntimeException('Container ID دریافت نشد.');
    $deadline=time()+180; $status=['status_code'=>'IN_PROGRESS'];
    while(time()<$deadline){$status=ig_container_status($containerId,$token); if(in_array($status['status_code']??'', ['FINISHED','ERROR','EXPIRED','PUBLISHED'],true)) break; sleep(3);}
    if(($status['status_code']??'')!=='FINISHED') throw new RuntimeException('پردازش Media تکمیل نشد: '.($status['status_code']??'UNKNOWN'));
    $published=ig_publish_container_id($acc['ig_user_id'],$containerId,$token); $mediaId=(string)($published['id']??'');
    db()->prepare('UPDATE content_items SET status="published",published_at=NOW(),external_id=?,error_message=NULL WHERE id=? AND user_id=?')->execute([$mediaId?:null,$contentId,$userId]);
    if($queueId){db()->prepare('UPDATE publish_queue SET status="published",published_media_id=?,last_error=NULL,updated_at=NOW() WHERE id=? AND user_id=?')->execute([$mediaId?:null,$queueId,$userId]);}
    if($queueId){db()->prepare('UPDATE publish_attempts SET status="success",finished_at=NOW(),response=? WHERE publish_queue_id=? AND user_id=? AND status="started" ORDER BY id DESC LIMIT 1')->execute([json_encode(['container'=>$container,'status'=>$status,'published'=>$published],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$queueId,$userId]);}
    audit($userId,'v19.publish.success',['content_id'=>$contentId,'queue_id'=>$queueId,'media_id'=>$mediaId]); notify($userId,'publish','محتوا منتشر شد','محتوای زمان‌بندی‌شده با موفقیت در Instagram منتشر شد.');
    return ['content_id'=>$contentId,'media_id'=>$mediaId,'container'=>$container,'status'=>$status,'published'=>$published];
}
