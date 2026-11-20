<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Contracts;

/**
 * Marks a driver that can split a charge: send it to a seller's subaccount
 * while the platform keeps a fee (Payment::split() / Payment::splitCode()).
 *
 * A charge carrying a split is never sent to a driver without this interface.
 * In a fallback chain such drivers are skipped, and when none can split the
 * charge fails - money meant for a seller never silently lands in the
 * platform's own account instead.
 */
interface SupportsSplitPaymentsInterface {}
