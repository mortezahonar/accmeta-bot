<?php

declare(strict_types=1);

require __DIR__ . '/../src/StartCommand.php';

$valid = [
    '/start',
    '/start@Accmeta_bot',
    '/START@ACCMETA_BOT',
    '/start payload',
];
foreach ($valid as $text) {
    if (!StartCommand::matches($text)) {
        fwrite(STDERR, "FAIL: valid start command was rejected: {$text}\n");
        exit(1);
    }
}

$invalid = [
    '/started',
    '/start@OtherBot',
    '/help',
    'hello /start',
];
foreach ($invalid as $text) {
    if (StartCommand::matches($text)) {
        fwrite(STDERR, "FAIL: invalid start command was accepted: {$text}\n");
        exit(1);
    }
}

$privateReply = StartCommand::privateReply();
foreach (['گروه مقصد', 'مقالهٔ تخصصی حسابداری یا حسابرسی', '/sources'] as $required) {
    if (!str_contains($privateReply, $required)) {
        fwrite(STDERR, "FAIL: private start reply is missing {$required}.\n");
        exit(1);
    }
}

if (!str_contains($privateReply, 'گفت‌وگوی خصوصی پردازش نمی‌شوند')) {
    fwrite(STDERR, "FAIL: private start reply does not explain the private-chat limitation.\n");
    exit(1);
}

if (!str_contains($privateReply, '/sources')) {
    fwrite(STDERR, "FAIL: private start reply does not point to the official source catalog.\n");
    exit(1);
}

foreach (['/sources', '/sources@Accmeta_bot', '/SOURCES@ACCMETA_BOT'] as $text) {
    if (!StartCommand::sourcesMatches($text)) {
        fwrite(STDERR, "FAIL: valid sources command was rejected: {$text}\n");
        exit(1);
    }
}

foreach (['/sources extra', '/mysources', '/sources@OtherBot'] as $text) {
    if (StartCommand::sourcesMatches($text)) {
        fwrite(STDERR, "FAIL: invalid sources command was accepted: {$text}\n");
        exit(1);
    }
}

echo "OK: /start command matching and private help reply.\n";
