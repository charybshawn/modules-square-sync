<?php

namespace Cultpantry\SquareSync\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the admin Square Sync mapping table. mappable is expected to
 * already be eager-loaded by the caller (FetchSquareSyncData) -- this
 * resource never touches the relation lazily, both because
 * Model::preventLazyLoading() is on outside production and because a
 * soft-deleted product should still render its last-known title/sku here
 * rather than blow up.
 */
class SquareObjectMappingResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->mappable_id,
            'product_title' => $this->mappable?->title,
            'product_sku' => $this->mappable?->sku,
            'square_object_id' => $this->square_object_id,
            'square_object_type' => $this->square_object_type,
            'sync_status' => $this->sync_status,
            'last_pushed_at' => $this->last_pushed_at?->toIso8601String(),
            'last_pulled_at' => $this->last_pulled_at?->toIso8601String(),
        ];
    }
}
