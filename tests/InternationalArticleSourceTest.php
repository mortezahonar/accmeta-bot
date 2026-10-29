<?php

declare(strict_types=1);

require __DIR__ . '/../src/Http.php';
require __DIR__ . '/../src/ArticleSource.php';
require __DIR__ . '/../src/InternationalArticleParser.php';
require __DIR__ . '/../src/InternationalSource.php';
require __DIR__ . '/../src/ArticleSourceRegistry.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$fixtures = [
    'journalofaccountancy.com' => <<<'HTML'
<!doctype html><html><head>
<meta property="og:title" content="PCAOB inspection results">
<meta name="author" content="By Alex Example">
<meta property="article:published_time" content="2026-10-23T11:58:12-04:00">
<meta property="og:image" content="https://www.journalofaccountancy.com/images/lead.jpg">
</head><body><h1>PCAOB inspection results</h1>
<article><div class="entry-content">
<p>The PCAOB reported a meaningful improvement in inspection findings across several audit firm groups.</p>
<p>The update describes audit evidence, engagement quality and current inspection trends in detail.</p>
<figure><img src="/images/chart.jpg" alt="Inspection chart"><figcaption>Inspection results</figcaption></figure>
</div><aside class="related">Unrelated links</aside></article></body></html>
HTML,
    'ifrs.org' => <<<'HTML'
<!doctype html><html><head><meta property="og:title" content="IFRS news summary"></head><body>
<div class="cmp-text" id="cookie-modal"><h2>Your privacy</h2><p>Cookie preferences and privacy information.</p></div>
<h1>April 2024 monthly news summary</h1>
<div class="header-title-wrap">01 May 2024 April 2024 monthly news summary</div>
<div class="cmp-text" id="richtext-article"><p>This public update summarizes news and events from the IFRS Foundation during the month.</p>
<h2>Standard-setting update</h2><p>The Foundation described work on accounting standards, implementation support and public consultations for stakeholders.</p></div>
</body></html>
HTML,
    'iaasb.org' => <<<'HTML'
<!doctype html><html><head><meta property="og:title" content="IAASB audit guidance"></head><body>
<main class="main-content"><h1>IAASB audit guidance</h1><time datetime="2023-12-08">Dec 8, 2023</time>
<div class="news__content-text"><p>The International Auditing and Assurance Standards Board issued guidance for assurance practitioners.</p>
<p>The article explains how practitioners should cite IFRS Accounting Standards in relevant reports.</p></div></main></body></html>
HTML,
    'intheblack.cpaaustralia.com.au' => <<<'HTML'
<!doctype html><html><head><meta name="author" content="Nicola Heath"><meta property="og:image" content="/images/cover.jpg"></head><body>
<div id="main-content"><h1>Building career resilience</h1><time datetime="2026-08-03">3 Aug 2026</time>
<div class="content-rich-text"><p>Accounting professionals need to develop curiosity and adaptability as regulations and technology continue to change.</p></div>
<div class="content-rich-text"><h2>Ways to practise</h2><p>Continual professional learning helps accountants respond to new client needs and emerging risks.</p><a href="https://example.org/learning">More information</a></div>
</div></body></html>
HTML,
];

$urls = [
    'joa' => 'https://www.journalofaccountancy.com/news/2026/oct/pcaob-inspection-results/',
    'ifrs' => 'https://www.ifrs.org/news-and-events/news/2024/05/april-2024-monthly-news-summary/',
    'iaasb' => 'https://www.iaasb.org/news-events/2023-12/iaasb-audit-guidance',
    'intheblack' => 'https://intheblack.cpaaustralia.com.au/careers-and-workplace/building-career-resilience',
];

$requests = [];
$source = new InternationalSource(static function (string $url, int $timeout) use (&$requests, $fixtures): array {
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    $host = str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    $requests[] = [$url, $timeout];
    return ['status' => 200, 'body' => $fixtures[$host] ?? ''];
});
$registry = new ArticleSourceRegistry($source);

