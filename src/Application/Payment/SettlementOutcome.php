<?php

declare(strict_types=1);

namespace App\Application\Payment;

enum SettlementOutcome: string
{
    case Settled = 'settled';
    case AlreadySettled = 'already_settled';
    case Failed = 'failed';

    /** The PSP could not be reached. Retry; do not tell the payer anything. */
    case Undetermined = 'undetermined';
}
