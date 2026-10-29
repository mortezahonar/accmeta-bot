<?php

declare(strict_types=1);

require __DIR__ . '/../src/ChatIdDiagnostic.php';

$valid = [
    'message' => [
        'text' => '/chatid@Accmeta_bot',
        'chat' => ['id' => -1001234567890, 'type' => 'supergroup'],
    ],
];
if (ChatIdDiagnostic::target($valid) !== '-1001234567890') {
    fwrite(STDERR, "FAIL: valid group diagnostic command was not recognized.\n");
    exit(1);
}

$private = ['message' => ['text' => '/chatid', 'chat' => ['id' => 42, 'type' => 'private']]];
$wrongCommand = ['message' => ['text' => '/start', 'chat' => ['id' => -1001234567890, 'type' => 'supergroup']]];
$withArguments = ['message' => ['text' => '/chatid extra', 'chat' => ['id' => -1001234567890, 'type' => 'supergroup']]];
foreach ([$private, $wrongCommand, $withArguments] as $invalid) {
    if (ChatIdDiagnostic::target($invalid) !== null) {
        fwrite(STDERR, "FAIL: invalid diagnostic input was accepted.\n");
        exit(1);
    }
}

echo "OK: chat ID diagnostic only recognizes exact commands in groups.\n";
