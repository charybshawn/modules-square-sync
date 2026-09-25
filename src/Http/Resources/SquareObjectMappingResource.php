<?php

namespace Cultpantry\SquareSync\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the admin Square Sync mapping table. The local item is
 * expected to already be resolved in bulk by the caller (FetchSquareSyncData
 * calls setLocalItem() for the whole page) -- otherwise localItem() falls
 * back to one LocalCatalog lookup per row. An archived item still renders
 * its last-known title/sku.
 */
class SquareObjectMappingResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->mappable_id,
            'product_title' => $this->localItem()?->title,
            'product_sku' => $this->localItem()?->sku,
            'square_object_id' => $this->square_object_id,
            'square_object_type' => $this->square_object_type,
            'sync_status' => $this->sync_status,
            'verification_status' => $this->verification_status,
            'verified_at' => $this->verified_at?->toIso8601String(),
            'last_pushed_at' => $this->last_pushed_at?->toIso8601String(),
            'last_pulled_at' => $this->last_pulled_at?->toIso8601String(),
        ];
    }
}
