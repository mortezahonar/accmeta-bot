<?php

declare(strict_types=1);

final class StartCommand
{
    public static function matches(string $text): bool
    {
        return preg_match('#^/start(?:@Accmeta_bot)?(?:\s+.*)?$#iu', trim($text)) === 1;
    }

    public static function sourcesMatches(string $text): bool
    {
        return preg_match('#^/sources(?:@Accmeta_bot)?$#iu', trim($text)) === 1;
    }

    public static function privateReply(): string
    {
        return 'سلام، به ربات ترجمهٔ مقالات حسابداری و حسابرسی خوش آمدید.' . "\n\n"
            . 'برای دریافت ترجمه، لینک مقالهٔ تخصصی حسابداری یا حسابرسی را در گروه مقصد بفرستید؛ پیام‌های گفت‌وگوی خصوصی پردازش نمی‌شوند.' . "\n\n"
            . 'برای دیدن فهرست مرتب‌شدهٔ منابع مجاز و نشانی رسمی آن‌ها، در گروه /sources را بفرستید یا دکمهٔ «فهرست منابع رسمی» را بزنید.';
    }
}
