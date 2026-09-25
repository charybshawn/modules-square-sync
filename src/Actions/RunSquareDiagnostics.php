<?php

namespace Cultpantry\SquareSync\Actions;

use App\Actions\GetSiteSetting;
use Cultpantry\SquareSync\Contracts\LocalCatalog;
use Cultpantry\SquareSync\Models\SquareImportedSale;
use Cultpantry\SquareSync\Models\SquareInventoryChange;
use Cultpantry\SquareSync\Models\SquareObjectMapping;
use Cultpantry\SquareSync\Models\SquareWebhookEvent;
use Cultpantry\SquareSync\Square\SquareCallRecorder;
use Cultpantry\SquareSync\Square\SquareClient;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * The Diagnostics panel's "Run checks": every link in the chain from a
 * sale on Square to a stock change here, checked in the order a sale
 * travels it, each with a pass / warn / fail and what to do about it.
 * Nothing here changes stock or creates anything on Square -- the one
 * side effect is the sample webhook Square sends when asked to test the
 * subscription, which this app handles like any other (harmlessly: it
 * triggers a catch-up pull).
 *
 * The end-to-end version, with a real (sandbox) sale, is RunSquareTestSale.
 */
class RunSquareDiagnostics
{
    /**
     * Webhook events the sync depends on, and why.
     */
    public const REQUIRED_EVENTS = [
        'inventory.count.updated' => 'applies Square sales to local stock',
        'catalog.version.updated' => 'notices items deleted on Square',
    ];

    public const OPTIONAL_EVENTS = [
        'order.updated' => 'records sales of items that don\'t track inventory within seconds',
    ];

    public function __construct(
        private readonly CheckSquareHealth $checkHealth,
        private readonly SquareClient $client,
        private readonly LocalCatalog $catalog,
        private readonly GetSiteSetting $getSetting,
        private readonly SquareCallRecorder $recorder,
    ) {}

    /**
     * With $debug, the result also carries every raw Square request and
     * response the run made, and every webhook that arrived meanwhile --
     * the delivery test's own event among them.
     *
     * @return array{health: array, checks: array<int, array{key: string, label: string, status: 'pass'|'warn'|'fail'|'skip', detail: string}>, test_sale: array, debug?: array}
     */
    public function handle(bool $debug = false): array
    {
        $startedAt = now();

        if ($debug) {
            $this->recorder->start();
        }

        $health = $this->checkHealth->handle();
        $online = $health['status'] === 'online';

        $checks = [
            $this->connectionCheck($health),
            $this->permissionsCheck($health),
            $this->locationCheck($health),
            $this->linksCheck($health),
        ];

        $settings = $this->webhookSettingsCheck();
        $checks[] = $settings;

        [$subscription, $subscriptionCheck] = $online
            ? $this->subscriptionCheck()
            : [null, $this->skip('webhook_subscription', 'Webhook subscription on Square', 'Needs a working connection.')];
        $checks[] = $subscriptionCheck;

        $checks[] = $subscription !== null && $settings['status'] === 'pass'
            ? $this->deliveryCheck($subscription)
            : $this->skip('webhook_delivery', 'Square can deliver webhooks here', 'Needs the webhook settings and a matching subscription on Square.');

        $checks[] = $this->activityCheck();

        $result = [
            'health' => $health,
            'checks' => $checks,
            'test_sale' => $this->testSaleAvailability($health),
        ];

        return $debug ? [...$result, 'debug' => $this->debugTrace($startedAt)] : $result;
    }

    /**
     * @return array{started_at: string, finished_at: string, config: array, square_calls: array, webhooks_received: array}
     */
    private function debugTrace(Carbon $startedAt): array
    {
        return [
            'started_at' => $startedAt->toIso8601String(),
            'finished_at' => now()->toIso8601String(),
            'config' => [
                'environment' => config('square-sync.environment'),
                'api_version' => config('square-sync.version'),
                'notification_url' => config('square-sync.notification_url'),
                'webhook_signature_key_set' => filled(config('square-sync.webhook_signature_key')),
                'timeout_seconds' => config('square-sync.timeout'),
            ],
            'square_calls' => $this->recorder->stop(),
            'webhooks_received' => SquareWebhookEvent::query()
                ->where('created_at', '>=', $startedAt)
                ->oldest('id')
                ->get()
                ->map(fn (SquareWebhookEvent $event) => [
                    'square_event_id' => $event->square_event_id,
                    'event_type' => $event->event_type,
                    'status' => $event->status,
                    'error' => $event->error,
                    'received_at' => $event->created_at?->toIso8601String(),
                    'processed_at' => $event->processed_at?->toIso8601String(),
                    'handling_ms' => $event->processed_at && $event->created_at ? (int) $event->created_at->diffInMilliseconds($event->processed_at) : null,
                    'payload' => $event->payload,
                ])
                ->all(),
        ];
    }

