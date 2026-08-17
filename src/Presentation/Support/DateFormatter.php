<?php

declare(strict_types=1);

namespace App\Presentation\Support;

use App\Application\Settings\CalendarSystem;
use App\Application\Settings\Locale;
use DateTimeImmutable;
use DateTimeZone;
use Morilog\Jalali\CalendarUtils;
use Morilog\Jalali\Jalalian;

/**
 * Converts between stored UTC Gregorian timestamps and what the operator sees
 * and types.
 *
 * Both directions matter. Rendering a Jalali date is cosmetic; *parsing* one is
 * not — when the calendar is set to Jalali, typing "1403/05/01" into a date
 * filter has to produce the right UTC range, or every report silently covers
 * the wrong period.
 */
final class DateFormatter
{
    /** Years below this are read as Jalali, years at or above it as Gregorian. */
    private const CALENDAR_YEAR_BOUNDARY = 1700;

    private DateTimeZone $timezone;

    public function __construct(
        private CalendarSystem $calendar = CalendarSystem::Jalali,
        private Locale $locale = Locale::Persian,
        string $timezone = 'Asia/Tehran',
    ) {
        $this->timezone = new DateTimeZone($timezone);
    }

    public function configure(CalendarSystem $calendar, Locale $locale, string $timezone): void
    {
        $this->calendar = $calendar;
        $this->locale = $locale;
        $this->timezone = new DateTimeZone($timezone);
    }

    public function calendar(): CalendarSystem
    {
        return $this->calendar;
    }

    public function timezone(): DateTimeZone
    {
        return $this->timezone;
    }

    /** Date only, e.g. "1403/05/26" or "2024-08-16". */
    public function date(?DateTimeImmutable $value): string
    {
        if ($value === null) {
            return '—';
        }

        $local = $value->setTimezone($this->timezone);

        return $this->calendar === CalendarSystem::Jalali
            ? Jalalian::fromDateTime($local)->format('Y/m/d')
            : $local->format('Y-m-d');
    }

    /** Date and time, e.g. "1403/05/26 14:32". */
    public function dateTime(?DateTimeImmutable $value): string
    {
        if ($value === null) {
            return '—';
        }

        $local = $value->setTimezone($this->timezone);

        return $this->calendar === CalendarSystem::Jalali
            ? Jalalian::fromDateTime($local)->format('Y/m/d H:i')
            : $local->format('Y-m-d H:i');
    }

    /** Month and day, for chart axes. */
    public function shortDate(?DateTimeImmutable $value): string
    {
        if ($value === null) {
            return '';
        }

        $local = $value->setTimezone($this->timezone);

        return $this->calendar === CalendarSystem::Jalali
            ? Jalalian::fromDateTime($local)->format('m/d')
            : $local->format('m-d');
    }

    /**
     * Parses a date typed by an operator into the *start* of that day, in UTC.
     *
     * Accepts the active calendar's format first and then the other one, so a
     * pasted ISO date still works while the panel is in Jalali mode.
     */
    public function parseStartOfDay(?string $input): ?DateTimeImmutable
    {
        $date = $this->parse($input);

        return $date?->setTime(0, 0, 0)->setTimezone(new DateTimeZone('UTC'));
    }

    /**
     * Parses a date into the *last instant* of that day, in UTC. Filters are
     * inclusive of their end date, which is what "to 1403/05/26" means to
     * everybody except a programmer.
     */
    public function parseEndOfDay(?string $input): ?DateTimeImmutable
    {
        $date = $this->parse($input);

        return $date?->setTime(23, 59, 59)->setTimezone(new DateTimeZone('UTC'));
    }

    /** Formats a date for pre-filling a filter input. */
    public function forInput(?DateTimeImmutable $value): string
    {
        if ($value === null) {
            return '';
        }

        $local = $value->setTimezone($this->timezone);

        return $this->calendar === CalendarSystem::Jalali
            ? Jalalian::fromDateTime($local)->format('Y/m/d')
            : $local->format('Y-m-d');
    }

    /**
     * Converts Western digits to Persian ones for display. Never applied to
     * anything that will be parsed again.
     */
    public function digits(string $value): string
    {
        if ($this->locale !== Locale::Persian) {
            return $value;
        }

        return strtr($value, [
            '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
        ]);
    }

    private function parse(?string $input): ?DateTimeImmutable
    {
        $input = trim((string) $input);

        if ($input === '') {
            return null;
        }

        // Operators paste Persian digits from other systems all the time.
        $input = strtr($input, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);

        $normalised = str_replace(['-', '.'], '/', $input);

        if (preg_match('#^(\d{4})/(\d{1,2})/(\d{1,2})$#', $normalised, $matches) !== 1) {
            return null;
        }

        [, $year, $month, $day] = array_map('intval', $matches);

        // The year picks the calendar, not the setting.
        //
        // "2024/08/16" is a perfectly valid *Jalali* date — it just lands in
        // 2645 CE — so trying the configured calendar first and falling back on
        // failure would silently accept a pasted ISO date as a year six
        // centuries away. Real Jalali years run around 1200-1600 and real
        // Gregorian ones from 1700, so the ranges never overlap in a filter
        // anybody would type. The configured calendar still decides how dates
        // are *rendered* and what the placeholder suggests.
        $looksJalali = $year < self::CALENDAR_YEAR_BOUNDARY;

        return $looksJalali
            ? ($this->fromJalali($year, $month, $day) ?? $this->fromGregorian($year, $month, $day))
            : ($this->fromGregorian($year, $month, $day) ?? $this->fromJalali($year, $month, $day));
    }

    private function fromJalali(int $year, int $month, int $day): ?DateTimeImmutable
    {
        // The third argument makes the check strict about month lengths and
        // leap years, so "1403/12/30" is accepted only in years that have it.
        if (!CalendarUtils::checkDate($year, $month, $day, true)) {
            return null;
        }

        [$gregorianYear, $gregorianMonth, $gregorianDay] = CalendarUtils::toGregorian($year, $month, $day);

        return $this->fromGregorian($gregorianYear, $gregorianMonth, $gregorianDay);
    }

    private function fromGregorian(int $year, int $month, int $day): ?DateTimeImmutable
    {
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat(
            'Y-n-j H:i:s',
            sprintf('%04d-%d-%d 00:00:00', $year, $month, $day),
            $this->timezone,
        );

        return $date === false ? null : $date;
    }
}
