<?php

namespace Cultpantry\SquareSync\Actions;

use App\Actions\GetSiteSetting;
use App\Actions\UpdateSiteSetting;
use Cultpantry\SquareSync\Contracts\AuditLog;
use Cultpantry\SquareSync\Models\SquareObjectMapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Square's sandbox and production are separate Square accounts: every
 * catalog id, location id and change-log position this module stores is
 * only meaningful in the environment it came from. When SQUARE_ENVIRONMENT
 * changes, all of it is reset rather than left pointing at objects that
 * don't exist -- every product is unlinked (soft delete, so the history
 * survives), the in-app sync location is cleared, and the catalog,
 * inventory and sales watermarks restart at "now" so the new account's
 * past isn't replayed as if it had just happened.
 *
 * Runs before every Square API call (SquareClient::request()), before
 * anything is queued to push (ShouldSyncToSquare), and on the admin page
 * load, so a stale link is never acted on. The check itself is one cached
 * setting read; the reset happens once per switch.
 *
 * The very first run after installing this has no remembered environment
 * yet -- it just records the current one, since existing links were made
 * against it.
 */
class ResetOnEnvironmentChange
{
    public const SETTING_KEY = 'square_sync.environment';

    public function __construct(
        private readonly GetSiteSetting $getSetting,
        private readonly UpdateSiteSetting $updateSetting,
        private readonly AuditLog $auditLog,
    ) {}

    /**
     * @return bool true if this call reset the module for a new environment
     */
    public function handle(): bool
    {
        $current = (string) config('square-sync.environment');
        $remembered = $this->getSetting->handle(self::SETTING_KEY);

        if ($remembered === $current) {
            return false;
        }

        // Two requests can notice the switch at once; only one resets.
        return (bool) Cache::lock('square-sync:environment-reset', 30)->get(function () use ($current) {
            $remembered = $this->getSetting->handle(self::SETTING_KEY);

            if ($remembered === $current) {
                return false;
            }

            if (blank($remembered)) {
                $this->updateSetting->handle(self::SETTING_KEY, $current);

                return false;
            }

            $this->reset($remembered, $current);

            return true;
        });
    }

    private function reset(string $from, string $to): void
    {
        $now = now()->toIso8601String();

        $unlinked = DB::transaction(function () use ($now, $to) {
            $unlinked = SquareObjectMapping::query()->count();
            SquareObjectMapping::query()->delete();

            // Blank, not null: the resolver treats blank as unset and falls
            // back to SQUARE_LOCATION_ID, which lives in the same .env as
            // SQUARE_ENVIRONMENT and so already matches it.
            $this->updateSetting->handle(GetSquareLocationId::SETTING_KEY, '');

            $this->updateSetting->handle(PullSquareCatalogDelta::WATERMARK_KEY, $now);
            $this->updateSetting->handle(PullSquareInventoryChanges::WATERMARK_KEY, $now);
            $this->updateSetting->handle(PullSquareSales::WATERMARK_KEY, $now);

            $this->updateSetting->handle(self::SETTING_KEY, $to);

            return $unlinked;
        });

        $this->auditLog->record(
            type: 'square.environment_changed',
            description: "Square environment changed from {$from} to {$to} -- {$unlinked} product link(s) and the sync location were reset",
            metadata: [
                'from' => $from,
                'to' => $to,
                'unlinked' => $unlinked,
            ],
            severity: 'warning',
        );
    }
}
