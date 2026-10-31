<?php

declare(strict_types=1);

require __DIR__ . '/../src/Http.php';
require __DIR__ . '/../src/ArticleSource.php';
require __DIR__ . '/../src/InternationalArticleParser.php';
require __DIR__ . '/../src/InternationalSource.php';
require __DIR__ . '/../src/ArticleSourceRegistry.php';
require __DIR__ . '/../src/SourceCatalog.php';
require __DIR__ . '/../src/Persian.php';

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
<div class="jss-content-main"><h1>Building career resilience</h1><time datetime="2026-08-03">3 Aug 2026</time>
<h4>At a glance</h4><div class="content-summary"><p>Accounting professionals need to develop curiosity and adaptability as regulations and technology continue to change.</p></div>
<div class="media-embed"><video-js class="video-js bc-video" data-account="6055873642001" data-player="giLxnTq5x" data-embed="default" data-video-id="6405939953112"></video-js></div>
<div class="content-rich-text"><div><p>The first article in this series explains accounting professionals' approach to new technology and includes a link to <a href="/technical-skills/excel-tips-python-part-1">part 1</a>.</p></div></div>
<div class="single-image"><figure><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==" data-src="/images/figure-one.jpg" alt="Figure one"><figcaption>First figure</figcaption></figure></div>
<div class="content-rich-text"><div><p>Further guidance and practical resources help accountants build skills. See the <a href="https://www.cpaaustralia.com.au/professional-development/courses-and-events">course list</a> or download the <a href="/files/example.xlsx">Excel file</a>.</p></div>
<figure><img src="data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==" data-srcset="/images/figure-two-small.jpg 320w, /images/figure-two-large.jpg 1000w" alt="Figure two"><figcaption>Second figure</figcaption><img src="/images/figure-three.jpg" alt="Figure three"></figure></div>
<div class="media-embed"><iframe src="https://play.libsyn.com/embed/episode/id/12345"></iframe></div>
<div class="accordion"><p>Long podcast transcript that must not be included as article body text.</p></div>
<div class="call-to-action"><p>Professional development: <a href="/professional-development/courses-and-events">Explore courses</a>.</p></div>
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

