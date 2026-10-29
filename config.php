<?php

declare(strict_types=1);

/*
 * Runtime configuration. Secrets are loaded from environment variables first,
 * with an optional local secrets.php file for development only.
 */
$allowedUsers = preg_split('/[\s,]+/', trim((string) (getenv('ACCMETA_ALLOWED_USER_IDS') ?: '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];

$config = [
    'bot_token' => (string) (getenv('ACCMETA_BOT_TOKEN') ?: ''),
    'chat_id' => (string) (getenv('ACCMETA_CHAT_ID') ?: ''),
    'gemini_api_key' => (string) (getenv('GEMINI_API_KEY') ?: ''),
    'gemini_model' => (string) (getenv('GEMINI_MODEL') ?: 'gemini-3.5-flash,gemini-3.1-flash-lite'),
    'fallback_api_key' => (string) (getenv('FALLBACK_API_KEY') ?: ''),
    'fallback_base_url' => (string) (getenv('FALLBACK_BASE_URL') ?: 'https://tokenharbor.ai/v1'),
    'fallback_model' => (string) (getenv('FALLBACK_MODEL') ?: 'deepseek-v4.1-flash:free'),
    'proxy' => (string) (getenv('ACCMETA_BOT_PROXY') ?: ''),
    'state_dir' => __DIR__ . '/state',
    'glossary_path' => (string) (getenv('ACCMETA_GLOSSARY') ?: (__DIR__ . '/data/accounting-glossary.md')),
    'allowed_user_ids' => $allowedUsers,
];

if (is_file(__DIR__ . '/secrets.php')) {
    $local = require __DIR__ . '/secrets.php';
    if (is_array($local)) {
        $config = array_replace($config, $local);
    }
}

return $config;
