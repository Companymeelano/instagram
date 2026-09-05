<?php
declare(strict_types=1);

function ig_base_url(string $path = ''): string {
    global $config;
    return rtrim($config['instagram']['graph_base_url'] ?? 'https://graph.instagram.com', '/') . '/' . ltrim($path, '/');
}
function ig_encrypt(string $plain): string {
    global $config;
    $seed = (string)($config['app']['encryption_key'] ?? '');
    if (strlen($seed) < 32 || str_contains($seed, 'CHANGE_THIS')) throw new RuntimeException('app.encryption_key تنظیم نشده است.');
    $key = hash('sha256', $seed, true); $iv = random_bytes(12); $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) throw new RuntimeException('Token encryption failed.');
    return base64_encode($iv.$tag.$cipher);
}
function ig_decrypt(string $payload): string {
    global $config;
    $seed = (string)($config['app']['encryption_key'] ?? '');
    if (strlen($seed) < 32 || str_contains($seed, 'CHANGE_THIS')) throw new RuntimeException('app.encryption_key تنظیم نشده است.');
    $raw = base64_decode($payload, true);
    if ($raw === false || strlen($raw) < 29) throw new RuntimeException('Token payload invalid.');
    $key = hash('sha256', $seed, true); $iv=substr($raw,0,12); $tag=substr($raw,12,16); $cipher=substr($raw,28);
    $plain=openssl_decrypt($cipher,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag);
    if ($plain === false) throw new RuntimeException('Token decrypt failed.');
    return $plain;
}
function ig_graph_get(string $path, array $query = [], int $timeout = 45): array {
    global $config;
    $query['access_token'] = $query['access_token'] ?? null;
    if ($query['access_token'] === null) unset($query['access_token']);
    $url=ig_base_url($path).($query?'?'.http_build_query($query):'');
    $r=curl_json($url,['method'=>'GET','headers'=>['Accept: application/json'],'timeout'=>$timeout]);
    return $r;
}
function ig_exchange_code(string $code): array {
    global $config;
    $url=$config['instagram']['oauth_token_url'] ?? 'https://api.instagram.com/oauth/access_token';
    $fields=http_build_query(['client_id'=>$config['instagram']['app_id'],'client_secret'=>$config['instagram']['app_secret'],'grant_type'=>'authorization_code','redirect_uri'=>$config['instagram']['redirect_uri'],'code'=>$code]);
    return curl_json($url,['method'=>'POST','headers'=>['Accept: application/json','Content-Type: application/x-www-form-urlencoded'],'body'=>$fields,'timeout'=>30]);
}
function ig_long_lived_token(string $short): array {
    global $config;
    $r=ig_graph_get('/access_token',['grant_type'=>'ig_exchange_token','client_secret'=>$config['instagram']['app_secret'],'access_token'=>$short],30);
    return $r;
}
function ig_refresh_token(string $token): array {
    $r=ig_graph_get('/refresh_access_token',['grant_type'=>'ig_refresh_token','access_token'=>$token],30);
    return $r;
}
function ig_profile(string $token): array {
    $fields='id,username,name,profile_picture_url,followers_count,follows_count,media_count,biography,website,account_type';
    $r=ig_graph_get('/me',['fields'=>$fields,'access_token'=>$token],30);
    if($r['status']<200||$r['status']>=300) throw new RuntimeException('Instagram profile request failed: '.($r['data']['error']['message']??'HTTP '.$r['status']));
    return $r['data'];
}
function ig_media(string $igUserId,string $token,int $limit=25): array {
    $fields='id,caption,media_type,media_url,thumbnail_url,permalink,timestamp,username,comments_count,like_count';
    $r=ig_graph_get('/'.rawurlencode($igUserId).'/media',['fields'=>$fields,'limit'=>max(1,min(100,$limit)),'access_token'=>$token]);
    if($r['status']<200||$r['status']>=300) throw new RuntimeException('Instagram media sync failed: '.($r['data']['error']['message']??'HTTP '.$r['status']));
    return $r['data'];
}

