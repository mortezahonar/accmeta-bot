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
            $lines = [$category];
            if ($category === 'مجلات علمی برتر' || $category === 'مجلات تخصصی حسابرسی' || $category === 'مجلات معتبر حوزه‌های خاص') {
                $lines[] = 'برای مجلات علمی، ربات فقط چکیدهٔ مقاله را ترجمه می‌کند؛ دسترسی متن کامل به ناشر و مقاله بستگی دارد.';
            }
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
