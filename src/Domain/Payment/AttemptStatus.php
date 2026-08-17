<?php

declare(strict_types=1);

namespace App\Domain\Payment;

/**
 * The outcome of one trip to one PSP.
 */
enum AttemptStatus: string
{
    /** The PSP accepted the request and gave us somewhere to send the payer. */
    case Started = 'started';

    /** The payer came back from the PSP; verification has not run yet. */
    case Returned = 'returned';

    /** The PSP confirmed the money moved. */
    case Succeeded = 'succeeded';

    /** The PSP rejected the request, or the payer abandoned or canceled it. */
    case Failed = 'failed';

    public function isFinal(): bool
    {
        return $this === self::Succeeded || $this === self::Failed;
    }
}
