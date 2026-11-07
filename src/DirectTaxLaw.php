<?php

declare(strict_types=1);

/**
 * دسترسی محلی به متن تنقیح‌شدهٔ قانون مالیات‌های مستقیم.
 * فایل ماده و نسخهٔ رسمی ذخیره‌شده پیش از دریافت زنده بررسی می‌شوند.
 * پاسخ نامعتبر یا صفحهٔ محدودیت دسترسی هرگز ذخیره نمی‌شود.
 */
final class DirectTaxLaw
{
    public const SOURCE_URL = 'https://qavanin.ir/Law/TreeText/?IDS=892583840653829785';
    public const LAW_TITLE = 'قانون مالیات‌های مستقیم';
    private const SOURCE_NAME = 'سامانه ملی قوانین و مقررات جمهوری اسلامی ایران';
    private const MAX_ARTICLE_NUMBER = 1000;
    private const SNAPSHOT_RETRIEVED_AT = '2026-11-06';

    /** @var Closure(string):array{status:int,body:string}|null */
    private ?Closure $fetcher;

    /**
     * @param callable(string):array{status:int,body:string}|null $fetcher Test seam; production uses Http::get.
     */
    public function __construct(private string $articlesDirectory, ?callable $fetcher = null)
    {
        $this->fetcher = $fetcher === null ? null : Closure::fromCallable($fetcher);
    }

    /** Return one unambiguous “م x ق م م” or “ماده x ق م م” request. */
    public static function requestedArticle(string $text): ?int
    {
        $request = self::requestedArticleIdentifier($text);

        return $request['number'] ?? null;
    }

