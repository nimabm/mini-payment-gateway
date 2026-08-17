<?php

declare(strict_types=1);

namespace App\Presentation\Api;

use App\Application\Gateway\DriverRegistry;
use App\Domain\Gateway\GatewayConfig;
use App\Domain\Gateway\GatewayRepository;
use App\Domain\Merchant\Merchant;
use App\Domain\Shared\Currency;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/v1/gateways
 *
 * Lets a merchant render its own gateway picker. Credentials are of course
 * never included.
 */
final readonly class ListGatewaysAction
{
    public function __construct(
        private GatewayRepository $gateways,
        private DriverRegistry $drivers,
    ) {
    }

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        /** @var Merchant $merchant */
        $merchant = $request->getAttribute(ApiAuthenticationMiddleware::ATTRIBUTE_MERCHANT);

        $gateways = array_values(array_filter(
            $this->gateways->findAssignedTo($merchant->id),
            static fn (GatewayConfig $gateway): bool => $gateway->isEnabled(),
        ));

        return ApiResponse::success([
            'gateways' => array_map(
                fn (GatewayConfig $gateway): array => [
                    'id' => $gateway->id->value,
                    'label' => $gateway->label(),
                    'provider' => $this->drivers->has($gateway->driver)
                        ? $this->drivers->get($gateway->driver)->displayName()
                        : $gateway->driver->value,
                    'sandbox' => $gateway->isSandbox(),
                    'currencies' => array_map(
                        static fn (Currency $currency): string => $currency->value,
                        $gateway->currencies(),
                    ),
                    'min_amount' => $gateway->minAmount(),
                    'max_amount' => $gateway->maxAmount(),
                ],
                $gateways,
            ),
        ]);
    }
}
