<?php

declare(strict_types=1);

final class SourceCatalog
{
    /** @return list<string> */
    public static function messages(): array
    {
        $groups = [];
        foreach (InternationalSource::catalog() as $source) {
            $groups[$source['category']][] = $source;
        }

        $messages = [];
        foreach ($groups as $category => $sources) {
            $lines = [$category, 'فقط مقاله یا خبر با متن کاملِ عمومی پذیرفته می‌شود.'];
            foreach ($sources as $source) {
                $lines[] = '';
                $lines[] = $source['name'];
                $lines[] = $source['description'];
                $lines[] = 'صفحهٔ رسمی:';
                $lines[] = $source['homepage'];
            }
            $messages[] = implode("\n", $lines);
        }

        return $messages;
    }
}
