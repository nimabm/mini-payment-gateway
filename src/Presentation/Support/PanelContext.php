<?php

declare(strict_types=1);

namespace App\Presentation\Support;

use App\Application\Settings\CalendarSystem;
use App\Application\Settings\Locale;
use App\Application\Settings\SettingKey;
use App\Application\Settings\Settings;
use App\Domain\Admin\AdminUser;

/**
 * Resolves the language and calendar for the current request.
 *
 * Three levels, most specific first:
 *
 *   1. A per-session override — the language switcher in the header.
 *   2. The signed-in user's saved preference.
 *   3. The installation-wide default from Settings.
 *
 * This is what lets one installation serve a Persian-speaking finance team and
 * an English-speaking developer at the same time without either of them
 * changing a global.
 */
final class PanelContext
{
    private ?Locale $locale = null;
    private ?CalendarSystem $calendar = null;
    private ?AdminUser $user = null;

    public function __construct(
        private readonly Settings $settings,
        private readonly Translator $translator,
        private readonly DateFormatter $dateFormatter,
    ) {
    }

    /**
     * @param array<string, mixed> $session
     */
    public function resolve(?AdminUser $user, array $session): void
    {
        $this->user = $user;

        $sessionLocale = is_string($session['locale'] ?? null) ? $session['locale'] : null;
        $sessionCalendar = is_string($session['calendar'] ?? null) ? $session['calendar'] : null;

        $this->locale = Locale::tryFrom((string) $sessionLocale)
            ?? $user?->locale()
            ?? Locale::fromStringOrDefault($this->settings->get(SettingKey::LOCALE));

        $this->calendar = CalendarSystem::tryFrom((string) $sessionCalendar)
            ?? $user?->calendar()
            ?? CalendarSystem::fromStringOrDefault($this->settings->get(SettingKey::CALENDAR));

        $this->translator->setLocale($this->locale);
        $this->dateFormatter->configure($this->calendar, $this->locale, $this->timezone());
    }

    public function locale(): Locale
    {
        // Public pages — checkout, the callback, the simulator — never go
        // through the admin middleware, so they resolve lazily against the
        // installation defaults rather than rendering in a hardcoded language.
        $this->resolveDefaultsOnce();

        return $this->locale ?? Locale::Persian;
    }

    public function calendar(): CalendarSystem
    {
        $this->resolveDefaultsOnce();

        return $this->calendar ?? CalendarSystem::Jalali;
    }

    public function user(): ?AdminUser
    {
        return $this->user;
    }

    public function timezone(): string
    {
        return $this->settings->get(SettingKey::TIMEZONE, 'UTC') ?? 'UTC';
    }

    public function pageSize(): int
    {
        return max(10, min(200, (int) $this->settings->get(SettingKey::PAGE_SIZE, '25')));
    }

    public function direction(): string
    {
        return $this->locale()->direction();
    }

    public function brandName(): string
    {
        return $this->settings->get(SettingKey::BRAND_NAME, 'Payment Gateway') ?? 'Payment Gateway';
    }

    public function isSandboxForced(): bool
    {
        return $this->settings->get(SettingKey::FORCE_SANDBOX, '0') === '1';
    }

    private function resolveDefaultsOnce(): void
    {
        if ($this->locale !== null) {
            return;
        }

        $this->resolve(null, []);
    }
}
