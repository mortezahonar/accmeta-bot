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
foreach (['گروه مقصد', 'مقالهٔ تخصصی حسابداری یا حسابرسی', 'Journal of Accountancy', 'IFRS Foundation', 'IAASB', 'INTHEBLACK'] as $required) {
    if (!str_contains($privateReply, $required)) {
        fwrite(STDERR, "FAIL: private start reply is missing {$required}.\n");
        exit(1);
    }
}

if (!str_contains($privateReply, 'گفت‌وگوی خصوصی پردازش نمی‌شوند')) {
    fwrite(STDERR, "FAIL: private start reply does not explain the private-chat limitation.\n");
    exit(1);
}

echo "OK: /start command matching and private help reply.\n";
