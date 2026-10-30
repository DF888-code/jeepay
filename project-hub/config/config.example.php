<?php

/**
 * 複製本檔為 config.php 後修改。config.php 含機密，不可加入版控、不可分享。
 *
 * 若尚未建立 config.php，直接開啟網站會顯示設定說明頁，並提供可複製的隨機 app_key 與 install_token。
 */
return [
    // 網站網址（寄送信件與 HTTPS 轉址使用），結尾不加斜線
    'base_url' => 'https://book888.free.je',

    // 正式環境務必為 true：所有 http 請求會轉址到 https
    'force_https' => true,

    // 32 bytes 隨機值的 Base64。用來加密 2FA 金鑰，設定後不可更改或遺失。
    // 產生方式：開啟網站的設定說明頁複製，或執行 php -r "echo base64_encode(random_bytes(32));"
    'app_key' => '',

    // 首次安裝時必須輸入的金鑰（至少 32 字元），避免陌生人搶先建立管理者。安裝完成後可清空。
    'install_token' => '',

    // InfinityFree 控制台「MySQL Databases」頁面上的資料
    'db' => [
        'host' => '',      // 例如 sqlXXX.infinityfree.com
        'port' => 3306,
        'name' => '',      // 例如 if0_xxxxxxxx_projecthub
        'user' => '',      // 例如 if0_xxxxxxxx
        'pass' => '',
    ],

    // Session Cookie 名稱
    'session_cookie' => 'phsid',
];
