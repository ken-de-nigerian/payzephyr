<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Exceptions;

/**
 * Class SubaccountException
 *
 * Thrown when a subaccount or bank operation fails: listing banks, resolving
 * an account number, or creating, updating or fetching a subaccount.
 */
final class SubaccountException extends PaymentException {}
