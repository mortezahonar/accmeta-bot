<?php

declare(strict_types=1);

/** Local-first archive for verified Qavanin law snapshots; it never fetches the network. */
final class LocalLawArchive
{
    private const SOURCE_NAME = 'سامانه ملی قوانین و مقررات جمهوری اسلامی ایران (قاونین)';

    /** @var array<string, array{title:string,source_url:string,retrieved_at:string,max_article:int}> */
    private const LAWS = [
        'value-added-tax' => [
            'title' => 'قانون مالیات بر ارزش افزوده',
            'source_url' => 'https://qavanin.ir/Law/TreeText/?IDS=17874811672232780652',
            'retrieved_at' => '2026-11-07',
            'max_article' => 57,
        ],
        'social-security' => [
            'title' => 'قانون تأمین اجتماعی',
            'source_url' => 'https://qavanin.ir/Law/TreeText/?IDS=15217005284570186428',
            'retrieved_at' => '2026-11-07',
            'max_article' => 118,
        ],
    ];

    public function __construct(private string $lawsDirectory)
    {
    }

    /**
     * Read a cached article or build it from the committed official snapshot.
     * No live source request is made by this class.
     *
     * @return array{law:string,article_number:int,is_repeated:bool,text:string,source:string,source_url:string,retrieved_at:string}|null
     */
    public function getArticle(string $law, int $number): ?array
    {
        $metadata = self::LAWS[$law] ?? null;
        if ($metadata === null) {
            throw new InvalidArgumentException('Unknown law archive.');
        }
        if ($number < 1 || $number > $metadata['max_article']) {
            return null;
        }

        $articlePath = $this->articlePath($law, $number);
        if (is_file($articlePath)) {
            return $this->readArticle($articlePath, $law, $number, $metadata);
        }

        $snapshotPath = $this->sourceArchivePath($law);
        if (!is_file($snapshotPath)) {
            throw new RuntimeException('The committed official-law snapshot is missing.');
        }

        $snapshot = file_get_contents($snapshotPath);
        if (!is_string($snapshot)) {
            throw new RuntimeException('The committed official-law snapshot could not be read.');
        }

        $articleText = self::extractArticleFromSnapshot($snapshot, $metadata['title'], $number);
        if ($articleText === null) {
            return null;
        }

        $record = [
            'law' => $law,
            'article_number' => $number,
            'is_repeated' => false,
            'text' => $articleText,
            'source' => self::SOURCE_NAME,
            'source_url' => $metadata['source_url'],
            'retrieved_at' => $metadata['retrieved_at'],
        ];
        $this->writeArticle($articlePath, $record, $law);

        return $record;
    }

