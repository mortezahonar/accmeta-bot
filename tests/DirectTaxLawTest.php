<?php

declare(strict_types=1);

require __DIR__ . '/../src/Http.php';
require __DIR__ . '/../src/Persian.php';
require __DIR__ . '/../src/DirectTaxLaw.php';

function lawAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$cases = [
    ['در میان متن م ۱ ق م م لطفاً بررسی شود', 1],
    ['م 42 ق م م', 42],
    ['در متن م ۴۲ ق م م آمده است', 42],
    ['در متن م ٤٢ ق م م آمده است', 42],
    ['تست ماده ۱۰ ق م م تست', 10],
    ['م 40 مکرر ق م م', 40],
    ['مادهٔ ۱۰ ق م م', 10],
    ['تست ماده ۱ ق م م تست', 1],
    ['ماده 186 ق م م', 186],
    ['م 0 ق م م', null],
    ['م 1001 ق م م', null],
    ['م 2 ق م م و م 3 ق م م', null],
    ['م 2 ق م م و ماده 3 ق م م', null],
    ['ماده 2 قانون مالیات‌های مستقیم', null],
    ['نامرتبط 42', null],
];
foreach ($cases as [$input, $expected]) {
    lawAssert(DirectTaxLaw::requestedArticle($input) === $expected, 'request parser mismatch: ' . $input);
}

$identifierCases = [
    ['در متن م ۲۰ ق م م', ['number' => 20, 'is_repeated' => false]],
    ['تست ماده 40 مکرر ق م م', ['number' => 40, 'is_repeated' => true]],
    ['مادهٔ ٤٠ مكرر ق م م', ['number' => 40, 'is_repeated' => true]],
    ['م 1 ق م م و م 2 مکرر ق م م', null],
];
foreach ($identifierCases as [$input, $expected]) {
    lawAssert(
        DirectTaxLaw::requestedArticleIdentifier($input) === $expected,
        'article identifier parser mismatch: ' . $input
    );
}

foreach (['creator', 'administrator'] as $status) {
    lawAssert(DirectTaxLaw::isAdministratorStatus($status), 'administrator role was rejected: ' . $status);
}
foreach (['member', 'restricted', 'left', 'kicked', ''] as $status) {
    lawAssert(!DirectTaxLaw::isAdministratorStatus($status), 'non-administrator role was accepted: ' . $status);
}

$fixture = <<<'HTML'
<!doctype html><html><body>
<h1>قانون مالياتهاي مستقيم</h1>
<section><h2>ماده ۱ ـ اشخاص مشمول</h2><p>متن کامل نخستین ماده.</p><p>بند دوم مادهٔ نخست.</p></section>
<section><h2>ماده 2 (اصلاحی) - متن بعدی</h2><p>متن کامل مادهٔ دوم.</p></section>
</body></html>
HTML;

$temporary = sys_get_temp_dir() . '/accmeta-direct-tax-test-' . bin2hex(random_bytes(5));
$fetchCount = 0;
$repository = new DirectTaxLaw($temporary, static function (string $url) use (&$fetchCount, $fixture): array {
    $fetchCount++;
    if ($url !== DirectTaxLaw::SOURCE_URL) {
        throw new RuntimeException('Unexpected source URL.');
    }
    return ['status' => 200, 'body' => $fixture];
});

$article = $repository->getArticle(2);
lawAssert(is_array($article), 'article 2 was not extracted');
lawAssert(str_contains($article['text'], 'ماده 2 (اصلاحی) - متن بعدی'), 'article heading was not retained');
lawAssert(str_contains($article['text'], 'متن کامل مادهٔ دوم.'), 'article body was not retained');
lawAssert(!str_contains($article['text'], 'متن کامل نخستین ماده'), 'article extraction crossed into the next section');
lawAssert($fetchCount === 1, 'source should be requested once on cache miss');
lawAssert(is_file($temporary . '/article-2.json'), 'extracted article was not cached locally');

$offlineRepository = new DirectTaxLaw($temporary, static function (): array {
    throw new RuntimeException('Network must not be used for cached article.');
});
$cached = $offlineRepository->getArticle(2);
lawAssert(is_array($cached) && $cached['text'] === $article['text'], 'cached lookup did not work without network');

$snapshotRoot = sys_get_temp_dir() . '/accmeta-direct-tax-snapshot-test-' . bin2hex(random_bytes(5));
mkdir($snapshotRoot . '/articles', 0775, true);
$officialSnapshot = __DIR__ . '/../data/laws/direct-taxes/source.md';
lawAssert(is_file($officialSnapshot), 'نسخهٔ رسمی ذخیره‌شده در مخزن پیدا نشد');
lawAssert(copy($officialSnapshot, $snapshotRoot . '/source.md'), 'نسخهٔ رسمی برای آزمون کپی نشد');
$snapshotFetchCount = 0;
$snapshotRepository = new DirectTaxLaw($snapshotRoot . '/articles', static function () use (&$snapshotFetchCount): array {
    $snapshotFetchCount++;
    throw new RuntimeException('برای مادهٔ موجود در نسخهٔ ذخیره‌شده نباید شبکه فراخوانی شود.');
});

