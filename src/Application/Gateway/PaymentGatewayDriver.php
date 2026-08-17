<?php

declare(strict_types=1);

namespace App\Application\Gateway;

use App\Domain\Gateway\DriverName;
use App\Domain\Shared\Currency;

/**
 * The single seam every PSP integration goes through.
 *
 * Adding a bank means writing one class against this interface and registering
 * it. No other file in the system changes — that property is the entire point
 * of this project, so keep this interface small and keep PSP vocabulary
 * (Authority, RefID, token, …) behind it.
 */
interface PaymentGatewayDriver
{
    public function name(): DriverName;

    /** Human readable name for the admin panel, e.g. "ZarinPal". */
    public function displayName(): string;

    public function supports(Currency $currency): bool;

    /**
     * Credential keys this driver expects, used to render its settings form.
     *
     * @return list<CredentialField>
     */
    public function credentialFields(): array;

    /** Opens a transaction and returns where to send the payer. */
    public function purchase(PurchaseRequest $request): PurchaseResponse;

    /** Confirms — or denies — that the payer was charged. */
    public function verify(VerificationRequest $request): VerificationResponse;

    /**
     * Asks the PSP about a transaction we are unsure of, without settling it.
     * Used by reconciliation to rescue payments stuck in limbo.
     */
    public function inquire(VerificationRequest $request): VerificationResponse;

    /** Whether {@see refund()} does anything on this PSP. */
    public function supportsRefunds(): bool;

    public function refund(RefundRequest $request): RefundResponse;
}
