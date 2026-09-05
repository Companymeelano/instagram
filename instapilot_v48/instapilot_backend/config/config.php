<?php
return [
    'app' => [
        'base_url' => 'https://YOUR-DOMAIN/ins',
        'timezone' => 'Asia/Tehran',
        'session_name' => 'instapilot_session',
        // V48: مسیر کوکی نشست. برای استقرار single-file در هر پوشه‌ای از هاست اشتراکی '/' بگذارید؛
        // اگر اپ فقط در /ins میزبانی می‌شود می‌توانید '/ins' کنید.
        'cookie_path' => '/',
        // V48 APK: اگر اپلیکیشن اندروید (WebView) را استفاده می‌کنید، خط زیر را فعال کنید:
        // 'cors_origins' => ['null'],
        'cors_origins' => [],
        'force_https' => true,
        'encryption_key' => 'CHANGE_THIS_TO_A_32_BYTE_RANDOM_SECRET',
    ],
    'db' => [
        'host' => 'localhost',
        'name' => 'instapilot',
        'user' => 'DB_USER',
        'pass' => 'DB_PASSWORD',
        'charset' => 'utf8mb4',
    ],
    'security' => [
        'csrf_header' => 'X-CSRF-Token',
        'idle_timeout_seconds' => 1800,
        'absolute_timeout_seconds' => 86400,
        'login_rate_limit' => 8,
        'login_rate_window_seconds' => 300,
    ],
    'ai' => [
        'provider' => 'builtin',
        'gemini_api_key' => '',
        'gemini_model' => 'gemini-2.5-flash',
        'groq_api_key' => '',
        'groq_model' => 'llama-3.3-70b-versatile',
        'gapgpt_api_key' => '',
        'gapgpt_base_url' => '',
    ],
    'instagram' => [
        'app_id' => '',
        'app_secret' => '',
        'redirect_uri' => 'https://YOUR-DOMAIN/ins/api/instagram/callback',
        'webhook_verify_token' => '',
        'webhook_app_secret' => '',
        'token_encryption_key' => '',
        'graph_base_url' => 'https://graph.instagram.com',
        'oauth_token_url' => 'https://api.instagram.com/oauth/access_token',
        'oauth_authorize_url' => 'https://api.instagram.com/oauth/authorize',
        'scopes' => 'instagram_basic,instagram_content_publish,pages_show_list',
        'refresh_before_days' => 7,
        'media_sync_limit' => 25,
        'media_sync_delay_ms' => 250,
        'media_insights_metrics' => 'views,reach,likes,comments,saved,shares,total_interactions',
        'insights_metrics' => 'reach,accounts_engaged,total_interactions,follower_count,profile_views',
        'insights_period' => 'day',
    ],
];