$verifiedRoutes = [
    'jae' => 'https://www.sciencedirect.com/science/article/pii/S0165410126000443',
    'jar' => 'https://onlinelibrary.wiley.com/doi/full/10.1111/1475-679X.12545',
    'car' => 'https://onlinelibrary.wiley.com/doi/abs/10.1111/1911-3846.12779',
    'rast' => 'https://link.springer.com/article/10.1007/s11142-022-09743-8',
    'ajpt' => 'https://publications.aaahq.org/ajpt/article/40/2/107/6192/eXtensible-Business-Reporting-Language-XBRL-A',
    'maj' => 'https://www.emerald.com/maj/article/40/6/761/1265591/Confucian-culture-and-the-choice-of-high-quality',
    'ija' => 'https://onlinelibrary.wiley.com/doi/full/10.1111/ijau.12348',
    'ifrs' => 'https://www.ifrs.org/news-and-events/news/2024/04/new-ifrs-accounting-standard-will-aid-investor-analysis-of-companies-financial-performance/',
    'iaasb' => 'https://www.iaasb.org/news-events/2023-12/iaasb-issues-guidance-assurance-practitioners-when-citing-ifrs-accounting-standards',
    'fasb' => 'https://www.fasb.org/news-and-meetings/in-the-news/fasb-issues-standard-on-codification-improvements-423461',
    'pcaob' => 'https://pcaobus.org/news-events/news-releases/news-release-detail/pcaob-updates-standard-setting-research-rulemaking-agendas-after-first-ever-public-comment-period',
    'iia' => 'https://www.theiia.org/en/content/articles/global-best-practices/2026/internal-audit-upskilling-for-critical-ai-capabilities/',
    'joa' => 'https://www.journalofaccountancy.com/news/2023/nov/how-artificial-intelligence-can-help-save-accounting/',
    'intheblack' => 'https://intheblack.cpaaustralia.com.au/careers-and-workplace/capability-accountants-need-to-build-career-resilience',
    'ima' => 'https://www.imatoday.org/learn/article/budgeting-for-agentic-ai',
    'cpajournal' => 'https://www.cpajournal.com/2026/09/02/evaluating-the-use-of-ai-in-financial-statement-audits/',
    'acctoday' => 'https://www.accountingtoday.com/list/accounting-in-2026-the-year-ahead-in-numbers',
    'aos' => 'https://www.sciencedirect.com/science/article/pii/S0361368200000039',
    'japp' => 'https://www.sciencedirect.com/science/article/pii/S0278425422000667',
];
$catalog = InternationalSource::catalog();
$assert(count($catalog) === 19, 'the catalog should retain 17 requested sources plus the existing IAASB and INTHEBLACK sources');
foreach ($catalog as $item) {
    $assert(str_starts_with($item['homepage'], 'https://'), $item['key'] . ' catalog entry should have an HTTPS official homepage');
    $assert(trim($item['description']) !== '', $item['key'] . ' catalog entry should have a short description');
}
foreach ($verifiedRoutes as $key => $url) {
    $assert($source->supportsUrl($url), $key . ' verified article route should be allowlisted');
}
$abstractKeys = array_column(array_filter($catalog, static fn (array $item): bool => $item['mode'] === 'abstract'), 'key');
$assert(count($abstractKeys) === 9, 'the nine remaining academic journal sources should be marked abstract-only');
$assert(!$source->supportsUrl('https://publications.aaahq.org/accounting-review/article/100/6/405/13963/Insights-from-100-Years-of-The-Accounting-Review'), 'TAR must be rejected because its article page blocks access with a CAPTCHA');
$assert(!$source->supportsUrl('https://www.sciencedirect.com/journal/accounting-organizations-and-society'), 'publisher journal listings must not be accepted as article pages');
$assert(!$source->supportsUrl('https://onlinelibrary.wiley.com/journal/1475679x'), 'Wiley journal homepages must not be accepted as article pages');
$assert(!$source->supportsUrl('https://www.theiia.org/en/standards/2024-standards/global-internal-audit-standards/'), 'standards landing pages must not be mistaken for article pages');
$catalogMessages = SourceCatalog::messages();
$assert(count($catalogMessages) === 5, 'official sources should be grouped into five mobile-sized categories');
$catalogMessageText = implode("\n", $catalogMessages);
$assert(!str_contains($catalogMessageText, 'The Accounting Review'), 'removed TAR must not appear in the official sources menu');
foreach ($catalog as $item) {
    $assert(str_contains($catalogMessageText, $item['homepage']), $item['key'] . ' official homepage should appear in /sources output');
}
foreach ($catalogMessages as $message) {
    $rtlMessage = Persian::rtl($message);
    $telegramUnits = (int) (strlen(mb_convert_encoding($rtlMessage, 'UTF-16LE', 'UTF-8')) / 2);
    $assert($telegramUnits <= 4096, 'each RTL source list message should fit within Telegram UTF-16 message limits');
}

$abstractHtml = <<<'HTML'
<!doctype html><html><head><meta property="og:title" content="Sample academic paper"></head><body>
<h1>Sample academic paper</h1>
<section class="article-section__abstract"><h2>Abstract</h2><p>This abstract explains the research question, method, results, and contribution in enough detail to provide a standalone scholarly summary for readers.</p></section>
<article class="article-body"><p>FULL ARTICLE TEXT MUST NOT BE INCLUDED when this journal is configured for abstract-only translation. This is intentionally separate from the abstract.</p></article>
</body></html>
HTML;
$abstractSource = new InternationalSource(static fn (string $url, int $timeout): array => ['status' => 200, 'body' => $abstractHtml]);
$abstractArticle = $abstractSource->fetch($verifiedRoutes['jae']);
$abstractText = implode(' ', array_map(static fn (array $block): string => strip_tags((string) ($block['html'] ?? '')), $abstractArticle['blocks']));
$assert($abstractArticle['content_label'] === 'چکیدهٔ مقاله', 'academic journal output should be labeled as abstract');
$assert(str_contains($abstractText, 'research question') && !str_contains($abstractText, 'FULL ARTICLE TEXT MUST NOT BE INCLUDED'), 'abstract-only mode must not extract the full text');