    private function connectionCheck(array $health): array
    {
        $label = 'Connected to Square';
        $connectionProblem = collect($health['problems'])->first(fn ($problem) => in_array($problem['key'], ['token_missing', 'token_invalid', 'unreachable', 'forbidden'], true));

        if ($connectionProblem !== null) {
            return $this->result('connection', $label, 'fail', $connectionProblem['message']);
        }

        $detail = ucfirst($health['environment']).' account '.($health['merchant_id'] ?? '(unknown merchant)').'.';
        $expiring = collect($health['warnings'])->firstWhere('key', 'token_expiring');

        return $expiring !== null
            ? $this->result('connection', $label, 'warn', "{$detail} {$expiring['message']}")
            : $this->result('connection', $label, 'pass', $detail);
    }

    private function permissionsCheck(array $health): array
    {
        $label = 'Access token permissions';

        if ($health['scopes'] === null) {
            return $health['merchant_id'] === null
                ? $this->skip('permissions', $label, 'Needs a working access token.')
                : $this->result('permissions', $label, 'warn', 'Square didn\'t list this token\'s permissions, so they can\'t be checked ahead of time.');
        }

        $missing = array_values(array_diff(CheckSquareHealth::REQUIRED_SCOPES, $health['scopes']));
        $missingForTest = array_values(array_diff(CheckSquareHealth::TEST_SALE_SCOPES, $health['scopes']));

        return match (true) {
            $missing !== [] => $this->result('permissions', $label, 'fail', 'Missing '.implode(', ', $missing).'. The sync can\'t work fully without them.'),
            $missingForTest !== [] => $this->result('permissions', $label, 'warn', 'Everything the sync needs. The test sale also needs '.implode(', ', $missingForTest).'.'),
            default => $this->result('permissions', $label, 'pass', 'Everything the sync and the test sale need.'),
        };
    }

    private function locationCheck(array $health): array
    {
        $label = 'Sync location';
        $problem = collect($health['problems'])->first(fn ($problem) => str_starts_with($problem['key'], 'location_'));

        return match (true) {
            $problem !== null => $this->result('location', $label, 'fail', $problem['message']),
            $health['location'] === null => $this->skip('location', $label, 'Needs a working connection.'),
            default => $this->result('location', $label, 'pass', ($health['location']['name'] ?? $health['location']['id']).' is active.'),
        };
    }

    private function linksCheck(array $health): array
    {
        $label = 'Product links';
        $links = $health['links'];

        if ($links === null) {
            return $this->skip('links', $label, 'Needs a working connection.');
        }

        if ($links['checked'] === 0) {
            return $this->result('links', $label, 'warn', 'No products are linked yet, so nothing syncs.');
        }

        $issues = count($links['issues']);

        return match (true) {
            $links['missing'] > 0 => $this->result('links', $label, 'fail', "{$links['missing']} of {$links['checked']} are offline -- their Square items don't exist on this account. Relink or unlink them."),
            $issues > 0 => $this->result('links', $label, 'warn', "{$issues} of {$links['checked']} point at items that are archived or not sold at the sync location."),
            default => $this->result('links', $label, 'pass', "All {$links['checked']} online."),
        };
    }

