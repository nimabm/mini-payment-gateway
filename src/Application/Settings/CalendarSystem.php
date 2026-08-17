<?php

declare(strict_types=1);

namespace App\Application\Settings;

/**
 * How dates are shown and, just as importantly, how date filters typed into
 * the panel are parsed. Storage is always Gregorian UTC.
 */
enum CalendarSystem: string
{
    case Jalali = 'jalali';
    case Gregorian = 'gregorian';

    public static function fromStringOrDefault(?string $value, self $default = self::Jalali): self
    {
        return self::tryFrom((string) $value) ?? $default;
    }

    /** Format hint shown next to date inputs, e.g. "1403/05/26". */
    public function inputHint(): string
    {
        return $this === self::Jalali ? '1403/05/26' : '2024-08-16';
    }
}
