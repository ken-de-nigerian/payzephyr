<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use KenDeNigerian\PayZephyr\DataObjects\PlanResponseDTO;

final class PlanResource extends JsonResource
{
    /**
     * Transform resource to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PlanResponseDTO $resource */
        $resource = $this->resource;

        return [
            'plan_code' => $resource->planCode,
            'name' => $resource->name,
            'amount' => [
                'value' => $resource->amount,
                'currency' => $resource->currency,
            ],
            'interval' => $resource->interval,
            'description' => $resource->description,
            'invoice_limit' => $resource->invoiceLimit,
            'metadata' => $resource->metadata,
            'provider' => $resource->provider ?? null,
            'created_at' => now()->toIso8601String(),
        ];
    }
}