    private function webhookSettingsCheck(): array
    {
        $label = 'Webhook settings';
        $url = config('square-sync.notification_url');
        $missing = array_keys(array_filter([
            'SQUARE_WEBHOOK_SIGNATURE_KEY' => blank(config('square-sync.webhook_signature_key')),
            'SQUARE_NOTIFICATION_URL' => blank($url),
        ]));

        if ($missing !== []) {
            return $this->result('webhook_settings', $label, 'fail', 'Set '.implode(' and ', $missing).' in .env. Until then Square sales only arrive on the 15-minute catch-up.');
        }

        $expectedPath = parse_url(route('webhooks.square'), PHP_URL_PATH);

        if (rtrim((string) parse_url($url, PHP_URL_PATH), '/') !== rtrim((string) $expectedPath, '/')) {
            return $this->result('webhook_settings', $label, 'fail', "SQUARE_NOTIFICATION_URL ({$url}) doesn't point at this app's webhook endpoint ({$expectedPath}).");
        }

        return $this->result('webhook_settings', $label, 'pass', "Square notifies {$url}.");
    }

    /**
     * @return array{0: array|null, 1: array} the matching subscription (if any) and the check
     */
    private function subscriptionCheck(): array
    {
        $label = 'Webhook subscription on Square';
        $url = rtrim((string) config('square-sync.notification_url'), '/');

        if ($url === '') {
            return [null, $this->skip('webhook_subscription', $label, 'Needs SQUARE_NOTIFICATION_URL.')];
        }

        try {
            $subscriptions = $this->client->webhooks()->listSubscriptions();
        } catch (Throwable $e) {
            return [null, $this->result('webhook_subscription', $label, 'warn', "Couldn't read subscriptions ({$e->getMessage()}). They can only be read with the application's personal access token -- check them in the Square Developer Console.")];
        }

        $subscription =collect($subscriptions)->first(fn (array $candidate) => rtrim($candidate['notification_url'] ?? '', '/') === $url);

        if ($subscription === null) {
            return [null, $this->result('webhook_subscription', $label, 'fail', "No subscription on this Square application sends to {$url}. Add one in the Square Developer Console ({$this->environmentLabel()}).")];
        }

        if (! ($subscription['enabled'] ?? true)) {
            return [null, $this->result('webhook_subscription', $label, 'fail', 'The subscription exists but is disabled. Enable it in the Square Developer Console.')];
        }

        $events = $subscription['event_types'] ?? [];
        $missingRequired = array_diff_key(self::REQUIRED_EVENTS, array_flip($events));
        $missingOptional = array_diff_key(self::OPTIONAL_EVENTS, array_flip($events));

        if ($missingRequired !== []) {
            // Square only test-delivers event types the subscription has.
            return [null, $this->result('webhook_subscription', $label, 'fail', 'The subscription is missing '.$this->describeEvents($missingRequired).'.')];
        }

        return [$subscription, $missingOptional !== []
            ? $this->result('webhook_subscription', $label, 'warn', 'Subscribed to everything required. Optional: '.$this->describeEvents($missingOptional).'.')
            : $this->result('webhook_subscription', $label, 'pass', 'Subscribed to '.implode(', ', array_keys([...self::REQUIRED_EVENTS, ...self::OPTIONAL_EVENTS])).'.')];
    }

    /**
     * Square sends a real, signed sample event to this app and reports
     * what it answered -- proof that delivery, the signature key and the
     * notification URL all line up.
     */
    private function deliveryCheck(array $subscription): array
    {
        $label = 'Square can deliver webhooks here';

        try {
            $result = $this->client->webhooks()->testSubscription($subscription['id'], 'inventory.count.updated');
        } catch (Throwable $e) {
            return $this->result('webhook_delivery', $label, 'warn', "Square couldn't run the delivery test: {$e->getMessage()}");
        }

        $status = (int) ($result['status_code'] ?? 0);

        // Square leaves the status out when it gave up waiting for this
        // app's answer -- but the event may still have arrived and been
        // handled, which is what actually matters.
        if ($status === 0) {
            $payload = $result['payload'] ?? null;
            $eventId = (is_string($payload) ? json_decode($payload, true) : $payload)['event_id'] ?? null;
            $received = $eventId !== null ? SquareWebhookEvent::query()->where('square_event_id', $eventId)->first() : null;

            if ($received !== null) {
                return $this->result('webhook_delivery', $label, 'pass', "Square's test inventory.count.updated event reached this app and was {$received->status}. Square didn't record the app's answer -- it most likely took longer than Square waits.");
            }
        }

        return match (true) {
            $status >= 200 && $status < 300 => $this->result('webhook_delivery', $label, 'pass', "Square sent a test inventory.count.updated event and this app accepted it ({$status})."),
            $status === 401 => $this->result('webhook_delivery', $label, 'fail', 'This app rejected the test event\'s signature (401). SQUARE_WEBHOOK_SIGNATURE_KEY must be this subscription\'s key, and SQUARE_NOTIFICATION_URL must match its URL exactly.'),
            $status === 0 => $this->result('webhook_delivery', $label, 'fail', 'Square couldn\'t reach this app at the notification URL. Is it publicly reachable?'),
            default => $this->result('webhook_delivery', $label, 'fail', "This app answered the test event with {$status}. See the square.webhook events in the audit log."),
        };
    }

