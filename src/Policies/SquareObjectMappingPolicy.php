<?php

namespace Cultpantry\SquareSync\Policies;

use App\Models\User;
use Cultpantry\SquareSync\Models\SquareObjectMapping;

class SquareObjectMappingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, SquareObjectMapping $squareObjectMapping): bool
    {
        return $user->isAdmin();
    }

    public function create(User $user): bool
    {
        return $user->isAdmin();
    }

    public function update(User $user, SquareObjectMapping $squareObjectMapping): bool
    {
        return $user->isAdmin();
    }

    public function delete(User $user, SquareObjectMapping $squareObjectMapping): bool
    {
        return $user->isAdmin();
    }

    /**
     * Triggering a manual re-sync from the admin UI. Kept separate from
     * update because "re-sync this mapping" isn't editing the record --
     * it's kicking off a push/pull job -- and the two may end up with
     * different authorization rules later even though both are
     * admin-only today.
     */
    public function sync(User $user, SquareObjectMapping $squareObjectMapping): bool
    {
        return $user->isAdmin();
    }
}
