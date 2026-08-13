<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Contracts;

use KenDeNigerian\PayZephyr\DataObjects\SubscriptionRequestDTO;
use KenDeNigerian\PayZephyr\DataObjects\SubscriptionResponseDTO;

/**
 * Optional interface for drivers to hook into subscription lifecycle events.
 *
 * Drivers can implement this interface if they need to perform custom logic
 * at specific points in the subscription lifecycle. All methods are optional
 * and can be left empty if not needed.
 */
interface SubscriptionLifecycleHooks
{
    /**
     * Called before a subscription is created.
     *
     * This hook allows you to modify the subscription request before it's sent
     * to the payment provider. You can add metadata, modify amounts, or perform
     * any pre-creation validation.
     *
     * @return SubscriptionRequestDTO The (potentially modified) request DTO
     */
    public function beforeSubscriptionCreate(SubscriptionRequestDTO $request): SubscriptionRequestDTO;

    /**
     * Called after a subscription is successfully created.
     *
     * This hook is called immediately after the subscription is created and
     * the response is received. You can use this to trigger notifications,
     * update local records, or perform post-creation tasks.
     */
    public function afterSubscriptionCreate(SubscriptionResponseDTO $response): void;

    /**
     * Called before a subscription is canceled.
     *
     * This hook allows you to perform any pre-cancellation logic, such as
     * checking if cancellation is allowed, sending warnings, or logging.
     */
    public function beforeSubscriptionCancel(string $subscriptionCode): void;

    /**
     * Called after a subscription is successfully canceled.
     *
     * This hook is called immediately after the subscription is canceled.
     * You can use this to trigger notifications, update local records,
     * or perform cleanup tasks.
     */
    public function afterSubscriptionCancel(SubscriptionResponseDTO $response): void;

    /**
     * Called before a subscription renewal payment is processed.
     *
     * This hook is called when a subscription is about to be renewed.
     * You can use this to validate the renewal, check account status,
     * or perform any pre-renewal checks.
     */
    public function beforeSubscriptionRenewal(string $subscriptionCode): void;

    /**
     * Called after a subscription renewal payment is successfully processed.
     *
     * This hook is called when a subscription renewal payment succeeds.
     * You can use this to extend access, send receipts, or update records.
     *
     * @param  string  $invoiceReference  The reference for the renewal invoice/payment
     */
    public function afterSubscriptionRenewal(string $subscriptionCode, string $invoiceReference): void;

    /**
     * Called when a subscription renewal payment fails.
     *
     * This hook is called when a subscription renewal payment fails.
     * You can use this to send failure notifications, update account status,
     * or trigger retry logic.
     *
     * @param  string  $reason  The reason for the payment failure
     */
    public function onSubscriptionRenewalFailed(string $subscriptionCode, string $reason): void;
}
