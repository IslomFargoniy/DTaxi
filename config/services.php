<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // O'z serverimizdagi xizmatlar (firmaga xos emas — har serverda bir xil manzil)
    'osrm' => [
        'url' => env('OSRM_URL', 'http://127.0.0.1:5000'),
        // Marshrut ma'lumoti (deploy/osrm-prepare.sh) — xarita to'plamiga (ibtaxi:map-export) kiradi
        'data_dir' => env('OSRM_DIR', '/var/lib/ibtaxi/osrm'),
    ],

    'tiles' => [
        'url' => env('TILES_URL', '/map/region.pmtiles'),
    ],

    // Asterisk (ARI) — shu serverda, firmaga xos emas
    'asterisk' => [
        'ari_url' => env('ASTERISK_ARI_URL', 'http://127.0.0.1:8088'),
        'ari_user' => env('ASTERISK_ARI_USER', 'ibtaxi'),
        'ari_password' => env('ASTERISK_ARI_PASSWORD'),
        'app' => 'ibtaxi',
        'dtls_cert' => env('ASTERISK_DTLS_CERT', '/etc/asterisk/keys/asterisk.pem'),
        // Asterisk ko'prik yozuvlarini shu yerga yozadi; job MP3 ga o'girib storage'ga ko'chiradi
        'recordings_dir' => env('ASTERISK_RECORDINGS_DIR', '/var/spool/asterisk/recording'),
    ],

    // WebRTC ICE: STUN (Asterisk rtp.conf dagi bilan bir xil) va ixtiyoriy TURN (install.sh --turn, coturn shu serverda)
    'webrtc' => [
        'stun' => env('STUN_URL', 'stun:stun.l.google.com:19302'),
        // Vergul bilan: turn:<domen>:3478?transport=udp,turn:<domen>:3478?transport=tcp,turns:<domen>:5349?transport=tcp
        'turn_url' => env('TURN_URL'),
        // coturn static-auth-secret — vaqtinchalik login/parol shu bilan imzolanadi (TURN REST API)
        'turn_secret' => env('TURN_SECRET'),
        'turn_ttl' => (int) env('TURN_TTL', 12 * 3600),
    ],

];