    /**
     * What's actually been happening -- the passive check that works in
     * production, where a test sale can't run.
     */
    private function activityCheck(): array
    {
        $label = 'Recent sync activity';

        $lastWebhook = SquareWebhookEvent::query()->max('created_at');
        $lastStockChange = SquareInventoryChange::query()->max('created_at');
        $lastSale = SquareImportedSale::query()->where('kind', 'sale')->max('created_at');
        $lastPull = $this->getSetting->handle(PullSquareSales::WATERMARK_KEY);

        $parts = [
            'Last webhook '.$this->ago($lastWebhook),
            'last Square sale applied to stock '.$this->ago($lastStockChange),
            'last sale recorded '.$this->ago($lastSale),
            'last catch-up pull '.$this->ago($lastPull),
        ];
        $detail = ucfirst(implode(', ', $parts)).'.';

        // The catch-up runs every 15 minutes; much older means the
        // scheduler isn't running.
        if ($lastPull === null || Carbon::parse($lastPull)->isBefore(now()->subHour())) {
            return $this->result('activity', $label, 'warn', "{$detail} The 15-minute catch-up hasn't run in over an hour -- is the scheduler running?");
        }

        return $this->result('activity', $label, 'pass', $detail);
    }

    /**
     * Which linked products a test sale can use: online links to
     * inventory-tracked products with stock to sell.
     */
    private function testSaleAvailability(array $health): array
    {
        $reason = match (true) {
            config('square-sync.environment') !== 'sandbox' => 'Test sales only run in the sandbox environment, because in production they\'d be real sales. Switch SQUARE_ENVIRONMENT to sandbox to use it.',
            $health['status'] !== 'online' => 'Fix the connection problems above first.',
            default => null,
        };

        $products = [];

        if ($reason === null) {
            $mappings = SquareObjectMapping::forLocalCatalog()->linked()->where('verification_status', 'ok')->get();
            $items = $this->catalog->findMany($mappings->pluck('mappable_id')->all());

            foreach ($mappings as $mapping) {
                $item = $items[$mapping->mappable_id] ?? null;

                if ($item !== null && $item->tracksInventory && $item->stockQuantity > 0) {
                    $products[] = ['id' => $item->id, 'title' => $item->title, 'stock' => $item->stockQuantity];
                }
            }

            usort($products, fn (array $a, array $b) => strcasecmp($a['title'], $b['title']));

            if ($products === []) {
                $reason = 'No linked product qualifies: it needs an online link, inventory tracking, and at least 1 in stock.';
            }
        }

        return ['available' => $reason === null, 'reason' => $reason, 'products' => $products];
    }

    private function describeEvents(array $events): string
    {
        return collect($events)->map(fn (string $why, string $event) => "{$event} ({$why})")->implode(', ');
    }

    private function environmentLabel(): string
    {
        return config('square-sync.environment') === 'production' ? 'Production tab' : 'Sandbox tab';
    }

    private function ago(mixed $timestamp): string
    {
        return $timestamp !== null ? Carbon::parse($timestamp)->diffForHumans() : 'never';
    }

    private function skip(string $key, string $label, string $detail): array
    {
        return $this->result($key, $label, 'skip', $detail);
    }

    private function result(string $key, string $label, string $status, string $detail): array
    {
        return ['key' => $key, 'label' => $label, 'status' => $status, 'detail' => $detail];
    }
}
