<?php

declare(strict_types=1);

/**
 * Local-first access to the consolidated Direct Tax Law.
 * A source response is cached only after the law title and requested article
 * heading have both been validated. Failed/challenge pages are never stored.
 */
final class DirectTaxLaw
{
    public const SOURCE_URL = 'https://qavanin.ir/Law/TreeText/?IDS=892583840653829785';
    public const LAW_TITLE = 'قانون مالیات‌های مستقیم';
    private const SOURCE_NAME = 'سامانه ملی قوانین و مقررات جمهوری اسلامی ایران';
    private const MAX_ARTICLE_NUMBER = 1000;

    /** @var Closure(string):array{status:int,body:string}|null */
    private ?Closure $fetcher;

    /**
     * @param callable(string):array{status:int,body:string}|null $fetcher Test seam; production uses Http::get.
     */
    public function __construct(private string $articlesDirectory, ?callable $fetcher = null)
    {
        $this->fetcher = $fetcher === null ? null : Closure::fromCallable($fetcher);
    }

    /** Return the one unambiguous “م x ق م م” article request in a message. */
    public static function requestedArticle(string $text): ?int
    {
        $text = Persian::latinDigits($text);
        $matches = [];
        preg_match_all(
            '/(?<![\p{L}\p{N}])م\s*([0-9]+)\s*ق\s*م\s*م(?![\p{L}\p{N}])/u',
            $text,
            $matches
        );

        if (count($matches[1] ?? []) !== 1) {
            return null;
        }

        $number = filter_var($matches[1][0], FILTER_VALIDATE_INT);
        if (!is_int($number) || $number < 1 || $number > self::MAX_ARTICLE_NUMBER) {
            return null;
        }

        return $number;
    }

    public static function isAdministratorStatus(string $status): bool
    {
        return in_array($status, ['creator', 'administrator'], true);
    }

    /**
     * Read a cached article or fetch it once from the official source and cache
     * the validated result for future offline lookups.
     *
     * @return array{article_number:int,text:string,source:string,source_url:string,retrieved_at:string}|null
     */
    public function getArticle(int $number): ?array
    {
        if ($number < 1 || $number > self::MAX_ARTICLE_NUMBER) {
            return null;
        }

        $path = $this->articlePath($number);
        if (is_file($path)) {
            return $this->readArticle($path, $number);
        }

        $response = $this->fetcher !== null
            ? ($this->fetcher)(self::SOURCE_URL)
            : Http::get(self::SOURCE_URL, 45);

        if (($response['status'] ?? 0) !== 200 || !isset($response['body']) || !is_string($response['body'])) {
            throw new RuntimeException('The official law source did not return a usable page.');
        }

        $articleText = self::extractArticle($response['body'], $number);
        if ($articleText === null) {
            return null;
        }

        $record = [
            'article_number' => $number,
            'text' => $articleText,
            'source' => self::SOURCE_NAME,
            'source_url' => self::SOURCE_URL,
            'retrieved_at' => gmdate('c'),
        ];

        $this->writeArticle($path, $record);

        return $record;
    }

    /**
     * Extract one article from a rendered official-law page. The parser fails
     * closed if it sees an access-denial/challenge page or an unexpected title.
     */
    public static function extractArticle(string $html, int $requestedNumber): ?string
    {
        if ($requestedNumber < 1 || $requestedNumber > self::MAX_ARTICLE_NUMBER) {
            return null;
        }

        if (preg_match('/#DOMAIN#|Transferring to the website|access denied|دسترسی شما به این بخش مسدود|درخواست شما به دلیل امنیتی پردازش نشد/i', $html) === 1) {
            throw new RuntimeException('The official law source returned an access restriction page.');
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$loaded || $document->documentElement === null) {
            throw new RuntimeException('The official law source returned unreadable HTML.');
        }

        $body = $document->getElementsByTagName('body')->item(0) ?? $document->documentElement;
        $visibleText = self::visibleText($body);
        $normalizedTitleText = str_replace(['ي', 'ك', '‌', "\u{200F}"], ['ی', 'ک', '', ''], $visibleText);
        $normalizedTitleText = preg_replace('/[^\p{L}]+/u', '', $normalizedTitleText) ?? $normalizedTitleText;

        if (!str_contains($normalizedTitleText, 'قانونمالیاتهایمستقیم')) {
            throw new RuntimeException('The official law source title could not be verified.');
        }

        $headingPattern = '/^[\h\x{200C}\x{200F}]*ماده[\h\x{00A0}]+([0-9۰-۹٠-٩]+)[\h\x{00A0}]*(?=[\-‐‑‒–—:：\(（]|$)/mu';
        $count = preg_match_all($headingPattern, $visibleText, $matches, PREG_OFFSET_CAPTURE);

        if ($count === false || $count === 0) {
            throw new RuntimeException('No article headings were found in the official law page.');
        }

        $selected = [];
        foreach ($matches[1] as $index => $numberMatch) {
            if (self::toLatinDigits($numberMatch[0]) === (string) $requestedNumber) {
                $selected[] = $index;
            }
        }

        // More than one plausible heading is ambiguous; do not guess which one
        // to publish or store.
        if (count($selected) !== 1) {
            return null;
        }

        $index = $selected[0];
        $start = $matches[0][$index][1];
        $end = strlen($visibleText);
        for ($next = $index + 1; $next < $count; $next++) {
            $end = $matches[0][$next][1];
            break;
        }

        $article = trim(substr($visibleText, $start, $end - $start));
        $article = preg_replace('/[\t\x{00A0} ]+/u', ' ', $article) ?? $article;
        $article = preg_replace('/\n[\h\x{200C}\x{200F}]*\n+/u', "\n", $article) ?? $article;

        if (mb_strlen($article, 'UTF-8') < 12 || mb_strlen($article, 'UTF-8') > 50000) {
            throw new RuntimeException('The extracted article length failed validation.');
        }

        return $article;
    }

