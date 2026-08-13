<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Exceptions;

use KenDeNigerian\PayZephyr\Traits\DetectsAmbiguousProviderOutcome;

/**
 * Class RefundException
 *
 * Thrown when refund operations fail.
 */
final class RefundException extends PaymentException
{
    use DetectsAmbiguousProviderOutcome;
}
