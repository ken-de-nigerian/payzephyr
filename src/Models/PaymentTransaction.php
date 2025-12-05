<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @method static create(array $array)
 * @method static where(string $string, mixed $reference)
 */
class PaymentTransaction extends Model
{
    protected $guarded = [];

    public function getTable()
    {
        return config('payments.logging.table', 'payment_transactions');
    }
}
