<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Contracts;

use KenDeNigerian\PayZephyr\DataObjects\RefundRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\RefundResponseDTO;

/**
 * Interface for refund functionality
 *
 * Not all drivers will support refunds - drivers can implement this
 * interface to indicate refund support.
 */
interface SupportsRefundsInterface
{
    /**
     * Refund a transaction, in full or in part.
     */
    public function refund(RefundRequestDTO $request): RefundResponseDTO;

    /**
     * Fetch details of a previously issued refund.
     */
    public function fetchRefund(string $refundReference): RefundResponseDTO;
}
