<?php

namespace Cultpantry\SquareSync\Actions;

use App\Actions\GetSiteSetting;

/**
 * The module kill switch plus the credentials check, shared by every
 * outbound entry point -- so nothing dispatches a job that would just fail
 * (or silently no-op) once it actually runs.
 */
class ShouldSyncToSquare
{
    public function __construct(
        private readonly GetSiteSetting $getSiteSetting,
        private readonly GetSquareLocationId $getLocationId,
    ) {}

    public function handle(): bool
    {
        if (! $this->getSiteSetting->handle('modules.cultpantry/square-sync.enabled', true)) {
            return false;
        }

        return filled(config('square-sync.access_token')) && filled($this->getLocationId->handle());
    }
}