foreach ([18, 19, 20, 30] as $number) {
    $snapshotArticle = $snapshotRepository->getArticle($number);
    lawAssert(is_array($snapshotArticle), 'مادهٔ ' . $number . ' از نسخهٔ رسمی ذخیره‌شده استخراج نشد');
    lawAssert(str_starts_with($snapshotArticle['text'], 'ماده'), 'سرعنوان مادهٔ ' . $number . ' در پاسخ نیست');
    lawAssert(is_file($snapshotRoot . '/articles/article-' . $number . '.json'), 'مادهٔ ' . $number . ' در cache محلی ثبت نشد');
    lawAssert($snapshotArticle['retrieved_at'] === '2026-11-06', 'تاریخ نسخهٔ رسمی برای مادهٔ ' . $number . ' نادرست است');
}
lawAssert(str_contains($snapshotRepository->getArticle(19)['text'], 'منسوخه'), 'وضعیت منسوخ مادهٔ ۱۹ حفظ نشد');
lawAssert(str_contains($snapshotRepository->getArticle(20)['text'], 'منسوخه'), 'وضعیت منسوخ مادهٔ ۲۰ حفظ نشد');
lawAssert(str_contains($snapshotRepository->getArticle(30)['text'], 'منسوخه'), 'وضعیت منسوخ مادهٔ ۳۰ حفظ نشد');
lawAssert($snapshotFetchCount === 0, 'برای مواد موجود در snapshot نباید درخواست شبکه شود');

$article3 = $snapshotRepository->getArticle(3);
lawAssert(is_array($article3), 'مادهٔ ۳ دارای چند سرعنوان باید بدون خطای دسترسی استخراج شود');
lawAssert(str_contains($article3['text'], 'منسوخه'), 'وضعیت فصل منسوخ مادهٔ ۳ حفظ نشد');
lawAssert(str_contains($article3['text'], 'چند سرعنوان'), 'ابهام نسخه‌های مادهٔ ۳ به کاربر اعلام نمی‌شود');

$article40 = $snapshotRepository->getArticle(40);
lawAssert(is_array($article40), 'مادهٔ ۴۰ دارای چند سرعنوان باید قابل دریافت باشد');
lawAssert(str_contains($article40['text'], 'منسوخه'), 'برچسب منسوخی مادهٔ ۴۰ حفظ نشد');
$article40TextNormalized = str_replace(['ي', 'ك'], ['ی', 'ک'], $article40['text']);
lawAssert(str_contains($article40TextNormalized, 'اصلاحی'), 'برچسب اصلاح مادهٔ ۴۰ حفظ نشد');
lawAssert(str_contains($article40['text'], 'چند سرعنوان'), 'چند نسخهٔ مادهٔ ۴۰ جداگانه اعلام نشد');

$repeated40 = $snapshotRepository->getArticle(40, true);
lawAssert(is_array($repeated40), 'مادهٔ ۴۰ مکرر از مادهٔ ۴۰ پایه جدا نشده است');
lawAssert($repeated40['is_repeated'], 'شناسهٔ مادهٔ مکرر در cache ثبت نشد');
$repeated40TextNormalized = str_replace(['ي', 'ك'], ['ی', 'ک'], $repeated40['text']);
lawAssert(str_contains($repeated40TextNormalized, 'ماده 40 مکرر'), 'سرعنوان مادهٔ ۴۰ مکرر حفظ نشد');
lawAssert(is_file($snapshotRoot . '/articles/article-40-mokarrar.json'), 'cache مادهٔ مکرر جداگانه ساخته نشد');

$base251 = $snapshotRepository->getArticle(251);
lawAssert(is_array($base251), 'مادهٔ پایهٔ ۲۵۱ از مادهٔ ۲۵۱ مکرر جدا نشده است');
$repeated251 = $snapshotRepository->getArticle(251, true);
lawAssert(is_array($repeated251), 'مادهٔ ۲۵۱ مکرر در نسخهٔ رسمی پیدا نشد');
lawAssert(is_file($snapshotRoot . '/articles/article-251.json'), 'cache مادهٔ پایهٔ ۲۵۱ ساخته نشد');
lawAssert(is_file($snapshotRoot . '/articles/article-251-mokarrar.json'), 'cache مادهٔ ۲۵۱ مکرر جداگانه ساخته نشد');
$missing98 = $snapshotRepository->getArticle(98);
lawAssert($missing98 === null, 'شمارهٔ بدون سرعنوان نباید با متن ساختگی یا دریافت زنده پاسخ داده شود');
lawAssert($snapshotFetchCount === 0, 'برای سرعنوان ناموجود در snapshot نباید منبع زنده فراخوانی شود');

