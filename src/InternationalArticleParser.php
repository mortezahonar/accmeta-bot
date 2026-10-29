<?php

declare(strict_types=1);

/** Extracts public article pages from the four allowlisted international sources. */
final class InternationalArticleParser
{
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
    public function parse(string $html, string $sourceKey, string $articleUrl): array
    {
        $previous = libxml_use_internal_errors(true);
        $dom = new DOMDocument();
        $loaded = $dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded) {
            throw new RuntimeException('ساختار HTML مقاله قابل‌خواندن نیست.');
        }

        $xpath = new DOMXPath($dom);
        $result = [
            'title' => '',
            'author' => '',
            'author_url' => '',
            'published' => '',
            'lead_image' => '',
            'body_empty' => true,
            'tags' => [],
            'blocks' => [],
        ];

        $result['title'] = $this->firstText($xpath, [
            '//h1[1]',
            '//meta[@property="og:title"]/@content',
            '//meta[@name="twitter:title"]/@content',
            '//title[1]',
        ]);
        $result['title'] = trim((string) preg_replace('/\s+[|–—-]\s+(?:Journal of Accountancy|IFRS Foundation|IAASB|INTHEBLACK).*$/iu', '', $result['title']));

        $result['author'] = $this->firstText($xpath, [
            '//meta[translate(@name,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="author"]/@content',
            '//meta[@property="article:author"]/@content',
            '//*[contains(concat(" ",normalize-space(@class)," ")," byline ")]//a[1]',
            '//*[contains(concat(" ",normalize-space(@class)," ")," author ")]//a[1]',
            '//a[@rel="author"][1]',
        ]);
        $result['author'] = trim((string) preg_replace('/^by\s+/i', '', $result['author']));

        $result['published'] = $this->firstText($xpath, [
            '//meta[@property="article:published_time"]/@content',
            '//meta[@name="article:published_time"]/@content',
            '//meta[@name="date"]/@content',
            '//meta[@name="pubdate"]/@content',
            '//time[@datetime][1]/@datetime',
        ]);

        if ($result['published'] === '') {
            $result['published'] = $this->firstText($xpath, [
                '//*[contains(concat(" ",normalize-space(@class)," ")," header-title-wrap ")]//text()[normalize-space()][1]',
                '//*[contains(concat(" ",normalize-space(@class)," ")," news__meta-date-location ")]//text()[normalize-space()][1]',
            ]);
        }

        if (str_contains($result['published'], '|')) {
            $result['published'] = trim(explode('|', $result['published'], 2)[0]);
        }

        $jsonLdAuthor = $this->jsonLdField($xpath, 'author');
        if ($result['title'] === '') {
            $result['title'] = $this->jsonLdText($this->jsonLdField($xpath, 'headline'));
        }
        if ($result['author'] === '') {
            $result['author'] = $this->jsonLdText($jsonLdAuthor);
        }
        if ($result['published'] === '') {
            $result['published'] = $this->jsonLdText($this->jsonLdField($xpath, 'datePublished'));
        }
        if ($result['author_url'] === '' && is_array($jsonLdAuthor)) {
            $result['author_url'] = self::absoluteUrl($this->jsonLdText($jsonLdAuthor['url'] ?? ''), $articleUrl);
        }

        if ($result['author'] !== '') {
            $authorNode = $xpath->query('//a[@rel="author" or contains(concat(" ",normalize-space(@class)," ")," author ")][1]')->item(0);
            if ($authorNode instanceof DOMElement && $this->normalizeText($authorNode->textContent) === $result['author']) {
                $result['author_url'] = self::absoluteUrl($authorNode->getAttribute('href'), $articleUrl);
            }
        }

        $result['lead_image'] = $this->firstText($xpath, [
            '//meta[@property="og:image"]/@content',
            '//meta[@name="twitter:image"]/@content',
        ]);
        if ($result['lead_image'] === '') {
            $result['lead_image'] = $this->jsonLdText($this->jsonLdField($xpath, 'image'));
        }
        $result['lead_image'] = self::absoluteUrl($result['lead_image'], $articleUrl);

