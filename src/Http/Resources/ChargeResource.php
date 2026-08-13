<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use KenDeNigerian\PayZephyr\DataObjects\ChargeResponseDTO;

final class ChargeResource extends JsonResource
{
    /**
     * Transform resource to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ChargeResponseDTO $resource */
        $resource = $this->resource;

        return [
            'reference' => $resource->reference,
            'authorization_url' => $resource->authorizationUrl,
            'status' => $resource->status,
            'provider' => $resource->provider ?? null,
            'amount' => [
                'value' => $resource->metadata['amount'] ?? null,
                'currency' => $resource->metadata['currency'] ?? null,
            ],
            'metadata' => $resource->metadata,
            'created_at' => now()->toIso8601String(),
        ];
    }
}
