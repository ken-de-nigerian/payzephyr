<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Models;

use ArrayObject;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use KenDeNigerian\PayZephyr\Contracts\StatusNormalizerInterface;
use KenDeNigerian\PayZephyr\Enums\PaymentStatus;
use KenDeNigerian\PayZephyr\Services\StatusNormalizer;
use KenDeNigerian\PayZephyr\Traits\HasConfigurableTableName;
use KenDeNigerian\PayZephyr\Traits\LogsToPaymentChannel;
use Throwable;

/**
 * @method static Builder<PaymentTransaction> where(string $column, mixed $operator = null, mixed $value = null)
 * @method static PaymentTransaction create(array<string, mixed> $attributes = [])
 * @method static PaymentTransaction|null first(array<int, string>|string $columns = ['*'])
 * @method static Builder<PaymentTransaction> lockForUpdate()
 * @method static Builder<PaymentTransaction> update(array<string, mixed> $attributes = [])
 * @method static Builder<PaymentTransaction> delete()
 *
 * @property string $reference
 * @property string $provider
 * @property string $status
 * @property int $amount
 * @property string $currency
 * @property string $email
 * @property string|null $channel
 * @property array<string, mixed>|ArrayObject<string, mixed>|null $metadata
 * @property array<string, mixed>|null $customer
 * @property Carbon|null $paid_at
 */
final class PaymentTransaction extends Model
{
    use HasConfigurableTableName;
    use LogsToPaymentChannel;

    /** @var array<int, string> */
    protected $fillable = [
        'reference',
        'provider',
        'status',
        'amount',
        'currency',
        'email',
        'channel',
        'metadata',
        'customer',
        'paid_at',
    ];

    protected $table = 'payment_transactions';

    protected function configuredTableNameKey(): string
    {
        return 'logging.table';
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'metadata' => AsArrayObject::class,
            'customer' => AsArrayObject::class,
            'paid_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public static function setTableName(string $table): void
    {
        $instance = new self;
        $instance->table = $table;
    }

    public function getConnectionName(): ?string
    {
        return parent::getConnectionName() ?? (app()->environment('testing') ? 'testing' : null);
    }

    /**
     * @param  Builder<PaymentTransaction>  $query
     * @return Builder<PaymentTransaction>
     */
    public function scopeSuccessful(Builder $query): Builder
    {
        /** @var Builder<self> */
        return $query->whereIn('status', self::statusVocabulary(PaymentStatus::SUCCESS->value));
    }

    /**
     * @param  Builder<PaymentTransaction>  $query
     * @return Builder<PaymentTransaction>
     */
    public function scopeFailed(Builder $query): Builder
    {
        $statuses = array_unique(array_merge(
            self::statusVocabulary(PaymentStatus::FAILED->value),
            [PaymentStatus::CANCELLED->value],
        ));

        /** @var Builder<self> */
        return $query->whereIn('status', array_values($statuses));
    }

    /**
     * @param  Builder<PaymentTransaction>  $query
     * @return Builder<PaymentTransaction>
     */
    public function scopePending(Builder $query): Builder
    {
        /** @var Builder<self> */
        return $query->whereIn('status', self::statusVocabulary(PaymentStatus::PENDING->value));
    }

    public function isSuccessful(): bool
    {
        return $this->normalizedStatus()?->isSuccessful() ?? false;
    }

    public function isFailed(): bool
    {
        return $this->normalizedStatus()?->isFailed() ?? false;
    }

    public function isPending(): bool
    {
        return $this->normalizedStatus()?->isPending() ?? false;
    }

    /**
     * This row's status as a PaymentStatus, or null when the provider sent
     * something the package has no meaning for.
     *
     * The container is preferred so an application's registered provider
     * mappings apply, with the static vocabulary as the fallback for a model
     * used outside a booted Laravel application.
     */
    private function normalizedStatus(): ?PaymentStatus
    {
        return PaymentStatus::tryFromString($this->resolveNormalizedStatus());
    }

    private function resolveNormalizedStatus(): string
    {
        try {
            if (function_exists('app')) {
                // The row knows which provider wrote it, and that decides what
                // an ambiguous status means: APPROVED is success for Square
                // and pending for PayPal. Normalizing without it silently
                // answered "none of the above" for any provider-specific
                // status that reached the column.
                return app(StatusNormalizerInterface::class)->normalize($this->status, $this->provider);
            }
        } catch (Throwable) {
            // fall through to the static vocabulary
        }

        return StatusNormalizer::normalizeStatic($this->status);
    }

    /**
     * Every stored status that means $normalized, so a query scope and its
     * matching predicate cannot disagree about the same row.
     *
     * @return array<int, string>
     */
    private static function statusVocabulary(string $normalized): array
    {
        try {
            if (function_exists('app')) {
                $normalizer = app(StatusNormalizerInterface::class);

                if ($normalizer instanceof StatusNormalizer) {
                    return $normalizer->statusesNormalizingTo($normalized);
                }
            }
        } catch (Throwable) {
            // fall through to the static vocabulary
        }

        return (new StatusNormalizer)->statusesNormalizingTo($normalized);
    }
}
