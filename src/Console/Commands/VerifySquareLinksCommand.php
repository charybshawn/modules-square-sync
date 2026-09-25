<?php

namespace Cultpantry\SquareSync\Console\Commands;

use Cultpantry\SquareSync\Actions\VerifySquareLinks;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Checks every product link against the connected Square account -- see
 * VerifySquareLinks. Meant to run on the host's schedule so stale links
 * surface on the Square Sync page without anyone going looking. Flags
 * only; never unlinks anything.
 */
class VerifySquareLinksCommand extends Command
{
    protected $signature = 'square:verify-links';

    protected $description = 'Check every Square product link still exists, is active, and is sold at the sync location.';

    public function handle(VerifySquareLinks $verifySquareLinks): int
    {
        if (blank(config('square-sync.access_token'))) {
            $this->error('SQUARE_ACCESS_TOKEN is not configured.');

            return self::FAILURE;
        }

        $result = $verifySquareLinks->handle((string) Str::uuid());

        if ($result['checked'] === 0) {
            $this->info('No product links to verify.');

            return self::SUCCESS;
        }

        $this->info("{$result['ok']} of {$result['checked']} link(s) OK.");

        if ($result['issues'] !== []) {
            $this->table(
                ['Product', 'Square object', 'Problem'],
                array_map(fn (array $issue) => [
                    $issue['product_title'] ?? "#{$issue['product_id']}",
                    $issue['square_object_id'],
                    str_replace('_', ' ', $issue['status']),
                ], $result['issues']),
            );
            $this->warn('Fix these on Square, or unlink/relink them on the Square Sync page.');
        }

        return self::SUCCESS;
    }
}
