<?php

declare(strict_types=1);

/** Allowlisted source adapter for public English accounting and audit content. */
final class InternationalSource implements ArticleSource
{
    /**
     * Retained sources have verified public full-text examples; each submitted
     * article must still expose its complete body without a registration wall.
     *
     * @var list<array{key:string,host:string,base:string,homepage:string,name:string,category:string,description:string,mode:string,path:string}>
     */
    private const SITES = [
        [
            'key' => 'ifrs', 'host' => 'ifrs.org', 'base' => 'https://www.ifrs.org',
            'homepage' => 'https://www.ifrs.org/',
            'name' => 'IFRS Foundation', 'category' => 'نهادهای استانداردگذار',
            'description' => 'بنیاد تدوین استانداردهای بین‌المللی حسابداری IFRS و افشای پایداری؛ صفحه‌های خبری رسمی پشتیبانی می‌شوند.',
            'mode' => 'article', 'path' => '~^/news-and-events/news/\d{4}/\d{2}/[^/]+/?$~i',
        ],
        [
            'key' => 'iaasb', 'host' => 'iaasb.org', 'base' => 'https://www.iaasb.org',
            'homepage' => 'https://www.iaasb.org/',
            'name' => 'IAASB', 'category' => 'نهادهای استانداردگذار',
            'description' => 'هیئت استانداردهای بین‌المللی حسابرسی و اطمینان‌بخشی؛ خبرها و راهنماهای رسمی پشتیبانی می‌شوند.',
            'mode' => 'article', 'path' => '~^/(?:news-events|news)/[^/]+/[^/]+(?:/[^/]+)*/?$~i',
        ],
        [
            'key' => 'iia', 'host' => 'www.theiia.org', 'base' => 'https://www.theiia.org',
            'homepage' => 'https://www.theiia.org/',
            'name' => 'The Institute of Internal Auditors (IIA)', 'category' => 'نهادهای استانداردگذار',
            'description' => 'انجمن جهانی حسابرسان داخلی؛ مقاله‌های حرفه‌ای آن پشتیبانی می‌شوند. فایل‌های PDF استانداردها به‌عنوان مقاله پردازش نمی‌شوند.',
            'mode' => 'article', 'path' => '~^/en/content/articles/[^/]+/\d{4}/[^/]+/?$~i',
        ],
        [
            'key' => 'joa', 'host' => 'journalofaccountancy.com', 'base' => 'https://www.journalofaccountancy.com',
            'homepage' => 'https://www.journalofaccountancy.com/',
            'name' => 'Journal of Accountancy (AICPA & CIMA)', 'category' => 'انجمن‌ها و مجلات حرفه‌ای',
            'description' => 'مجلهٔ حرفه‌ای حسابداری، حسابرسی، گزارشگری مالی و مالیات وابسته به AICPA & CIMA.',
            'mode' => 'article', 'path' => '~^/(?:news|issues|features)/[^/]+/[^/]+(?:/[^/]+)*/?$~i',
        ],
        [
            'key' => 'intheblack', 'host' => 'intheblack.cpaaustralia.com.au', 'base' => 'https://intheblack.cpaaustralia.com.au',
            'homepage' => 'https://intheblack.cpaaustralia.com.au/',
            'name' => 'INTHEBLACK (CPA Australia)', 'category' => 'انجمن‌ها و مجلات حرفه‌ای',
            'description' => 'مجلهٔ حرفه‌ای CPA Australia دربارهٔ حسابداری، حسابرسی، حرفه و محیط کار؛ مقاله‌های کامل عمومی پشتیبانی می‌شوند.',
            'mode' => 'article', 'path' => '~^/(?!latest/?$)[^/]+/[^/]+(?:/[^/]+)*/?$~i',
        ],
        [
            'key' => 'cpajournal', 'host' => 'www.cpajournal.com', 'base' => 'https://www.cpajournal.com',
            'homepage' => 'https://www.cpajournal.com/',
            'name' => 'The CPA Journal (NYSSCPA)', 'category' => 'انجمن‌ها و مجلات حرفه‌ای',
            'description' => 'نشریهٔ انجمن حسابداران رسمی ایالت نیویورک دربارهٔ حسابداری، حسابرسی، مالیات و موضوعات حرفه‌ای.',
            'mode' => 'article', 'path' => '~^/\d{4}/\d{2}/\d{2}/[^/]+/?$~i',
        ],
    ];

    /** @var (\Closure(string, int): array{status: int, body: string})|null */
    private ?Closure $fetcher = null;

    public function __construct(?callable $fetcher = null)
    {
        $this->fetcher = $fetcher !== null ? Closure::fromCallable($fetcher) : null;
    }

