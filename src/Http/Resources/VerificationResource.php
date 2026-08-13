<?php

declare(strict_types=1);

namespace KenDeNigerian\PayZephyr\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;
use KenDeNigerian\PayZephyr\DataObjects\VerificationResponseDTO;

final class VerificationResource extends JsonResource
{
    /**
     * Transform resource to array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var VerificationResponseDTO $resource */
        $resource = $this->resource;

        return [
            'reference' => $resource->reference,
            'status' => $resource->status,
            'provider' => $resource->provider ?? null,
            'channel' => $resource->channel,
            'amount' => [
                'value' => $resource->amount,
                'currency' => $resource->currency,
            ],
            'paid_at' => $resource->paidAt ? Carbon::parse($resource->paidAt)->toIso8601String() : null,
            'metadata' => $resource->metadata,
            'verified_at' => now()->toIso8601String(),
        ];
    }
}
