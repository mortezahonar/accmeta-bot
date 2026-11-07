<?php

declare(strict_types=1);

/** Parse the explicitly requested legal lookup forms without inferring a law. */
final class LawArticleRequest
{
    private const MAX_IDENTIFIER = 1000;

    /** @var array<string, array{pattern:string,title:string,ready:bool}> */
    private const CATEGORIES = [
        'value-added-tax' => [
            'pattern' => '/(?<![\p{L}\p{N}])(?:ماده[\x{0654}\x{200C}]?|م)\s*([0-9]+)\s*ق\s*ا\s*ا(?![\p{L}\p{N}])/u',
            'title' => 'قانون مالیات بر ارزش افزوده',
            'ready' => true,
        ],
        'social-security' => [
            'pattern' => '/(?<![\p{L}\p{N}])(?:ماده[\x{0654}\x{200C}]?|م)\s*([0-9]+)\s*ت\s*ا(?![\p{L}\p{N}])/u',
            'title' => 'قانون تأمین اجتماعی',
            'ready' => true,
        ],
        'labor-code' => [
            'pattern' => '/(?<![\p{L}\p{N}])(?:ماده[\x{0654}\x{200C}]?|م)\s*([0-9]+)\s*ق\s*ک(?![\p{L}\p{N}])/u',
            'title' => 'قانون کار',
            'ready' => false,
        ],
        'accounting-standard' => [
            'pattern' => '/(?<![\p{L}\p{N}])استاندارد\s+حسابداری\s+([0-9]+)(?![\p{L}\p{N}])/u',
            'title' => 'استاندارد حسابداری',
            'ready' => false,
        ],
        'auditing-standard' => [
            'pattern' => '/(?<![\p{L}\p{N}])استاندارد\s+حسابرسی\s+([0-9]+)(?![\p{L}\p{N}])/u',
            'title' => 'استاندارد حسابرسی',
            'ready' => false,
        ],
    ];

    /**
     * Return exactly one recognized request. Multiple requests or an invalid
     * number return an ambiguity marker so the caller never chooses one.
     *
     * @return array{law:string,number:int}|array{ambiguous:true}|null
     */
    public static function parse(string $text): ?array
    {
        $text = Persian::latinDigits($text);
        $text = str_replace(['ي', 'ك'], ['ی', 'ک'], $text);
        $matches = [];

        foreach (self::CATEGORIES as $law => $category) {
            $found = [];
            $result = preg_match_all($category['pattern'], $text, $found);
            if ($result === false) {
                return null;
            }

            foreach ($found[1] ?? [] as $rawNumber) {
                $number = filter_var($rawNumber, FILTER_VALIDATE_INT);
                if (!is_int($number) || $number < 1 || $number > self::MAX_IDENTIFIER) {
                    return ['ambiguous' => true];
                }
                $matches[] = ['law' => $law, 'number' => $number];
            }
        }

        if ($matches === []) {
            return null;
        }

        if (count($matches) !== 1) {
            return ['ambiguous' => true];
        }

        return $matches[0];
    }

    /** Detect multiple recognized requests, including the dedicated direct-tax parser's forms. */
    public static function hasMultipleRecognizedRequests(string $text): bool
    {
        $text = Persian::latinDigits($text);
        $text = str_replace(['ي', 'ك'], ['ی', 'ک'], $text);
        $count = 0;

        foreach (self::CATEGORIES as $category) {
            $matched = preg_match_all($category['pattern'], $text);
            if ($matched === false) {
                return false;
            }
            $count += $matched;
            if ($count > 1) {
                return true;
            }
        }

        $directTaxPattern = '/(?<![\p{L}\p{N}])(?:ماده[\x{0654}\x{200C}]?|م)\s*[0-9]+\s*(?:مکرر\s*)?ق\s*م\s*م(?![\p{L}\p{N}])/u';
        $directTaxMatches = preg_match_all($directTaxPattern, $text);
        return $directTaxMatches !== false && $count + $directTaxMatches > 1;
    }

    public static function title(string $law): ?string
    {
        return self::CATEGORIES[$law]['title'] ?? null;
    }

    public static function isReady(string $law): bool
    {
        return self::CATEGORIES[$law]['ready'] ?? false;
    }
}