    /** @return list<array{key:string,name:string,category:string,homepage:string,description:string,mode:string}> */
    public static function catalog(): array
    {
        return array_map(static fn (array $site): array => [
            'key' => $site['key'],
            'name' => $site['name'],
            'category' => $site['category'],
            'homepage' => $site['homepage'],
            'description' => $site['description'],
            'mode' => $site['mode'],
        ], self::SITES);
    }

    public function supportsUrl(string $url): bool
    {
        return $this->siteForUrl($url) !== null;
    }

    public function topicKey(string $url): string
    {
        $site = $this->siteForUrl($url);
        if ($site === null) {
            return '';
        }

        $path = (string) (parse_url(trim($url), PHP_URL_PATH) ?: '');
        $segments = array_values(array_filter(explode('/', $path), static fn (string $part): bool => $part !== ''));
        $slug = rawurldecode((string) (end($segments) ?: ''));
        $slug = (string) preg_replace('/\.(?:html?|aspx?)$/i', '', $slug);
        $slug = strtolower(trim((string) preg_replace('/[^a-z0-9]+/i', '-', $slug), '-'));

        if ($slug === '') {
            $slug = substr(hash('sha256', strtolower((string) parse_url($url, PHP_URL_HOST)) . $path), 0, 24);
        }

        return substr($site['key'] . '-' . $slug, 0, 80);
    }

    /**
     * @return array{
     *     title: string,
     *     author: string,
     *     author_url: string,
     *     published: string,
     *     lead_image: string,
     *     body_empty: bool,
     *     tags: list<string>,
     *     blocks: list<array{type: string, html?: string, src?: string, alt?: string, media_type?: string}>,
     *     source_name?: string,
     *     content_label?: string
     * }
     */
    public function fetch(string $url): array
    {
        $site = $this->siteForUrl($url);
        if ($site === null) {
            throw new RuntimeException('این لینک در فهرست منابع بین‌المللی پشتیبانی‌شده نیست.');
        }

        $response = $this->fetcher !== null
            ? ($this->fetcher)($url, 90)
            : Http::get($url, 90);

        if ($response['status'] !== 200) {
            throw new RuntimeException($site['key'] . ' پاسخ HTTP ' . $response['status'] . ' داد.');
        }

        $article = (new InternationalArticleParser())->parse($response['body'], $site['key'], $url, $site['mode']);
        foreach ($article['blocks'] as &$block) {
            if (($block['type'] ?? '') === 'img') {
                $block['src'] = $this->resolveUrl((string) ($block['src'] ?? ''), $url);
            }
        }
        unset($block);

        $bodyText = '';
        foreach ($article['blocks'] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $bodyText .= ' ' . html_entity_decode(strip_tags((string) ($block['html'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        $bodyText = trim((string) preg_replace('/\s+/u', ' ', $bodyText));

        if ($article['title'] === '' || $article['body_empty'] || mb_strlen($bodyText, 'UTF-8') < 80) {
            throw new RuntimeException('عنوان یا متن مقاله پیدا نشد؛ ممکن است صفحه محدود، ناقص یا ساختارش عوض شده باشد.');
        }

        if (preg_match('/create (?:a )?free account to continue reading|subscribe to continue reading|log in to continue reading/i', $bodyText) === 1) {
            throw new RuntimeException('این صفحه برای ادامهٔ خواندن به حساب کاربری یا اشتراک نیاز دارد؛ ترجمه متوقف شد.');
        }

        $article['source_name'] = $site['name'];
        $article['content_label'] = $site['mode'] === 'abstract' ? 'چکیدهٔ مقاله' : '';
        return $article;
    }

    public function resolveUrl(string $src, string $articleUrl): string
    {
        return InternationalArticleParser::absoluteUrl($src, $articleUrl);
    }

    /** @return array{key:string,host:string,base:string,homepage:string,name:string,category:string,description:string,mode:string,path:string}|null */
    private function siteForUrl(string $url): ?array
    {
        $parts = parse_url(trim($url));
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        $port = $parts['port'] ?? null;
        if (
            !in_array($scheme, ['http', 'https'], true)
            || ($port !== null && !in_array($port, [80, 443], true))
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return null;
        }

        if (str_starts_with($host, 'www.')) {
            $host = substr($host, 4);
        }

        $path = (string) ($parts['path'] ?? '/');
        foreach (self::SITES as $site) {
            $candidateHost = str_starts_with($site['host'], 'www.') ? substr($site['host'], 4) : $site['host'];
            if ($host === $candidateHost && preg_match($site['path'], $path) === 1) {
                return $site;
            }
        }

        return null;
    }
}
