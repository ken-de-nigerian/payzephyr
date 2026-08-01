<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Models;

use Illuminate\Database\Eloquent\Model;
use KenDeNigerian\PayZephyr\Traits\HasConfigurableTableName;
use KenDeNigerian\PayZephyr\Traits\LogsToPaymentChannel;

/**
 * A record that a given (provider, event_key) webhook delivery has already
 * been processed. See ADR-0005 - this is what makes duplicate deliveries
 * (which gateways send routinely as part of their retry/at-least-once
 * semantics) a safe no-op for side effects beyond transaction status, such
 * as the subscription lifecycle events ProcessWebhook dispatches.
 *
 * @method static WebhookEvent create(array $attributes = [])
 *
 * @property int $id
 * @property string $provider
 * @property string $event_key
 */
final class WebhookEvent extends Model
{
    use HasConfigurableTableName;
    use LogsToPaymentChannel;

    /** @var array<int, string> */
    protected $fillable = [
        'provider',
        'event_key',
    ];

    protected $table = 'webhook_events';

    public function getConnectionName(): ?string
    {
        return parent::getConnectionName() ?? (app()->environment('testing') ? 'testing' : null);
    }

    protected function configuredTableNameKey(): string
    {
        return 'webhook.events.table';
    }
}
