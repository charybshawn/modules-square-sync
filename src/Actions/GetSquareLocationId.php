<?php

namespace Cultpantry\SquareSync\Actions;

use App\Actions\GetSiteSetting;

/**
 * Single resolver for which Square location this module syncs inventory
 * with. Every other class in this module that needs the location id calls
 * this rather than reading config('square-sync.location_id') directly --
 * see FetchSquareSyncData, ApplyInventoryCountFromSquare,
 * PushInventoryCountJob, PushStockToSquare, ProductObserver, and
 * ReconcileSquareInventory.
 *
 * Resolution order (checked in handle()):
 *   1. The site setting an admin sets from the Square Sync page -- see
 *      SquareSyncController::updateLocation().
 *   2. config('square-sync.location_id') (i.e. SQUARE_LOCATION_ID) as a
 *      fallback. This fallback is required, not just a nicety: existing
 *      deployments configure the location purely via .env and must keep
 *      working exactly as before until an admin visits the page and picks
 *      one explicitly. Only once the setting is saved does it take over.
 */
class GetSquareLocationId
{
    /**
     * Site setting key. Namespaced like the existing
     * PullSquareCatalogDelta::WATERMARK_KEY convention
     * ('square_sync.catalog_delta_watermark').
     */
    public const SETTING_KEY = 'square_sync.location_id';

    public function __construct(private readonly GetSiteSetting $getSetting) {}

    public function handle(): ?string
    {
        return $this->storedSetting() ?? config('square-sync.location_id');
    }

    /**
     * Where the value handle() would return actually came from, so the
     * admin UI can say "set via .env" vs. "set in-app" rather than leaving
     * an admin guessing why a field they didn't touch already has a value.
     *
     * @return 'setting'|'env'|null
     */
    public function source(): ?string
    {
        if (filled($this->storedSetting())) {
            return 'setting';
        }

        return filled(config('square-sync.location_id')) ? 'env' : null;
    }

    private function storedSetting(): ?string
    {
        $value = $this->getSetting->handle(self::SETTING_KEY);

        return filled($value) ? $value : null;
    }
}
