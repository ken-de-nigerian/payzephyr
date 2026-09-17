<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Traits;

use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Stripe\Exception\ApiConnectionException;

/**
 * Shared detection of "the provider's outcome is genuinely unknown".
 *
 * The distinction this draws is the single most safety-critical one in the
 * package: a request the provider could not possibly have acted on is safe to
 * retry or fail over, while a request that was transmitted but whose response
 * was lost may already have moved real money. Only the latter is ambiguous.
 *
 * The exception chain is walked rather than inspected one level deep, because
 * how deeply the original network exception is buried varies by operation:
 * a charge surfaces ChargeException -> RequestException, while a refund
 * surfaces RefundException -> ChargeException -> RequestException (the shared
 * AbstractDriver::makeRequest() wraps every GuzzleException in a
 * ChargeException before the refund trait re-wraps it).
 */
trait DetectsAmbiguousProviderOutcome
{
    /**
     * Whether the underlying failure means the outcome with the provider is
     * genuinely unknown - the request may have already reached and been
     * processed, but no usable response ever came back - as opposed to a
     * failure the provider could not have acted on (the connection was never
     * established) or one it actively responded to (a definitive 4xx/5xx).
     *
     * Callers must never treat an ambiguous outcome as safe to retry against
     * the same or a different provider: doing so risks charging or refunding
     * the customer twice.
     */
    public function isAmbiguousProviderOutcome(): bool
    {
        $current = $this->getPrevious();

        while ($current !== null) {
            if ($current instanceof ConnectException) {
                return false;
            }

            if ($current instanceof RequestException) {
                return $current->getResponse() === null;
            }

            if ($current instanceof ApiConnectionException) {
                return true;
            }

            $current = $current->getPrevious();
        }

        return false;
    }
}