    /** @return array{number:int,is_repeated:bool}|null */
    public static function requestedArticleIdentifier(string $text): ?array
    {
        $text = Persian::latinDigits($text);
        $text = str_replace(['ي', 'ك'], ['ی', 'ک'], $text);
        $matches = [];
        preg_match_all(
            '/(?<![\p{L}\p{N}])(?:ماده[\x{0654}\x{200C}]?|م)\s*([0-9]+)\s*(مکرر)?\s*ق\s*م\s*م(?![\p{L}\p{N}])/u',
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

        return [
            'number' => $number,
            'is_repeated' => !empty($matches[2][0]),
        ];
    }

    public static function isAdministratorStatus(string $status): bool
    {
        return in_array($status, ['creator', 'administrator'], true);
    }

    /**
     * ابتدا cache ماده و سپس نسخهٔ رسمی ذخیره‌شده را می‌خواند.
     * فقط اگر ماده در هیچ‌کدام نبود، دریافت زنده و cache معتبر را امتحان می‌کند.
     *
     * @return array{article_number:int,is_repeated:bool,text:string,source:string,source_url:string,retrieved_at:string}|null
     */
    public function getArticle(int $number, bool $isRepeated = false): ?array
    {
        if ($number < 1 || $number > self::MAX_ARTICLE_NUMBER) {
            return null;
        }

        $path = $this->articlePath($number, $isRepeated);
        if (is_file($path)) {
            return $this->readArticle($path, $number, $isRepeated);
        }

        $snapshotPath = $this->sourceArchivePath();
        if (is_file($snapshotPath)) {
            $snapshot = file_get_contents($snapshotPath);
            if (!is_string($snapshot)) {
                throw new RuntimeException('The local official-law snapshot could not be read.');
            }

            $articleText = self::extractArticleVariantFromSnapshot($snapshot, $number, $isRepeated);
            if ($articleText !== null) {
                $record = [
                    'article_number' => $number,
                    'is_repeated' => $isRepeated,
                    'text' => $articleText,
                    'source' => self::SOURCE_NAME,
                    'source_url' => self::SOURCE_URL,
                    'retrieved_at' => self::SNAPSHOT_RETRIEVED_AT,
                ];

                $this->writeArticle($path, $record);

                return $record;
            }

            // The committed official snapshot is the complete lookup authority
            // for this law; do not turn a missing heading into a network error.
            return null;
        }

        if ($isRepeated) {
            return null;
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
            'is_repeated' => false,
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

    /** Extract one unambiguous article from the verified, committed Markdown snapshot. */
    public static function extractArticleFromSnapshot(string $snapshot, int $requestedNumber): ?string
    {
        return self::extractArticleVariantFromSnapshot($snapshot, $requestedNumber, false);
    }

    /** Extract exact base or «مکرر» headings, preserving every officially listed version. */
    public static function extractArticleVariantFromSnapshot(
        string $snapshot,
        int $requestedNumber,
        bool $isRepeated = false
    ): ?string {
        if ($requestedNumber < 1 || $requestedNumber > self::MAX_ARTICLE_NUMBER) {
            return null;
        }

        $normalizedTitleText = str_replace(['ي', 'ك', '‌', "\u{200F}"], ['ی', 'ک', '', ''], $snapshot);
        $normalizedTitleText = preg_replace('/[^\p{L}]+/u', '', $normalizedTitleText) ?? $normalizedTitleText;
        if (!str_contains($normalizedTitleText, 'قانونمالیاتهایمستقیم')) {
            throw new RuntimeException('The local official-law snapshot title could not be verified.');
        }

        $lines = preg_split('/\R/u', $snapshot);
        if (!is_array($lines)) {
            throw new RuntimeException('The local official-law snapshot could not be parsed.');
        }

        $headings = [];
        $sectionContext = null;
        foreach ($lines as $lineNumber => $line) {
            $rawHeadingLine = preg_replace('/^[\s\p{Cf}#]+/u', '', $line) ?? $line;
            $normalizedLine = str_replace(['ي', 'ك', '‌', "\u{200F}"], ['ی', 'ک', '', ''], $rawHeadingLine);
            $headingLine = preg_replace('/^[\s\p{Cf}#]+/u', '', $normalizedLine) ?? $normalizedLine;
            if (preg_match('/^(?:بخش|فصل|باب)\s+/u', $headingLine) === 1) {
                $sectionContext = trim($rawHeadingLine);
                continue;
            }

            if (preg_match('/^ماده\s*([0-9۰-۹٠-٩]+)(?:\s*(مکرر))?(?=$|[\s\-ـ:：\(（])/u', $headingLine, $match) !== 1) {
                continue;
            }

            $headings[] = [
                'number' => (int) self::toLatinDigits($match[1]),
                'is_repeated' => !empty($match[2]),
                'line' => $lineNumber,
                'section_context' => $sectionContext,
            ];
        }

        $selected = array_values(array_filter(
            $headings,
            static fn (array $heading): bool => $heading['number'] === $requestedNumber
                && $heading['is_repeated'] === $isRepeated
        ));
        if ($selected === []) {
            return null;
        }

        $articles = [];
        foreach ($selected as $selectedHeading) {
            $start = $selectedHeading['line'];
            $end = count($lines);
            foreach ($headings as $heading) {
                if ($heading['line'] > $start) {
                    $end = $heading['line'];
                    break;
                }
            }

            $article = implode("\n", array_slice($lines, $start, $end - $start));
            $article = preg_replace('/\[([^\]\r\n]+)\]\([^)\r\n]+\)/u', '$1', $article) ?? $article;
            $article = str_replace('\\-', '-', $article);
            $article = preg_replace('/[ \t]+/u', ' ', $article) ?? $article;
            $article = preg_replace('/[ \t]*\n[ \t]*/u', "\n", $article) ?? $article;
            $article = preg_replace('/\n{3,}/u', "\n\n", $article) ?? $article;
            $article = preg_replace('/^[\s\p{Cf}]+/u', '', $article) ?? $article;
            $article = trim($article);

            $context = $selectedHeading['section_context'];
            $normalizedContext = is_string($context)
                ? str_replace(['ي', 'ك'], ['ی', 'ک'], $context)
                : '';
            if (is_string($context) && preg_match('/منسوخ(?:ه)?|اصلاحی|الحاقی|جایگزین/u', $normalizedContext) === 1) {
                $article = 'بخش مرتبط در منبع رسمی: ' . $context . "\n" . $article;
            }

            $length = mb_strlen($article, 'UTF-8');
            if ($length < 12 || $length > 50000) {
                throw new RuntimeException('The extracted snapshot article length failed validation.');
            }
            $articles[] = $article;
        }

        if (count($articles) > 1) {
            array_unshift(
                $articles,
                'توجه: این شناسه در منبع رسمی چند سرعنوان دارد. همهٔ بخش‌های دقیقاً منطبق، جداگانه و با برچسب خود منبع آمده‌اند؛ نسخهٔ جاری حدس زده نشده است.'
            );
        }

        $result = implode("\n\n────────────────\n\n", $articles);
        if (mb_strlen($result, 'UTF-8') > 150000) {
            throw new RuntimeException('The combined official article versions exceed the safe length limit.');
        }

        return $result;
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

    private function articlePath(int $number, bool $isRepeated = false): string
    {
        return rtrim($this->articlesDirectory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'article-' . $number
            . ($isRepeated ? '-mokarrar' : '') . '.json';
    }

    private function sourceArchivePath(): string
    {
        return dirname(rtrim($this->articlesDirectory, DIRECTORY_SEPARATOR))
            . DIRECTORY_SEPARATOR . 'source.md';
    }

    /** @return array{article_number:int,is_repeated:bool,text:string,source:string,source_url:string,retrieved_at:string} */
    private function readArticle(string $path, int $number, bool $isRepeated = false): array
    {
        $raw = file_get_contents($path);
        $record = is_string($raw) ? json_decode($raw, true) : null;
        $cachedRepeated = is_array($record) ? ($record['is_repeated'] ?? false) : false;
        if (!is_array($record)
            || ($record['article_number'] ?? null) !== $number
            || !is_bool($cachedRepeated)
            || $cachedRepeated !== $isRepeated
            || !is_string($record['text'] ?? null)
            || trim($record['text']) === ''
            || ($record['source_url'] ?? null) !== self::SOURCE_URL
            || !is_string($record['retrieved_at'] ?? null)
        ) {
            throw new RuntimeException('The cached law article failed validation.');
        }

        return [
            'article_number' => $number,
            'is_repeated' => $isRepeated,
            'text' => $record['text'],
            'source' => is_string($record['source'] ?? null) ? $record['source'] : self::SOURCE_NAME,
            'source_url' => self::SOURCE_URL,
            'retrieved_at' => $record['retrieved_at'],
        ];
    }

    /** @param array{article_number:int,is_repeated:bool,text:string,source:string,source_url:string,retrieved_at:string} $record */
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
