<?php

declare(strict_types=1);

namespace App\Presentation\Admin;

use App\Domain\Admin\AdminUser;
use App\Domain\Admin\AdminUserRepository;
use App\Domain\Admin\WeakPassword;
use App\Infrastructure\Audit\AuditLogger;
use App\Infrastructure\Security\RateLimiter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Lets a signed-in operator change their own password.
 *
 * Separate from {@see SettingsController} because that page configures the
 * installation, and this changes a credential — different blast radius, and
 * this one needs throttling and an audit trail.
 */
final readonly class PasswordController
{
    use ResolvesActor;

    public function __construct(
        private AdminUserRepository $users,
        private Session $session,
        private RateLimiter $rateLimiter,
        private AuditLogger $audit,
    ) {
    }

    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $user = $this->actor($request);

        if ($user === null) {
            return $this->redirect('/admin/login');
        }

        // CSRF is already enforced for every state-changing admin request by
        // AdminAuthMiddleware, which refuses them with a 403 before they reach
        // a controller.
        $input = FormInput::fromRequest($request);

        // The current password is a credential like any other, so guessing at it
        // from an already-open session is throttled too.
        if (!$this->rateLimiter->allow('password:' . $user->id->value, 10)) {
            return $this->fail('password.throttled');
        }

        if (!$user->verifyPassword($input->string('current_password'))) {
            $this->audit->record($user, 'admin.password_change_failed', $user->email, [], $this->ip($request));

            return $this->fail('password.wrong_current');
        }

        $new = $input->string('new_password');

        if ($new !== $input->string('confirm_password')) {
            return $this->fail('password.mismatch');
        }

        try {
            $user->changePassword($new);
        } catch (WeakPassword) {
            return $this->fail('password.too_short', ['min' => AdminUser::MINIMUM_PASSWORD_LENGTH]);
        }

        $this->users->save($user);

        // A new session id after a credential change, so a session cookie
        // captured beforehand is worthless.
        $this->session->regenerate();

        $this->audit->record($user, 'admin.password_changed', $user->email, [], $this->ip($request));
        $this->session->flash('password.changed');

        return $this->redirect('/admin/settings');
    }

    /**
     * @param array<string, string|int|float> $replacements
     */
    private function fail(string $messageKey, array $replacements = []): ResponseInterface
    {
        $this->session->flash($messageKey, 'error', $replacements);

        return $this->redirect('/admin/settings');
    }
}