$assert($source->supportsUrl($urls['joa']), 'Journal of Accountancy article routes should be supported');
$assert($source->supportsUrl($urls['ifrs']), 'IFRS Foundation news routes should be supported');
$assert($source->supportsUrl($urls['iaasb']), 'IAASB news routes should be supported');
$assert($source->supportsUrl($urls['intheblack']), 'INTHEBLACK article routes should be supported');
$assert(!$source->supportsUrl('https://journalofaccountancy.com.evil.example/news/2026/oct/fake'), 'lookalike hosts must be rejected');
$assert(!$source->supportsUrl('https://example.org/news/2026/article'), 'unlisted hosts must be rejected');
$assert(!$source->supportsUrl('https://www.ifrs.org/news-and-events/'), 'listing pages must not be treated as articles');
$assert(!$source->supportsUrl('https://www.journalofaccountancy.com/topics/auditing/'), 'category pages must not be treated as articles');
$assert(!$source->supportsUrl('https://user:pass@www.ifrs.org/news-and-events/news/2024/05/article/'), 'credential-bearing URLs must be rejected');

$assert($source->topicKey($urls['joa']) === $source->topicKey($urls['joa'] . '?utm_source=test'), 'tracking query strings must not change the topic key');
$assert($source->topicKey($urls['joa']) !== $source->topicKey($urls['ifrs']), 'topic keys must be namespaced by source');
$assert($registry->findLink('Read https://example.org/nope then https://www.iaasb.org/news-events/2023-12/iaasb-audit-guidance.') === $urls['iaasb'], 'link extraction should skip unsupported URLs and trim final punctuation');

$expectedTitles = [
    'joa' => 'PCAOB inspection results',
    'ifrs' => 'April 2024 monthly news summary',
    'iaasb' => 'IAASB audit guidance',
    'intheblack' => 'Building career resilience',
];
foreach ($urls as $key => $url) {
    $article = $source->fetch($url);
    $assert($article['title'] === $expectedTitles[$key], $key . ' title should be extracted from the public article HTML');
    $assert($article['body_empty'] === false && count($article['blocks']) > 0, $key . ' article text blocks should be extracted');
    $plain = '';
    foreach ($article['blocks'] as $block) {
        if (($block['type'] ?? '') === 'text') {
            $plain .= strip_tags((string) ($block['html'] ?? '')) . ' ';
        }
    }
    $assert(strlen(trim($plain)) > 80, $key . ' body text should be long enough to translate');
}

$joa = $source->fetch($urls['joa']);
$assert($joa['author'] === 'Alex Example', 'author metadata should strip the conventional By prefix');
$assert($joa['published'] === '2026-10-23T11:58:12-04:00', 'article publication timestamp should be preserved');
$assert($joa['lead_image'] === 'https://www.journalofaccountancy.com/images/lead.jpg', 'OpenGraph lead image should be retained');
$assert(in_array('Inspection chart', array_column($joa['blocks'], 'alt'), true), 'figure alt text should be preserved');
$assert($source->resolveUrl('../images/chart.jpg', $urls['iaasb']) === 'https://www.iaasb.org/news-events/images/chart.jpg', 'relative image URLs should resolve against the article route');
$assert($source->resolveUrl('//cdn.example.org/image.png', $urls['iaasb']) === 'https://cdn.example.org/image.png', 'protocol-relative image URLs should become HTTPS URLs');
$assert($requests[0] === [$urls['joa'], 90], 'the adapter should fetch validated pages with the standard timeout');

try {
    $restricted = new InternationalSource(static fn (string $url, int $timeout): array => [
        'status' => 200,
        'body' => '<html><h1>Restricted article</h1><div class="entry-content"><p>Create a free account to continue reading this article. This page requires an account for further access.</p></div></html>',
    ]);
    $restricted->fetch($urls['joa']);
    $assert(false, 'pages that require an account should not be translated');
} catch (RuntimeException $e) {
    $assert(str_contains($e->getMessage(), 'حساب کاربری'), 'account-gated pages should return a clear access restriction error');
}

try {
    (new InternationalSource(static fn (string $url, int $timeout): array => ['status' => 503, 'body' => '']))->fetch($urls['joa']);
    $assert(false, 'non-200 HTTP responses should be rejected');
} catch (RuntimeException $e) {
    $assert(str_contains($e->getMessage(), 'HTTP 503'), 'HTTP status failures should identify the returned status');
}

fwrite(STDOUT, "OK: international source allowlist, parsing, metadata, images, query-stable topics and access checks passed.\n");
