<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Repositories;

use Illuminate\Database\QueryException;
use KenDeNigerian\PayZephyr\Contracts\WebhookEventRepositoryInterface;
use KenDeNigerian\PayZephyr\Models\WebhookEvent;
use KenDeNigerian\PayZephyr\Traits\DetectsUniqueConstraintViolations;

final class EloquentWebhookEventRepository implements WebhookEventRepositoryInterface
{
    use DetectsUniqueConstraintViolations;

    public function recordIfNew(string $provider, string $eventKey): bool
    {
        try {
            WebhookEvent::create([
                'provider' => $provider,
                'event_key' => $eventKey,
            ]);

            return true;
        } catch (QueryException $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                return false;
            }

            throw $e;
        }
    }
}