        $keywords = $this->firstText($xpath, ['//meta[@name="keywords"]/@content']);
        if ($keywords !== '') {
            foreach (preg_split('/\s*,\s*/u', $keywords, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $tag) {
                $tag = trim($tag);
                if ($tag !== '' && !in_array($tag, $result['tags'], true)) {
                    $result['tags'][] = $tag;
                }
            }
        }

        $containers = $this->bodyContainers($xpath, $sourceKey);

        if ($containers === []) {
            return $result;
        }

        foreach ($containers as $container) {
            if (!$container instanceof DOMElement) {
                continue;
            }

            $this->removeNoise($xpath, $container);
            $this->appendBlocks($dom, $xpath, $container, $result['blocks']);
        }

        foreach ($result['blocks'] as $block) {
            if ($block['type'] === 'img' || trim(strip_tags((string) ($block['html'] ?? ''))) !== '') {
                $result['body_empty'] = false;
                break;
            }
        }

        return $result;
    }

    private function jsonLdField(DOMXPath $xpath, string $field): mixed
    {
        $scripts = $xpath->query('//script[translate(@type,"ABCDEFGHIJKLMNOPQRSTUVWXYZ","abcdefghijklmnopqrstuvwxyz")="application/ld+json"]');
        if ($scripts === false) {
            return null;
        }

        foreach ($scripts as $script) {
            $data = json_decode((string) $script->textContent, true);
            $value = $this->findJsonLdField($data, $field);
            if ($value !== null && $value !== '') {
                return $value;
            }
        }
        return null;
    }

    private function findJsonLdField(mixed $value, string $field): mixed
    {
        if (!is_array($value)) {
            return null;
        }
        if (array_key_exists($field, $value)) {
            return $value[$field];
        }
        foreach ($value as $child) {
            $found = $this->findJsonLdField($child, $field);
            if ($found !== null && $found !== '') {
                return $found;
            }
        }
        return null;
    }

    private function jsonLdText(mixed $value): string
    {
        if (is_string($value) || is_numeric($value)) {
            return trim((string) $value);
        }
        if (!is_array($value)) {
            return '';
        }
        if (array_is_list($value)) {
            foreach ($value as $item) {
                $text = $this->jsonLdText($item);
                if ($text !== '') {
                    return $text;
                }
            }
            return '';
        }
        foreach (['name', 'url', 'contentUrl', '@id'] as $key) {
            if (isset($value[$key])) {
                $text = $this->jsonLdText($value[$key]);
                if ($text !== '') {
                    return $text;
                }
            }
        }
        return '';
    }

    /** @return list<DOMNode> */
    private function bodyContainers(DOMXPath $xpath, string $sourceKey): array
    {
        $queries = match ($sourceKey) {
            'joa' => ['//div[contains(concat(" ",normalize-space(@class)," ")," entry-content ")]'],
            'ifrs' => ['//div[contains(concat(" ",normalize-space(@class)," ")," cmp-text ")]'],
            'iaasb' => ['//div[contains(concat(" ",normalize-space(@class)," ")," news__content-text ")]'],
            'intheblack' => ['//div[contains(concat(" ",normalize-space(@class)," ")," content-rich-text ")]'],
            default => [],
        };

        $nodes = [];
        foreach ($queries as $query) {
            $found = $xpath->query($query);
            if ($found === false) {
                continue;
            }
            foreach ($found as $node) {
                $text = $this->normalizeText($node->textContent);
                if ($text !== '' && !$this->isNoiseText($text)) {
                    $nodes[] = $node;
                }
            }
        }

        if ($sourceKey === 'ifrs' && $nodes !== []) {
            usort($nodes, fn (DOMNode $a, DOMNode $b): int => mb_strlen($this->normalizeText($b->textContent), 'UTF-8') <=> mb_strlen($this->normalizeText($a->textContent), 'UTF-8'));
            return [$nodes[0]];
        }

        if ($sourceKey === 'joa' && count($nodes) > 1) {
            usort($nodes, fn (DOMNode $a, DOMNode $b): int => mb_strlen($this->normalizeText($b->textContent), 'UTF-8') <=> mb_strlen($this->normalizeText($a->textContent), 'UTF-8'));
            return [$nodes[0]];
        }

        if ($sourceKey === 'iaasb' && count($nodes) > 1) {
            foreach ($nodes as $node) {
                if (str_contains(' ' . (string) ($node instanceof DOMElement ? $node->getAttribute('class') : '') . ' ', ' news__content-text ')) {
                    return [$node];
                }
            }
            return [$nodes[0]];
        }

        return $nodes;
    }

