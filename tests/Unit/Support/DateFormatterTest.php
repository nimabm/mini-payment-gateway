<?php

declare(strict_types=1);

namespace App\Tests\Unit\Support;

use App\Application\Settings\CalendarSystem;
use App\Application\Settings\Locale;
use App\Presentation\Support\DateFormatter;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The calendar setting decides how report filters are interpreted, so getting
 * this wrong silently reports the wrong period. These tests pin both
 * directions: rendering and parsing.
 */
#[CoversClass(DateFormatter::class)]
final class DateFormatterTest extends TestCase
{
    #[Test]
    public function it_renders_jalali_dates(): void
    {
        $formatter = new DateFormatter(CalendarSystem::Jalali, Locale::English, 'Asia/Tehran');

        // 2024-08-16 is 1403-05-26 in the Jalali calendar.
        self::assertSame('1403/05/26', $formatter->date($this->utc('2024-08-16 12:00:00')));
    }

    #[Test]
    public function it_renders_gregorian_dates(): void
    {
        $formatter = new DateFormatter(CalendarSystem::Gregorian, Locale::English, 'Asia/Tehran');

        self::assertSame('2024-08-16', $formatter->date($this->utc('2024-08-16 12:00:00')));
    }

    #[Test]
    public function it_parses_a_jalali_filter_into_the_right_utc_range(): void
    {
        $formatter = new DateFormatter(CalendarSystem::Jalali, Locale::Persian, 'Asia/Tehran');

        $start = $formatter->parseStartOfDay('1403/05/26');
        $end = $formatter->parseEndOfDay('1403/05/26');

        self::assertNotNull($start);
        self::assertNotNull($end);

        // Tehran is UTC+3:30, so local midnight is 20:30 UTC the day before.
        self::assertSame('2024-08-15 20:30:00', $start->format('Y-m-d H:i:s'));
        self::assertSame('2024-08-16 20:29:59', $end->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function it_parses_a_gregorian_filter_into_the_right_utc_range(): void
    {
        $formatter = new DateFormatter(CalendarSystem::Gregorian, Locale::English, 'Asia/Tehran');

        $start = $formatter->parseStartOfDay('2024-08-16');

        self::assertNotNull($start);
        self::assertSame('2024-08-15 20:30:00', $start->format('Y-m-d H:i:s'));
    }

    /**
     * Operators paste ISO dates into a Jalali-configured panel constantly.
     *
     * "2024/08/16" is a valid Jalali date as well — it lands in 2645 CE — so
     * this is not a fallback-on-failure case. The year itself has to decide.
     */
    #[Test]
    public function a_gregorian_year_is_read_as_gregorian_even_in_jalali_mode(): void
    {
        $formatter = new DateFormatter(CalendarSystem::Jalali, Locale::Persian, 'Asia/Tehran');

        $parsed = $formatter->parseStartOfDay('2024-08-16');

        self::assertNotNull($parsed);
        self::assertSame('2024-08-15 20:30:00', $parsed->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function a_jalali_year_is_read_as_jalali_even_in_gregorian_mode(): void
    {
        $formatter = new DateFormatter(CalendarSystem::Gregorian, Locale::English, 'Asia/Tehran');

        $parsed = $formatter->parseStartOfDay('1403/05/26');

        self::assertNotNull($parsed);
        self::assertSame('2024-08-15 20:30:00', $parsed->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function it_accepts_persian_digits(): void
    {
        $formatter = new DateFormatter(CalendarSystem::Jalali, Locale::Persian, 'Asia/Tehran');

        self::assertEquals(
            $formatter->parseStartOfDay('1403/05/26'),
            $formatter->parseStartOfDay('۱۴۰۳/۰۵/۲۶'),
        );
    }

    #[Test]
    public function it_returns_null_for_unparseable_input(): void
    {
        $formatter = new DateFormatter(CalendarSystem::Jalali, Locale::Persian, 'Asia/Tehran');

        self::assertNull($formatter->parseStartOfDay(''));
        self::assertNull($formatter->parseStartOfDay('not a date'));
        self::assertNull($formatter->parseStartOfDay('1403/13/45'));
    }

    #[Test]
    public function it_localises_digits_only_for_persian(): void
    {
        $persian = new DateFormatter(CalendarSystem::Jalali, Locale::Persian, 'Asia/Tehran');
        $english = new DateFormatter(CalendarSystem::Gregorian, Locale::English, 'UTC');

        self::assertSame('۱۲۳', $persian->digits('123'));
        self::assertSame('123', $english->digits('123'));
    }

    private function utc(string $value): DateTimeImmutable
    {
        return new DateTimeImmutable($value, new DateTimeZone('UTC'));
    }
}