lawAssert($snapshotFetchCount === 0, 'استخراج نسخه‌های رسمی نباید درخواست شبکه ایجاد کند');

unlink($snapshotRoot . '/source.md');
$snapshotCacheHit = $snapshotRepository->getArticle(18);
lawAssert(is_array($snapshotCacheHit) && $snapshotCacheHit['article_number'] === 18, 'پس از cache باید پاسخ بدون نسخهٔ منبع محلی هم کار کند');
foreach (glob($snapshotRoot . '/articles/*') ?: [] as $file) {
    @unlink($file);
}
@rmdir($snapshotRoot . '/articles');
@rmdir($snapshotRoot);

$cacheOnlyRoot = sys_get_temp_dir() . '/accmeta-direct-tax-cache-only-' . bin2hex(random_bytes(5));
mkdir($cacheOnlyRoot . '/articles', 0775, true);
$productionArticleFiles = glob(__DIR__ . '/../data/laws/direct-taxes/articles/article-*.json') ?: [];
foreach ($productionArticleFiles as $file) {
    lawAssert(copy($file, $cacheOnlyRoot . '/articles/' . basename($file)), 'فایل cache برای آزمون کپی نشد');
}
$cacheOnlyFetchCount = 0;
$cacheOnlyRepository = new DirectTaxLaw($cacheOnlyRoot . '/articles', static function () use (&$cacheOnlyFetchCount): array {
    $cacheOnlyFetchCount++;
    throw new RuntimeException('برای cache آماده‌شده نباید شبکه فراخوانی شود.');
});
foreach ($productionArticleFiles as $file) {
    if (preg_match('/^article-([0-9]+)(-mokarrar)?\.json$/', basename($file), $match) !== 1) {
        lawAssert(false, 'نام یکی از فایل‌های cache با الگوی شناسهٔ قانون هماهنگ نیست');
    }
    $number = (int) $match[1];
    $isRepeated = !empty($match[2]);
    $cachedRecord = $cacheOnlyRepository->getArticle($number, $isRepeated);
    lawAssert(is_array($cachedRecord), 'فایل cache مادهٔ ' . $number . ' قابل خواندن نیست');
    lawAssert($cachedRecord['is_repeated'] === $isRepeated, 'نوع شناسه در فایل cache نادرست است');
}
lawAssert(count($productionArticleFiles) === 286, 'آرشیو باید ۲۷۸ مادهٔ پایه و ۸ شناسهٔ مکرر داشته باشد');
lawAssert($cacheOnlyFetchCount === 0, 'خواندن cache آماده‌شده نباید به شبکه وابسته باشد');
foreach (glob($cacheOnlyRoot . '/articles/*') ?: [] as $file) {
    @unlink($file);
}
@rmdir($cacheOnlyRoot . '/articles');
@rmdir($cacheOnlyRoot);

$blockedDirectory = $temporary . '-blocked';
$blockedRepository = new DirectTaxLaw($blockedDirectory, static fn (string $url): array => [
    'status' => 200,
    'body' => '<html><title>The URL you requested has been blocked</title><body>#DOMAIN# دسترسی شما به این بخش مسدود شد</body></html>',
]);
$blocked = false;
try {
    $blockedRepository->getArticle(4);
} catch (RuntimeException) {
    $blocked = true;
}
lawAssert($blocked, 'challenge page was not rejected');
lawAssert(!is_file($blockedDirectory . '/article-4.json'), 'blocked response must never be cached');

$chunks = DirectTaxLaw::splitForTelegram(str_repeat('قانون و مسئولیت؛ 👩🏽‍💻\n', 500), 300);
lawAssert(count($chunks) > 1, 'long law text was not split');
foreach ($chunks as $chunk) {
    $utf16Units = intdiv(strlen(mb_convert_encoding($chunk, 'UTF-16LE', 'UTF-8')), 2);
    lawAssert($utf16Units <= 300, 'message chunk exceeds the UTF-16 limit');
}
lawAssert(implode('', $chunks) === str_repeat('قانون و مسئولیت؛ 👩🏽‍💻\n', 500), 'splitting dropped or changed legal text');

foreach (glob($temporary . '*') ?: [] as $path) {
    if (is_dir($path)) {
        foreach (glob($path . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($path);
    } else {
        @unlink($path);
    }
}

echo "OK: direct-tax request parsing, source validation, offline cache, and Telegram-safe splitting.\n";
