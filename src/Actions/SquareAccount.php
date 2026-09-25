<?php

namespace Cultpantry\SquareSync\Actions;

use App\Actions\GetSiteSetting;
use App\Actions\UpdateSiteSetting;
use Cultpantry\SquareSync\Contracts\AuditLog;
use Cultpantry\SquareSync\Models\SquareObjectMapping;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Which Square account this module's stored state belongs to. Every
 * catalog id, location id and change-log position is only meaningful on
 * the account it came from, so when the account changes, all of it is
 * reset rather than left pointing at objects that don't exist: every
 * product is unlinked (soft delete, so the history survives), the in-app
 * sync location is cleared, and the catalog, inventory and sales
 * watermarks restart at "now" so the new account's past isn't replayed.
 *
 * An account is its environment plus its merchant id, and they're noticed
 * two ways:
 *
 *  - guard(): the environment, compared before every Square API call and
 *    before anything is queued to push. One cached setting read, no API
 *    call -- a sandbox <-> production switch is caught before a single
 *    stale id is sent.
 *  - confirmMerchant(): the merchant id the access token actually belongs
 *    to, which only Square knows. CheckSquareHealth reads it on every
 *    health check, so swapping SQUARE_ACCESS_TOKEN for another seller's is
 *    caught too, even with the environment unchanged.
 *
 * The first time either is seen there's nothing to compare with, so it's
 * just remembered. Links carried over from some other account at that
 * point are caught by link verification instead: their items don't exist
 * here, so they're marked offline.
 */
class SquareAccount
{
    public const ENVIRONMENT_KEY = 'square_sync.environment';

    public const MERCHANT_KEY = 'square_sync.merchant_id';

    public function __construct(
        private readonly GetSiteSetting $getSetting,
        private readonly UpdateSiteSetting $updateSetting,
        private readonly AuditLog $auditLog,
    ) {}

    /**
     * @return bool true if this call reset the module for a new environment
     */
    public function guard(): bool
    {
        $current = (string) config('square-sync.environment');

        if ($this->getSetting->handle(self::ENVIRONMENT_KEY) === $current) {
            return false;
        }

        return $this->locked(function () use ($current) {
            $remembered = $this->getSetting->handle(self::ENVIRONMENT_KEY);

            if ($remembered === $current) {
                return false;
            }

            if (blank($remembered)) {
                $this->updateSetting->handle(self::ENVIRONMENT_KEY, $current);

                return false;
            }

            // The new environment's merchant isn't known until the next
            // health check, which adopts it.
            $this->reset(
                description: "Square environment changed from {$remembered} to {$current}",
                metadata: ['from' => $remembered, 'to' => $current],
                environment: $current,
                merchantId: '',
            );

            return true;
        });
    }

    /**
     * @return bool true if this call reset the module for a different merchant
     */
    public function confirmMerchant(string $merchantId): bool
    {
        // An environment switch is always handled first, so the merchant
        // comparison below is within one environment.
        $this->guard();

        if ($this->merchantId() === $merchantId) {
            return false;
        }

        return $this->locked(function () use ($merchantId) {
            $remembered = $this->merchantId();

            if ($remembered === $merchantId) {
                return false;
            }

            if ($remembered === null) {
                $this->updateSetting->handle(self::MERCHANT_KEY, $merchantId);

                return false;
            }

            $this->reset(
                description: "Square access token now belongs to a different seller account ({$remembered} → {$merchantId})",
                metadata: ['from_merchant' => $remembered, 'to_merchant' => $merchantId, 'environment' => config('square-sync.environment')],
                environment: (string) config('square-sync.environment'),
                merchantId: $merchantId,
            );

            return true;
        });
    }

    public function merchantId(): ?string
    {
        $value = $this->getSetting->handle(self::MERCHANT_KEY);

        return filled($value) ? $value : null;
    }

    /**
     * Two requests can notice a change at once; only one resets.
     */
    private function locked(callable $callback): bool
    {
        return (bool) Cache::lock('square-sync:account-reset', 30)->get($callback);
    }

    private function reset(string $description, array $metadata, string $environment, string $merchantId): void
    {
        $now = now()->toIso8601String();

        $unlinked = DB::transaction(function () use ($now, $environment, $merchantId) {
            $unlinked = SquareObjectMapping::query()->count();
            SquareObjectMapping::query()->delete();

            // Blank, not null: the resolver treats blank as unset and falls
            // back to SQUARE_LOCATION_ID, which lives in the same .env as
            // the credentials and so already matches them.
            $this->updateSetting->handle(GetSquareLocationId::SETTING_KEY, '');

            $this->updateSetting->handle(PullSquareCatalogDelta::WATERMARK_KEY, $now);
            $this->updateSetting->handle(PullSquareInventoryChanges::WATERMARK_KEY, $now);
            $this->updateSetting->handle(PullSquareSales::WATERMARK_KEY, $now);

            $this->updateSetting->handle(self::ENVIRONMENT_KEY, $environment);
            $this->updateSetting->handle(self::MERCHANT_KEY, $merchantId);

            return $unlinked;
        });

        $this->auditLog->record(
            type: 'square.account_changed',
            description: "{$description} -- {$unlinked} product link(s) and the sync location were reset",
            metadata: [...$metadata, 'unlinked' => $unlinked],
            severity: 'warning',
        );
    }
}
