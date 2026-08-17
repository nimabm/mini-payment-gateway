<?php

declare(strict_types=1);

namespace App\Application\Settings;

/**
 * The languages the admin panel is translated into.
 */
enum Locale: string
{
    case Persian = 'fa';
    case English = 'en';

    public static function fromStringOrDefault(?string $value, self $default = self::Persian): self
    {
        return self::tryFrom((string) $value) ?? $default;
    }

    public function direction(): string
    {
        return $this === self::Persian ? 'rtl' : 'ltr';
    }

    public function isRtl(): bool
    {
        return $this->direction() === 'rtl';
    }

    /** Native name, shown in the language switcher. */
    public function nativeName(): string
    {
        return match ($this) {
            self::Persian => 'فارسی',
            self::English => 'English',
        };
    }

    /** The calendar an operator most likely wants when picking this language. */
    public function defaultCalendar(): CalendarSystem
    {
        return $this === self::Persian ? CalendarSystem::Jalali : CalendarSystem::Gregorian;
    }
}
