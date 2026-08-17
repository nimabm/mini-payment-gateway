<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Application\Settings\CalendarSystem;
use App\Application\Settings\Locale;
use App\Application\Settings\SettingKey;
use App\Application\Settings\Settings;
use App\Domain\Admin\AdminUserRepository;
use App\Infrastructure\Audit\AuditLogger;
use App\Presentation\Support\TemplateRenderer;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * The settings page: panel language, calendar, timezone, and the global
 * sandbox switch.
 */
final readonly class SettingsController
{
    use ResolvesActor;

    public function __construct(
        private Settings $settings,
        private AdminUserRepository $users,
        private TemplateRenderer $renderer,
        private Session $session,
        private AuditLogger $audit,
    ) {
    }

    public function show(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderer->render(new Response(), 'admin/settings.html.twig', [
            'settings' => $this->settings->all(),
            'locales' => Locale::cases(),
            'calendars' => CalendarSystem::cases(),
            'timezones' => DateTimeZone::listIdentifiers(),
            'flash' => $this->session->pull('flash'),
            'csrfToken' => $this->session->csrfToken(),
        ]);
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $input = FormInput::fromRequest($request);

        $locale = Locale::fromStringOrDefault($input->string('locale'));
        $calendar = CalendarSystem::fromStringOrDefault($input->string('calendar'));

        $this->settings->set(SettingKey::LOCALE, $locale->value);
        $this->settings->set(SettingKey::CALENDAR, $calendar->value);
        $this->settings->set(SettingKey::TIMEZONE, $this->timezone($input->string('timezone')));
        $this->settings->set(SettingKey::PAGE_SIZE, (string) max(10, min(200, $input->int('page_size', 25))));
        $this->settings->set(SettingKey::BRAND_NAME, $input->string('brand_name', 'Payment Gateway'));
        $this->settings->set(SettingKey::FORCE_SANDBOX, $input->checkbox('force_sandbox') ? '1' : '0');

        // A session-level language override would otherwise mask the change the
        // operator just made and make the setting look broken.
        $this->session->forget('locale');
        $this->session->forget('calendar');

        $this->audit->record(
            $this->actor($request),
            'settings.updated',
            null,
            [
                'locale' => $locale->value,
                'calendar' => $calendar->value,
                'force_sandbox' => $input->checkbox('force_sandbox'),
            ],
            $this->ip($request),
        );

        $this->session->flash('settings.saved');

        return $this->redirect('/admin/settings');
    }

    /**
     * Stores the signed-in user's own language and calendar, leaving the
     * installation defaults alone.
     */
    public function updatePreferences(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->actor($request);
        $input = FormInput::fromRequest($request);

        if ($user !== null) {
            $user->setPreferences(
                Locale::tryFrom($input->string('locale')),
                CalendarSystem::tryFrom($input->string('calendar')),
            );

            $this->users->save($user);
        }

        $this->session->forget('locale');
        $this->session->forget('calendar');

        return $this->redirect('/admin/settings');
    }

    /**
     * Switches language for this session only — the header's language toggle.
     */
    public function switchLocale(ServerRequestInterface $request): ResponseInterface
    {
        $this->session->start();

        $locale = Locale::tryFrom((string) ($request->getQueryParams()['locale'] ?? ''));

        if ($locale !== null) {
            $this->session->set('locale', $locale->value);
        }

        $calendar = CalendarSystem::tryFrom((string) ($request->getQueryParams()['calendar'] ?? ''));

        if ($calendar !== null) {
            $this->session->set('calendar', $calendar->value);
        }

        $referer = $request->getHeaderLine('Referer');

        return $this->redirect($this->safeReturnPath($referer));
    }

    /**
     * Only ever returns a local path: reflecting a Referer straight into a
     * Location header is an open redirect.
     */
    private function safeReturnPath(string $referer): string
    {
        $path = parse_url($referer, PHP_URL_PATH);

        if (!is_string($path) || !str_starts_with($path, '/admin')) {
            return '/admin';
        }

        $query = parse_url($referer, PHP_URL_QUERY);

        return is_string($query) && $query !== '' ? $path . '?' . $query : $path;
    }

    private function timezone(string $value): string
    {
        return in_array($value, DateTimeZone::listIdentifiers(), true) ? $value : 'UTC';
    }
}
