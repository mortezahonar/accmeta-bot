<?php

declare(strict_types=1);

/** Allowlisted source adapter for public English accounting and audit articles. */
final class InternationalSource implements ArticleSource
{
    private const SITES = [
        'journalofaccountancy.com' => ['key' => 'joa', 'base' => 'https://www.journalofaccountancy.com'],
        'ifrs.org' => ['key' => 'ifrs', 'base' => 'https://www.ifrs.org'],
        'iaasb.org' => ['key' => 'iaasb', 'base' => 'https://www.iaasb.org'],
        'intheblack.cpaaustralia.com.au' => ['key' => 'intheblack', 'base' => 'https://intheblack.cpaaustralia.com.au'],
    ];

    /** @var (\Closure(string, int): array{status: int, body: string})|null */
    private ?Closure $fetcher = null;

    public function __construct(?callable $fetcher = null)
    {
        $this->fetcher = $fetcher !== null ? Closure::fromCallable($fetcher) : null;
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
     *     blocks: list<array{type: string, html?: string, src?: string, alt?: string}>
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

        $article = (new InternationalArticleParser())->parse($response['body'], $site['key'], $url);
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
            throw new RuntimeException('عنوان یا متن کامل مقاله پیدا نشد؛ ممکن است صفحه محدود، ناقص یا ساختارش عوض شده باشد.');
        }

        if (preg_match('/create (?:a )?free account to continue reading|subscribe to continue reading|log in to continue reading/i', $bodyText) === 1) {
            throw new RuntimeException('این صفحه برای ادامهٔ خواندن به حساب کاربری یا اشتراک نیاز دارد؛ ترجمه متوقف شد.');
        }

        return $article;
    }

    public function resolveUrl(string $src, string $articleUrl): string
    {
        return InternationalArticleParser::absoluteUrl($src, $articleUrl);
    }

    /** @return array{key: string, base: string}|null */
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

        $site = self::SITES[$host] ?? null;

        if ($site === null) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '/');
        $segments = array_values(array_filter(explode('/', $path), static fn (string $part): bool => $part !== ''));

        if (count($segments) < 2) {
            return null;
        }

        $first = strtolower((string) ($segments[0] ?? ''));

        $allowed = match ($site['key']) {
            'joa' => in_array($first, ['news', 'issues', 'features'], true) && count($segments) >= 3,
            'ifrs' => str_starts_with(strtolower($path), '/news-and-events/news/') && count($segments) >= 5,
            'iaasb' => in_array($first, ['news-events', 'news'], true) && count($segments) >= 3,
            'intheblack' => count($segments) >= 2,
            default => false,
        };

        return $allowed ? $site : null;
    }
}
