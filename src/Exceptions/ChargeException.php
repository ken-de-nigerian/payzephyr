<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Exceptions;

use KenDeNigerian\PayZephyr\Traits\DetectsAmbiguousProviderOutcome;

/**
 * Class ChargeException
 *
 * Thrown when payment charge fails
 */
final class ChargeException extends PaymentException
{
    use DetectsAmbiguousProviderOutcome;
}