$genericArticleHtml = <<<'HTML'
<!doctype html><html><head><meta property="og:title" content="Verified professional article"></head><body>
<main><article><div itemprop="articleBody"><p>This verified public professional article contains enough substantive accounting and auditing content to demonstrate that the article body extractor is working for the newly allowlisted source pages.</p></div></article></main>
</body></html>
HTML;
$genericArticleSource = new InternationalSource(static fn (string $url, int $timeout): array => ['status' => 200, 'body' => $genericArticleHtml]);
foreach (['fasb', 'pcaob', 'iia', 'ima', 'cpajournal', 'acctoday'] as $key) {
    $parsed = $genericArticleSource->fetch($verifiedRoutes[$key]);
    $assert($parsed['body_empty'] === false && $parsed['title'] === 'Verified professional article', $key . ' should extract a public article body from standard articleBody markup');
}

$assert($source->topicKey($urls['joa']) === $source->topicKey($urls['joa'] . '?utm_source=test'), 'tracking query strings must not change the topic key');
$assert($source->topicKey($urls['joa']) !== $source->topicKey($urls['ifrs']), 'topic keys must be namespaced by source');
$assert($registry->findLink('Read https://example.org/nope then https://www.iaasb.org/news-events/2023-12/iaasb-audit-guidance.') === $urls['iaasb'], 'link extraction should skip unsupported URLs and trim final punctuation');
$assert($registry->findLink('Read https://publications.aaahq.org/accounting-review/article/100/6/405/13963/Insights-from-100-Years-of-The-Accounting-Review then https://www.iaasb.org/news-events/2023-12/iaasb-audit-guidance.') === $urls['iaasb'], 'link extraction should skip removed TAR and select the next allowed article');

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
$intheblack = $source->fetch($urls['intheblack']);
$intheblackImages = array_values(array_filter($intheblack['blocks'], static fn (array $block): bool => ($block['type'] ?? '') === 'img'));
$intheblackMedia = array_values(array_filter($intheblack['blocks'], static fn (array $block): bool => ($block['type'] ?? '') === 'media'));
$intheblackHtml = implode(' ', array_map(
    static fn (array $block): string => (string) ($block['html'] ?? ''),
    array_values(array_filter($intheblack['blocks'], static fn (array $block): bool => ($block['type'] ?? '') === 'text'))
));
$assert(count($intheblackImages) === 3, 'INTHEBLACK lazy-loaded images and every image in a figure should be extracted');
$assert(
    in_array('https://intheblack.cpaaustralia.com.au/images/figure-one.jpg', array_column($intheblackImages, 'src'), true)
        && in_array('https://intheblack.cpaaustralia.com.au/images/figure-two-large.jpg', array_column($intheblackImages, 'src'), true),
    'INTHEBLACK data-src and the largest data-srcset candidate should become absolute image URLs'
);
$assert(
    count($intheblackMedia) === 2
        && in_array('video', array_column($intheblackMedia, 'media_type'), true)
        && in_array('audio', array_column($intheblackMedia, 'media_type'), true),
    'Brightcove video and Libsyn podcast embeds should become separate linkable media blocks'
);
$assert(
    in_array('https://players.brightcove.net/6055873642001/giLxnTq5x_default/index.html?videoId=6405939953112', array_column($intheblackMedia, 'src'), true)
        && in_array('https://play.libsyn.com/embed/episode/id/12345', array_column($intheblackMedia, 'src'), true),
    'media blocks should use the exact HTTPS player destinations'
);
$assert(
    str_contains($intheblackHtml, 'href="https://intheblack.cpaaustralia.com.au/technical-skills/excel-tips-python-part-1"')
        && str_contains($intheblackHtml, 'href="https://intheblack.cpaaustralia.com.au/files/example.xlsx"')
        && str_contains($intheblackHtml, 'href="https://www.cpaaustralia.com.au/professional-development/courses-and-events"'),
    'relative article links should become absolute while full links remain clickable'
);
$assert(!str_contains($intheblackHtml, 'Long podcast transcript'), 'the page transcript accordion should not be duplicated into the article body');
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
