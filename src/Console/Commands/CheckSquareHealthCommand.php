<?php

namespace Cultpantry\SquareSync\Console\Commands;

use Cultpantry\SquareSync\Actions\CheckSquareHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Scheduled by the module every 15 minutes (see SquareSyncServiceProvider),
 * so a dead token, a changed account or a link gone stale is noticed -- and
 * the admins alerted -- without anyone opening the Square Sync page.
 */
class CheckSquareHealthCommand extends Command
{
    protected $signature = 'square:check';

    protected $description = 'Check the Square connection and every product link, and alert admins when something breaks or recovers.';

    public function handle(CheckSquareHealth $checkSquareHealth): int
    {
        $health = $checkSquareHealth->handle((string) Str::uuid());

        $this->line('Square connection: '.strtoupper($health['status'])." ({$health['environment']})");

        foreach ($health['problems'] as $problem) {
            $this->error("  {$problem['message']}");
        }

        foreach ($health['warnings'] as $warning) {
            $this->warn("  {$warning['message']}");
        }

        if ($health['links'] !== null) {
            $links = $health['links'];
            $this->info("{$links['ok']} of {$links['checked']} product link(s) OK.");

            if ($links['issues'] !== []) {
                $this->table(
                    ['Product', 'Square object', 'Problem'],
                    array_map(fn (array $issue) => [
                        $issue['product_title'] ?? "#{$issue['product_id']}",
                        $issue['square_object_id'],
                        str_replace('_', ' ', $issue['status']),
                    ], $links['issues']),
                );
            }
        }

        // A broken connection is a failed scheduled run, so monitoring
        // sees it; stale links are reported, not failures.
        return $health['status'] === 'online' ? self::SUCCESS : self::FAILURE;
    }
}
