<?php

namespace Cultpantry\SquareSync\Database\Factories;

use Cultpantry\SquareSync\Models\SquareWebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SquareWebhookEvent>
 *
 * See SquareObjectMappingFactory's docblock for why this lives outside
 * src/ and how SquareWebhookEvent::newFactory() loads it anyway.
 */
class SquareWebhookEventFactory extends Factory
{
    protected $model = SquareWebhookEvent::class;

    public function definition(): array
    {
        return [
            'square_event_id' => (string) $this->faker->unique()->uuid(),
            'event_type' => $this->faker->randomElement([
                'catalog.version.updated',
                'inventory.count.updated',
            ]),
            'payload' => [
                'type' => 'catalog.version.updated',
                'event_id' => (string) $this->faker->uuid(),
                'data' => ['object' => ['type' => 'CATALOG_VERSION']],
            ],
            'status' => 'received',
            'error' => null,
            'processed_at' => null,
        ];
    }

    public function processed(): static
    {
        return $this->state(fn () => [
            'status' => 'processed',
            'processed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => 'failed',
            'error' => 'Something went wrong processing this event.',
        ]);
    }
}
