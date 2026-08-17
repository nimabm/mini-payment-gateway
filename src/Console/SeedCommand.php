<?php

declare(strict_types=1);

namespace App\Console;

use App\Domain\Admin\AdminUser;
use App\Domain\Admin\AdminUserRepository;
use App\Domain\Gateway\DriverName;
use App\Domain\Gateway\GatewayConfig;
use App\Domain\Merchant\ApiCredential;
use App\Domain\Merchant\ApiCredentialRepository;
use App\Domain\Merchant\Merchant;
use App\Domain\Merchant\MerchantRepository;
use App\Domain\Shared\Clock;
use App\Domain\Shared\Currency;
use App\Infrastructure\Persistence\SqliteGatewayRepository;
use App\Infrastructure\Security\ApiKeyFactory;
use DateTimeImmutable;

/**
 * Brings a fresh installation to a state you can actually click around in:
 * an administrator, a demo website with working API keys, and a Simulator
 * gateway so a payment can be taken end to end before any bank is involved.
 *
 * Idempotent — running it twice changes nothing.
 */
final readonly class SeedCommand implements Command
{
    public function __construct(
        private AdminUserRepository $users,
        private MerchantRepository $merchants,
        private ApiCredentialRepository $credentials,
        private SqliteGatewayRepository $gateways,
        private ApiKeyFactory $keys,
        private Clock $clock,
    ) {
    }

    public function __invoke(array $arguments): int
    {
        $now = $this->clock->now();

        $this->seedAdmin($now);
        $merchant = $this->seedMerchant($now);
        $this->seedGateways($merchant, $now);

        return 0;
    }

    private function seedAdmin(DateTimeImmutable $now): void
    {
        if ($this->users->count() > 0) {
            echo "Admin user already exists — skipped.\n";

            return;
        }

        $password = bin2hex(random_bytes(9));

        $this->users->save(AdminUser::register(
            'admin@example.com',
            'Administrator',
            $password,
            $now,
        ));

        echo "Admin user created:\n";
        echo "  Email:    admin@example.com\n";
        echo sprintf("  Password: %s\n", $password);
    }

    private function seedMerchant(DateTimeImmutable $now): Merchant
    {
        $existing = $this->merchants->findBySlug('demo-shop');

        if ($existing !== null) {
            echo "Demo website already exists — skipped.\n";

            return $existing;
        }

        $merchant = Merchant::register(
            name: 'Demo Shop',
            slug: 'demo-shop',
            defaultCurrency: Currency::IRT,
            now: $now,
            webhookUrl: null,
            // Empty allowlists: convenient for a first run, and the panel warns
            // you to fill them in before going live.
            allowedCallbackHosts: [],
            ipAllowlist: [],
        );

        $this->merchants->save($merchant);

        $pair = $this->keys->create();

        $this->credentials->save(ApiCredential::issue(
            $merchant->id,
            $pair['keyId'],
            $pair['secret'],
            'seed',
            $now,
        ));

        echo "Demo website created:\n";
        echo sprintf("  Key ID: %s\n", $pair['keyId']);
        echo sprintf("  Secret: %s\n", $pair['secret']);

        return $merchant;
    }

    private function seedGateways(Merchant $merchant, DateTimeImmutable $now): void
    {
        if ($this->gateways->all() !== []) {
            echo "Gateways already configured — skipped.\n";

            return;
        }

        $simulator = GatewayConfig::configure(
            driver: DriverName::fromString('fake'),
            label: 'Simulator',
            credentials: [],
            currencies: [Currency::IRT, Currency::IRR, Currency::USD, Currency::EUR],
            sandbox: true,
            now: $now,
            priority: 10,
        );
        $simulator->enable();
        $this->gateways->save($simulator);

        // Created in sandbox and left disabled: nobody should be one seed away
        // from pointing a live ZarinPal account at a demo install.
        $zarinpal = GatewayConfig::configure(
            driver: DriverName::fromString('zarinpal'),
            label: 'ZarinPal (sandbox)',
            credentials: [],
            currencies: [Currency::IRT, Currency::IRR],
            sandbox: true,
            now: $now,
            priority: 20,
        );
        $this->gateways->save($zarinpal);

        $this->gateways->assignToMerchant($merchant->id, [$simulator->id, $zarinpal->id]);

        echo "Gateways created: Simulator (enabled), ZarinPal (sandbox, disabled).\n";
        echo "Add your ZarinPal merchant id in the panel, then enable it.\n";
    }
}
