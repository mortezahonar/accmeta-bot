<?php

declare(strict_types=1);

require __DIR__ . '/../src/Persian.php';
require __DIR__ . '/../src/LocalLawArchive.php';

function localLawAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function removeLocalLawTestDirectory(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $entry) {
        $path = $directory . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($path)) {
            removeLocalLawTestDirectory($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($directory);
}

$repositoryRoot = dirname(__DIR__);
$sourceRoot = $repositoryRoot . '/data/laws';
$metadata = LocalLawArchive::laws();
$expectedAvailable = [
    'value-added-tax' => 57,
    'social-security' => 117,
];
$temporaryRoot = sys_get_temp_dir() . '/accmeta-law-snapshot-' . bin2hex(random_bytes(5));
$cacheOnlyRoot = sys_get_temp_dir() . '/accmeta-law-cache-only-' . bin2hex(random_bytes(5));

try {
    foreach ($metadata as $law => $details) {
        $productionSnapshot = $sourceRoot . '/' . $law . '/source.md';
        localLawAssert(is_file($productionSnapshot), 'official snapshot missing: ' . $law);
        $temporaryLawDirectory = $temporaryRoot . '/' . $law;
        if (!is_dir($temporaryLawDirectory)) {
            mkdir($temporaryLawDirectory, 0775, true);
        }
        localLawAssert(copy($productionSnapshot, $temporaryLawDirectory . '/source.md'), 'official snapshot could not be copied: ' . $law);
    }

    $snapshotArchive = new LocalLawArchive($temporaryRoot);
    foreach ($metadata as $law => $details) {
        $found = 0;
        $missing = [];
        for ($number = 1; $number <= $details['max_article']; $number++) {
            $article = $snapshotArchive->getArticle($law, $number);
            if ($law === 'social-security' && $number === 63) {
                localLawAssert($article === null, 'Social Security article 63 must not be invented from its table-of-contents entry');
                continue;
            }
            localLawAssert(is_array($article), 'official body article was not extracted: ' . $law . ' ' . $number);
            localLawAssert($article['law'] === $law, 'law slug mismatch in extracted article');
            localLawAssert($article['article_number'] === $number, 'article number mismatch in extracted article');
            localLawAssert($article['is_repeated'] === false, 'unexpected repeated identifier in current law archive');
            localLawAssert($article['source_url'] === $details['source_url'], 'official source URL mismatch');
            localLawAssert($article['retrieved_at'] === $details['retrieved_at'], 'snapshot retrieval date mismatch');
            localLawAssert(mb_strlen($article['text'], 'UTF-8') >= 12, 'article body is too short to be a legal text');
            localLawAssert(preg_match('/\[[^\]]+\]\([^)]+\)/u', $article['text']) !== 1, 'Markdown link leaked into article text');
            localLawAssert(is_file($temporaryRoot . '/' . $law . '/articles/article-' . $number . '.json'), 'article was not cached after local extraction');
            $found++;
        }
        localLawAssert($found === $expectedAvailable[$law], 'available body-article count mismatch: ' . $law);
    }

    localLawAssert(!is_file($temporaryRoot . '/social-security/articles/article-63.json'), 'missing source text must not create an article cache');

    foreach ($metadata as $law => $details) {
        $productionArticles = glob($sourceRoot . '/' . $law . '/articles/article-*.json') ?: [];
        localLawAssert(count($productionArticles) === $expectedAvailable[$law], 'prebuilt article cache count mismatch: ' . $law);
        foreach ($productionArticles as $file) {
            $destinationDirectory = $cacheOnlyRoot . '/' . $law . '/articles';
            if (!is_dir($destinationDirectory)) {
                mkdir($destinationDirectory, 0775, true);
            }
            localLawAssert(copy($file, $destinationDirectory . '/' . basename($file)), 'production article cache could not be copied');
        }
    }

    $cacheOnlyArchive = new LocalLawArchive($cacheOnlyRoot);
    foreach ($metadata as $law => $details) {
        for ($number = 1; $number <= $details['max_article']; $number++) {
            if ($law === 'social-security' && $number === 63) {
                continue;
            }
            $article = $cacheOnlyArchive->getArticle($law, $number);
            localLawAssert(is_array($article), 'prebuilt cache was not readable without source.md: ' . $law . ' ' . $number);
        }
    }
} finally {
    removeLocalLawTestDirectory($temporaryRoot);
    removeLocalLawTestDirectory($cacheOnlyRoot);
}

fwrite(STDOUT, "OK: 174 official body articles, no table-of-contents leakage, source metadata, and cache-only lookup.\n");
