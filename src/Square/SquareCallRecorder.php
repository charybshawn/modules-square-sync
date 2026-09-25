<?php

namespace Cultpantry\SquareSync\Square;

/**
 * Keeps every Square request and response of one run in memory, for the
 * Diagnostics panel's "Show raw responses" -- the audit trail already has
 * them, but scattered across a busy events table. Scoped to the request,
 * and off unless something calls start().
 */
final class SquareCallRecorder
{
    private bool $recording = false;

    /** @var array<int, array<string, mixed>> */
    private array $calls = [];

    public function start(): void
    {
        $this->recording = true;
        $this->calls = [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function stop(): array
    {
        $this->recording = false;

        return $this->calls;
    }

    /**
     * @param  array<string, mixed>  $call
     */
    public function record(array $call): void
    {
        if ($this->recording) {
            $this->calls[] = $call;
        }
    }
}
