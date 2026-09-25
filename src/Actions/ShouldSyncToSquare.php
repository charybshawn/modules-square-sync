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
        private readonly ResetOnEnvironmentChange $resetOnEnvironmentChange,
    ) {}

    public function handle(): bool
    {
        if (! $this->getSiteSetting->handle('modules.cultpantry/square-sync.enabled', true)) {
            return false;
        }

        // Before anything is queued against a link: after a sandbox <->
        // production switch those links belong to the other account.
        $this->resetOnEnvironmentChange->handle();

        return filled(config('square-sync.access_token')) && filled($this->getLocationId->handle());
    }
}