    /** Split text without cutting a UTF-8 character; count Telegram UTF-16 units. */
    public static function splitForTelegram(string $text, int $maxUtf16Units = 3300): array
    {
        if ($maxUtf16Units < 100) {
            throw new InvalidArgumentException('Message chunk limit is too small.');
        }

        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($characters)) {
            throw new RuntimeException('Text is not valid UTF-8.');
        }

        $chunks = [];
        $current = '';
        $units = 0;

        foreach ($characters as $character) {
            $characterUnits = intdiv(strlen(mb_convert_encoding($character, 'UTF-16LE', 'UTF-8')), 2);
            if ($units + $characterUnits > $maxUtf16Units) {
                $newline = mb_strrpos($current, "\n", 0, 'UTF-8');
                if ($newline !== false && $newline > 0) {
                    $splitAt = $newline + 1;
                    $chunks[] = mb_substr($current, 0, $splitAt, 'UTF-8');
                    $current = mb_substr($current, $splitAt, null, 'UTF-8');
                    $units = self::utf16Length($current);
                } else {
                    $chunks[] = $current;
                    $current = '';
                    $units = 0;
                }
            }

            $current .= $character;
            $units += $characterUnits;
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    private function articlePath(int $number): string
    {
        return rtrim($this->articlesDirectory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'article-' . $number . '.json';
    }

    /** @return array{article_number:int,text:string,source:string,source_url:string,retrieved_at:string} */
    private function readArticle(string $path, int $number): array
    {
        $raw = file_get_contents($path);
        $record = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($record)
            || ($record['article_number'] ?? null) !== $number
            || !is_string($record['text'] ?? null)
            || trim($record['text']) === ''
            || ($record['source_url'] ?? null) !== self::SOURCE_URL
            || !is_string($record['retrieved_at'] ?? null)
        ) {
            throw new RuntimeException('The cached law article failed validation.');
        }

        return [
            'article_number' => $number,
            'text' => $record['text'],
            'source' => is_string($record['source'] ?? null) ? $record['source'] : self::SOURCE_NAME,
            'source_url' => self::SOURCE_URL,
            'retrieved_at' => $record['retrieved_at'],
        ];
    }

    /** @param array{article_number:int,text:string,source:string,source_url:string,retrieved_at:string} $record */
    private function writeArticle(string $path, array $record): void
    {
        if (!is_dir($this->articlesDirectory)
            && !mkdir($this->articlesDirectory, 0775, true)
            && !is_dir($this->articlesDirectory)
        ) {
            throw new RuntimeException('Could not create the local law archive.');
        }

        $encoded = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($encoded)) {
            throw new RuntimeException('Could not encode the law article for local storage.');
        }

        $temporary = tempnam($this->articlesDirectory, '.article-');
        if ($temporary === false) {
            throw new RuntimeException('Could not prepare the local law archive write.');
        }

        try {
            if (file_put_contents($temporary, $encoded . "\n", LOCK_EX) === false
                || !rename($temporary, $path)
            ) {
                throw new RuntimeException('Could not save the law article locally.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private static function visibleText(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return $node->nodeValue ?? '';
        }

        if (!$node instanceof DOMElement && !$node instanceof DOMDocument) {
            return '';
        }

        if ($node instanceof DOMElement) {
            $tag = strtolower($node->tagName);
            if (in_array($tag, ['script', 'style', 'noscript', 'svg', 'iframe', 'nav', 'header', 'footer', 'form'], true)) {
                return '';
            }
            if ($tag === 'br') {
                return "\n";
            }
        }

        $isBlock = $node instanceof DOMElement && in_array(strtolower($node->tagName), [
            'address', 'article', 'blockquote', 'dd', 'div', 'dl', 'dt', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'li', 'ol', 'p', 'section', 'table', 'tbody', 'td', 'th', 'tr', 'ul',
        ], true);

        $text = $isBlock ? "\n" : '';
        foreach ($node->childNodes as $child) {
            $text .= self::visibleText($child);
        }

        return $text . ($isBlock ? "\n" : '');
    }

    private static function toLatinDigits(string $digits): string
    {
        return strtr($digits, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    private static function utf16Length(string $text): int
    {
        return intdiv(strlen(mb_convert_encoding($text, 'UTF-16LE', 'UTF-8')), 2);
    }
}
