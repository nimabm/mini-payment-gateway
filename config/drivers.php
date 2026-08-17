<?php

declare(strict_types=1);

use App\Infrastructure\Gateway\Fake\FakeDriver;
use App\Infrastructure\Gateway\ZarinPal\ZarinPalDriver;

/**
 * The registered payment providers.
 *
 * To add a bank:
 *
 *   1. Write a class implementing `App\Application\Gateway\PaymentGatewayDriver`
 *      under `src/Infrastructure/Gateway/<Provider>/`.
 *   2. Add it to this list.
 *   3. Configure a connection for it in the admin panel.
 *
 * There is no step 4. Nothing else in the codebase needs to change — see
 * `docs/ADDING_A_GATEWAY.md`.
 *
 * @return list<class-string<App\Application\Gateway\PaymentGatewayDriver>>
 */
return [
    ZarinPalDriver::class,
    FakeDriver::class,
];
