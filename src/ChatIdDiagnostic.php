<?php

declare(strict_types=1);

final class ChatIdDiagnostic
{
    /** Return the source group ID only for the exact diagnostic command. */
    public static function target(array $update): ?string
    {
        $message = $update['message'] ?? null;
        if (!is_array($message)) {
            return null;
        }

        $type = (string) ($message['chat']['type'] ?? '');
        if (!in_array($type, ['group', 'supergroup'], true)) {
            return null;
        }

        $text = trim((string) ($message['text'] ?? ''));
        if (preg_match('~^/chatid(?:@accmeta_bot)?$~i', $text) !== 1) {
            return null;
        }

        $chatId = (string) ($message['chat']['id'] ?? '');
        return preg_match('/^-?[0-9]+$/', $chatId) === 1 ? $chatId : null;
    }
}
