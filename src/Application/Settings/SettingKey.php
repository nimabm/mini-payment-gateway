<?php

declare(strict_types=1);

namespace App\Application\Settings;

/**
 * Every runtime setting, in one place, so a typo cannot silently create a
 * second setting that nothing reads.
 */
final class SettingKey
{
    /** Default admin panel language: "fa" or "en". */
    public const LOCALE = 'panel.locale';

    /** Default calendar: "jalali" or "gregorian". */
    public const CALENDAR = 'panel.calendar';

    /** IANA timezone used to render timestamps in the panel. */
    public const TIMEZONE = 'panel.timezone';

    /** Rows per page in panel tables. */
    public const PAGE_SIZE = 'panel.page_size';

    /**
     * Master sandbox switch. When on, every gateway behaves as a sandbox
     * connection regardless of its own setting — a safety net for staging
     * environments restored from a production database.
     */
    public const FORCE_SANDBOX = 'gateways.force_sandbox';

    /** Business name shown on the checkout page. */
    public const BRAND_NAME = 'checkout.brand_name';

    private function __construct()
    {
    }
}