    /** Extract every exact body heading for a numbered article from a Qavanin Markdown snapshot. */
    public static function extractArticleFromSnapshot(string $snapshot, string $expectedTitle, int $requestedNumber): ?string
    {
        if ($requestedNumber < 1) {
            return null;
        }

        $normalizedSnapshot = self::normalizeForComparison($snapshot);
        if (!str_contains($normalizedSnapshot, self::normalizeForComparison($expectedTitle))) {
            throw new RuntimeException('The local official-law snapshot title could not be verified.');
        }

        $lines = preg_split('/\R/u', $snapshot);
        if (!is_array($lines)) {
            throw new RuntimeException('The local official-law snapshot could not be parsed.');
        }

        // Qavanin pages include a table of contents before the full legal text.
        // Start after the explicit consolidated-version marker to avoid caching
        // the short navigation headings as if they were article text.
        $bodyStart = null;
        foreach ($lines as $lineNumber => $line) {
            if (self::normalizeForComparison(trim($line)) === 'بااصلاحاتوالحاقاتبعدی') {
                $bodyStart = $lineNumber + 1;
            }
        }
        if ($bodyStart === null) {
            throw new RuntimeException('The consolidated-text boundary could not be verified.');
        }

        $headings = [];
        $sectionContext = null;
        for ($lineNumber = $bodyStart, $lineCount = count($lines); $lineNumber < $lineCount; $lineNumber++) {
            $rawLine = $lines[$lineNumber];
            $normalizedLine = str_replace(['ي', 'ك', '‌', "\u{200F}"], ['ی', 'ک', '', ''], $rawLine);
            $headingLine = preg_replace('/^[\s\p{Cf}#>*+|]+/u', '', $normalizedLine) ?? $normalizedLine;
            $headingLine = trim($headingLine);

            if (preg_match('/^(?:بخش|فصل|باب)\s+/u', $headingLine) === 1) {
                $sectionContext = trim($rawLine);
                continue;
            }

            if (preg_match('/^ماده[\h\x{00A0}\x{200C}]*([0-9۰-۹٠-٩]+)(?:[\h\x{00A0}\x{200C}]*(مکرر))?(?=$|[\s\-ـ:：\(（])/u', $headingLine, $match) !== 1) {
                continue;
            }

            $headings[] = [
                'number' => (int) Persian::latinDigits($match[1]),
                'is_repeated' => !empty($match[2]),
                'line' => $lineNumber,
                'section_context' => $sectionContext,
            ];
        }

        if ($headings === []) {
            throw new RuntimeException('No article headings were found in the official-law snapshot.');
        }

        $selected = array_values(array_filter(
            $headings,
            static fn (array $heading): bool => $heading['number'] === $requestedNumber
                && $heading['is_repeated'] === false
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

            $articleLines = [];
            foreach (array_slice($lines, $start, $end - $start) as $line) {
                if (self::isStandaloneMarkdownLinkLine($line)) {
                    continue;
                }
                $line = preg_replace('/\[([^\]\r\n]+)\]\([^)\r\n]+\)/u', '$1', $line) ?? $line;
                $articleLines[] = str_replace('\\-', '-', $line);
            }

            $article = implode("\n", $articleLines);
            $article = preg_replace('/[\t\x{00A0} ]+/u', ' ', $article) ?? $article;
            $article = preg_replace('/[ \t]*\n[ \t]*/u', "\n", $article) ?? $article;
            $article = preg_replace('/\n{3,}/u', "\n\n", $article) ?? $article;
            $article = trim($article);

            $context = $selectedHeading['section_context'];
            $normalizedContext = is_string($context)
                ? str_replace(['ي', 'ك'], ['ی', 'ک'], $context)
                : '';
            if (is_string($context) && preg_match('/منسوخ(?:ه)?|اصلاحی|الحاقی|جایگزین/u', $normalizedContext) === 1) {
                $article = 'بخش مرتبط در منبع رسمی: ' . trim($context) . "\n" . $article;
            }

            if (mb_strlen($article, 'UTF-8') < 12 || mb_strlen($article, 'UTF-8') > 50000) {
                throw new RuntimeException('The extracted official article length failed validation.');
            }
            $articles[] = $article;
        }

        if (count($articles) > 1) {
            array_unshift(
                $articles,
                'توجه: این شماره در متن رسمی چند سرعنوان دارد. همهٔ بخش‌های دقیقاً منطبق، جداگانه و به‌ترتیب منبع آمده‌اند؛ نسخهٔ جاری حدس زده نشده است.'
            );
        }

        $result = implode("\n\n────────────────\n\n", $articles);
        if (mb_strlen($result, 'UTF-8') > 150000) {
            throw new RuntimeException('The combined official article versions exceed the safe length limit.');
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public static function laws(): array
    {
        return self::LAWS;
    }

    private function articlePath(string $law, int $number): string
    {
        return rtrim($this->lawsDirectory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . $law
            . DIRECTORY_SEPARATOR . 'articles'
            . DIRECTORY_SEPARATOR . 'article-' . $number . '.json';
    }

    private function sourceArchivePath(string $law): string
    {
        return rtrim($this->lawsDirectory, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . $law
            . DIRECTORY_SEPARATOR . 'source.md';
    }

    /** @param array{title:string,source_url:string,retrieved_at:string,max_article:int} $metadata */
    private function readArticle(string $path, string $law, int $number, array $metadata): array
    {
        $raw = file_get_contents($path);
        $record = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($record)
            || ($record['law'] ?? null) !== $law
            || ($record['article_number'] ?? null) !== $number
            || ($record['is_repeated'] ?? null) !== false
            || !is_string($record['text'] ?? null)
            || trim($record['text']) === ''
            || ($record['source_url'] ?? null) !== $metadata['source_url']
            || ($record['retrieved_at'] ?? null) !== $metadata['retrieved_at']
        ) {
            throw new RuntimeException('The cached official-law article failed validation.');
        }

        return [
            'law' => $law,
            'article_number' => $number,
            'is_repeated' => false,
            'text' => $record['text'],
            'source' => is_string($record['source'] ?? null) ? $record['source'] : self::SOURCE_NAME,
            'source_url' => $metadata['source_url'],
            'retrieved_at' => $metadata['retrieved_at'],
        ];
    }

    /** @param array{law:string,article_number:int,is_repeated:bool,text:string,source:string,source_url:string,retrieved_at:string} $record */
    private function writeArticle(string $path, array $record, string $law): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create the local official-law archive.');
        }

        $encoded = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        if (!is_string($encoded)) {
            throw new RuntimeException('Could not encode the official-law article for local storage.');
        }

        $temporary = tempnam($directory, '.article-');
        if ($temporary === false) {
            throw new RuntimeException('Could not prepare the local official-law archive write.');
        }

        try {
            if (file_put_contents($temporary, $encoded . "\n", LOCK_EX) === false
                || !rename($temporary, $path)
            ) {
                throw new RuntimeException('Could not save the official-law article locally.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private static function isStandaloneMarkdownLinkLine(string $line): bool
    {
        $line = trim($line);
        if ($line === '') {
            return false;
        }

        return preg_match('/^(?:\[[^\]\r\n]+\]\([^)\r\n]+\)(?:\s+"[^"]*")?\s*)+$/u', $line) === 1;
    }

    private static function normalizeForComparison(string $text): string
    {
        $text = str_replace(['ي', 'ك', '‌', "\u{200F}"], ['ی', 'ک', '', ''], $text);
        return preg_replace('/[^\p{L}\p{N}]+/u', '', $text) ?? $text;
    }
}