    private function removeNoise(DOMXPath $xpath, DOMElement $container): void
    {
        $nodes = $xpath->query(
            './/script | .//style | .//noscript | .//iframe | .//form | .//button | .//nav | .//aside | .//footer | .//svg',
            $container
        );
        if ($nodes !== false) {
            $remove = [];
            foreach ($nodes as $node) {
                $remove[] = $node;
            }
            foreach ($remove as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        $all = $xpath->query('.//*[@class]', $container);
        if ($all === false) {
            return;
        }
        $remove = [];
        foreach ($all as $node) {
            $class = strtolower((string) $node->attributes?->getNamedItem('class')?->nodeValue);
            if (preg_match('/(?:^|\s)(?:advertisement|ad-slot|related|social-share|share-buttons|cookie|newsletter|author-bio|comment-form)(?:\s|$)/i', $class) === 1) {
                $remove[] = $node;
            }
        }
        foreach ($remove as $node) {
            $node->parentNode?->removeChild($node);
        }
    }

    /** @param list<array{type: string, html?: string, src?: string, alt?: string}> $blocks */
    private function appendBlocks(DOMDocument $dom, DOMXPath $xpath, DOMElement $container, array &$blocks): void
    {
        $children = [];
        foreach ($container->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                if (trim((string) $child->textContent) === '') {
                    continue;
                }
                $html = $dom->saveHTML($child);
                if (is_string($html) && trim(strip_tags($html)) !== '') {
                    $blocks[] = ['type' => 'text', 'html' => $html];
                }
                continue;
            }

            if (!$child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);
            if ($tag === 'figure') {
                $img = $xpath->query('.//img[@src]', $child)->item(0);
                if ($img instanceof DOMElement) {
                    $caption = $this->normalizeText((string) $xpath->query('.//figcaption[1]', $child)->item(0)?->textContent);
                    $alt = trim($img->getAttribute('alt'));
                    $blocks[] = [
                        'type' => 'img',
                        'src' => $img->getAttribute('src'),
                        'alt' => $alt !== '' ? $alt : $caption,
                    ];
                    continue;
                }
            }

            if ($tag === 'img' && $child->hasAttribute('src')) {
                $blocks[] = ['type' => 'img', 'src' => $child->getAttribute('src'), 'alt' => $child->getAttribute('alt')];
                continue;
            }

            $html = $dom->saveHTML($child);
            if (is_string($html) && trim(strip_tags($html)) !== '') {
                $blocks[] = ['type' => 'text', 'html' => $html];
            } elseif ($html !== '' && preg_match('/<img\b/i', $html)) {
                $img = $xpath->query('.//img[@src]', $child)->item(0);
                if ($img instanceof DOMElement) {
                    $blocks[] = ['type' => 'img', 'src' => $img->getAttribute('src'), 'alt' => $img->getAttribute('alt')];
                }
            }
        }
    }

    /** @param list<string> $queries */
    private function firstText(DOMXPath $xpath, array $queries): string
    {
        foreach ($queries as $query) {
            $node = $xpath->query($query)->item(0);
            if ($node === null) {
                continue;
            }
            $text = $this->normalizeText((string) ($node->nodeValue ?? $node->textContent));
            if ($text !== '') {
                return $text;
            }
        }
        return '';
    }

    private function normalizeText(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    private function isNoiseText(string $text): bool
    {
        return preg_match('/^(?:your privacy|cookie preferences|cookie details|accept optional cookies|reject optional cookies)/i', trim($text)) === 1;
    }

    public static function absoluteUrl(string $src, string $articleUrl): string
    {
        $src = trim(html_entity_decode($src, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($src === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $src) === 1) {
            return $src;
        }
        if (str_starts_with($src, '//')) {
            return 'https:' . $src;
        }

        $origin = parse_url($articleUrl);
        if (!is_array($origin) || empty($origin['host'])) {
            return '';
        }
        $scheme = strtolower((string) ($origin['scheme'] ?? 'https')) === 'http' ? 'http' : 'https';
        $host = (string) $origin['host'];
        $port = isset($origin['port']) && !in_array((int) $origin['port'], [80, 443], true) ? ':' . (int) $origin['port'] : '';
        $parts = parse_url($src);
        $path = (string) ($parts['path'] ?? '');

        if (str_starts_with($path, '/')) {
            $resolved = $path;
        } else {
            $articlePath = (string) ($origin['path'] ?? '/');
            $directory = rtrim(str_replace('\\', '/', dirname($articlePath)), '/');
            $resolved = ($directory === '' ? '' : $directory) . '/' . $path;
        }

        $segments = [];
        foreach (explode('/', $resolved) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }
        $absolute = $scheme . '://' . $host . $port . '/' . implode('/', $segments);
        if (isset($parts['query'])) {
            $absolute .= '?' . $parts['query'];
        }
        if (isset($parts['fragment'])) {
            $absolute .= '#' . $parts['fragment'];
        }
        return $absolute;
    }
}