function ig_media_insights(string $mediaId, string $token, ?string $mediaType = null): array {
    global $config;
    $metrics=(string)($config['instagram']['media_insights_metrics'] ?? 'views,reach,likes,comments,saved,shares,total_interactions');
    $candidates=array_values(array_filter(array_map('trim', explode(',', $metrics))));
    if(!$candidates) $candidates=['reach','likes','comments','saved','shares'];
    $attempts=[];
    $seen=[];
    $attempts[]=$candidates;
    $attempts[]=['reach','likes','comments','saved','shares'];
    $attempts[]=['reach','likes','comments','saved'];
    $attempts[]=['reach'];
    $last=null;
    foreach($attempts as $set){
        $key=implode(',', $set); if(isset($seen[$key])) continue; $seen[$key]=true;
        $r=ig_graph_get('/'.rawurlencode($mediaId).'/insights',['metric'=>$key],45);
        if($r['status']>=200 && $r['status']<300) return $r['data'];
        $last=$r;
    }
    throw new RuntimeException('Instagram media insights failed: '.($last['data']['error']['message']??'HTTP '.($last['status']??0)));
}

function ig_media_insight_map(array $payload): array {
    $out=[];
    foreach(($payload['data']??[]) as $metric){
        $name=(string)($metric['name']??'');
        $value=null;
        if(isset($metric['total_value']['value']) && is_numeric($metric['total_value']['value'])) $value=(float)$metric['total_value']['value'];
        elseif(isset($metric['values'][0]['value']) && is_numeric($metric['values'][0]['value'])) $value=(float)$metric['values'][0]['value'];
        if($name!=='' && $value!==null) $out[$name]=$value;
    }
    return $out;
}

function ig_publish_container(string $igUserId,string $token,array $params): array {
    // POST manually because ig_graph_get is GET-only.
    global $config;
    $url=ig_base_url('/'.rawurlencode($igUserId).'/media');
    $params['access_token']=$token;
    $r=curl_json($url,['method'=>'POST','headers'=>['Accept: application/json','Content-Type: application/x-www-form-urlencoded'],'body'=>http_build_query($params),'timeout'=>60]);
    if($r['status']<200||$r['status']>=300) throw new RuntimeException('Instagram media container failed: '.($r['data']['error']['message']??'HTTP '.$r['status']));
    return $r['data'];
}
function ig_container_status(string $containerId,string $token): array {
    $r=ig_graph_get('/'.rawurlencode($containerId),['fields'=>'id,status_code,status'],30);
    if($r['status']<200||$r['status']>=300) throw new RuntimeException('Container status failed: '.($r['data']['error']['message']??'HTTP '.$r['status']));
    return $r['data'];
}
function ig_publish_container_id(string $igUserId,string $containerId,string $token): array {
    $url=ig_base_url('/'.rawurlencode($igUserId).'/media_publish');
    $r=curl_json($url,['method'=>'POST','headers'=>['Accept: application/json','Content-Type: application/x-www-form-urlencoded'],'body'=>http_build_query(['creation_id'=>$containerId,'access_token'=>$token]),'timeout'=>60]);
    if($r['status']<200||$r['status']>=300) throw new RuntimeException('Instagram publish failed: '.($r['data']['error']['message']??'HTTP '.$r['status']));
    return $r['data'];
}

function ig_insights(string $igUserId,string $token,int $days=30): array {
    global $config;
    $metrics=(string)($config['instagram']['insights_metrics']??'reach,accounts_engaged,total_interactions,follower_count,profile_views');
    $period=(string)($config['instagram']['insights_period']??'day');
    $r=ig_graph_get('/'.rawurlencode($igUserId).'/insights',[
        'metric'=>$metrics,'period'=>$period,'access_token'=>$token
    ],60);
    if($r['status']<200||$r['status']>=300) throw new RuntimeException('Instagram insights request failed: '.($r['data']['error']['message']??'HTTP '.$r['status']));
    return $r['data'];
}
