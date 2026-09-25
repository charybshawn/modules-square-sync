<?php

namespace Cultpantry\SquareSync\Actions;

use App\Actions\GetSiteSetting;
use App\Actions\UpdateSiteSetting;
use Cultpantry\SquareSync\Contracts\AdminAlerts;
use Cultpantry\SquareSync\Contracts\AuditLog;
use Cultpantry\SquareSync\Square\SquareClient;
use Cultpantry\SquareSync\Square\SquareException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The one answer to "is the Square sync working right now?", asked live
 * of Square rather than inferred from the last thing that happened to
 * succeed. Runs when an admin opens the Square Sync page, on the
 * `square:check` schedule, and as the first half of Run Sync Check.
 *
 * In order, stopping at the first thing that makes the rest meaningless:
 *
 *  1. Credentials are configured.
 *  2. The token works, and belongs to the account the links were made on
 *     (see SquareAccount::confirmMerchant() -- a different seller resets
 *     the links, exactly like a sandbox <-> production switch).
 *  3. The token has every permission the sync uses.
 *  4. The sync location exists on this account and is active.
 *  5. Every product link still points at a live Square item
 *     (VerifySquareLinks), which marks each one online or offline.
 *
 * The result is stored as a snapshot the page renders from, and compared
 * with the previous one: when something new breaks, or everything
 * recovers, the admins are alerted and it's written to the audit trail.
 * Checks that find nothing new stay quiet.
 */
class CheckSquareHealth
{
    public const SNAPSHOT_KEY = 'square_sync.health';

    /**
     * Everything the sync itself does.
     */
    public const REQUIRED_SCOPES = ['ITEMS_READ', 'ITEMS_WRITE', 'INVENTORY_READ', 'INVENTORY_WRITE', 'ORDERS_READ', 'CUSTOMERS_READ'];

    /**
     * Only the test sale needs these (see RunSquareTestSale).
     */
    public const TEST_SALE_SCOPES = ['ORDERS_WRITE', 'PAYMENTS_WRITE'];

    /**
     * A check older than this means the scheduler isn't running it.
     */
    public const STALE_AFTER_MINUTES = 60;

    private const EXPIRY_WARNING_DAYS = 14;

    public function __construct(
        private readonly SquareClient $client,
        private readonly SquareAccount $account,
        private readonly GetSquareLocationId $getLocationId,
        private readonly VerifySquareLinks $verifyLinks,
        private readonly GetSiteSetting $getSetting,
        private readonly UpdateSiteSetting $updateSetting,
        private readonly AuditLog $auditLog,
        private readonly AdminAlerts $alerts,
    ) {}

    /**
     * @return array{
     *     status: 'online'|'offline'|'not_configured',
     *     checked_at: string,
     *     environment: string,
     *     merchant_id: string|null,
     *     problems: array<int, array{key: string, message: string}>,
     *     warnings: array<int, array{key: string, message: string}>,
     *     scopes: array<int, string>|null,
     *     location: array{id: string, name: string|null, status: string|null}|null,
     *     links: array|null,
     * }
     */
    public function handle(?string $correlationId = null): array
    {
        // One check at a time -- two admins opening the page together, or
        // the schedule overlapping a page load, would otherwise both
        // compare against the same previous snapshot and alert twice.
        return Cache::lock('square-sync:health-check', 60)->block(30, function () use ($correlationId) {
            $previous = $this->snapshot();
            $health = $this->check();

            $this->updateSetting->handle(self::SNAPSHOT_KEY, json_encode($health));
            $this->reportChange($previous, $health, $correlationId);

            return $health;
        });
    }

    /**
     * The last stored check, or null if there's never been one.
     */
    public function snapshot(): ?array
    {
        $stored = $this->getSetting->handle(self::SNAPSHOT_KEY);
        $decoded = is_string($stored) ? json_decode($stored, true) : null;

        return is_array($decoded) ? $decoded : null;
    }

    private function check(): array
    {
        $health = [
            'status' => 'online',
            'checked_at' => now()->toIso8601String(),
            'environment' => (string) config('square-sync.environment'),
            'merchant_id' => null,
            'problems' => [],
            'warnings' => [],
            'scopes' => null,
            'location' => null,
            'links' => null,
        ];

        if (blank(config('square-sync.access_token'))) {
            return $this->fail($health, 'not_configured', 'token_missing', 'No Square access token is set. Add SQUARE_ACCESS_TOKEN to .env.');
        }

        // 2. The token, and whose it is.
        try {
            $token = $this->client->oauth()->tokenStatus();
        } catch (Throwable $e) {
            return $this->fail($health, 'offline', ...$this->describeFailure($e, 'the access token'));
        }

        $health['merchant_id'] = $token['merchant_id'];
        $health['scopes'] = $token['scopes'];

        if (filled($token['merchant_id']) && $this->account->confirmMerchant($token['merchant_id'])) {
            $health['warnings'][] = [
                'key' => 'account_changed',
                'message' => 'The access token now belongs to a different Square account, so every product link was removed. Link your products again.',
            ];
        }

        if ($token['expires_at'] !== null && Carbon::parse($token['expires_at'])->isBefore(now()->addDays(self::EXPIRY_WARNING_DAYS))) {
            $health['warnings'][] = [
                'key' => 'token_expiring',
                'message' => 'The Square access token expires '.Carbon::parse($token['expires_at'])->diffForHumans().'. Renew it before then.',
            ];
        }

        // 3. Permissions. Personal access tokens don't always list scopes;
        // unknown isn't treated as missing.
        $missing = is_array($token['scopes']) ? array_values(array_diff(self::REQUIRED_SCOPES, $token['scopes'])) : [];

        if ($missing !== []) {
            $health['status'] = 'offline';
            $health['problems'][] = [
                'key' => 'scopes_missing',
                'message' => 'The access token is missing permissions the sync needs: '.implode(', ', $missing).'.',
            ];
        }

        // 4. The sync location.
        $locationId = $this->getLocationId->handle();

        if (blank($locationId)) {
            return $this->fail($health, 'offline', 'location_missing', 'No sync location is chosen. Pick one under Connection.');
        }

        try {
            $location = $this->client->locations()->retrieve($locationId)->json('location') ?? [];
        } catch (Throwable $e) {
            [$key, $message] = $e instanceof SquareException && $e->httpStatus() === 404
                ? ['location_not_found', "The sync location ({$locationId}) doesn't exist on this Square account. Pick one of this account's locations."]
                : $this->describeFailure($e, 'the sync location');

            return $this->fail($health, 'offline', $key, $message);
        }

        $health['location'] = [
            'id' => $locationId,
            'name' => $location['name'] ?? null,
            'status' => $location['status'] ?? null,
        ];

        if (($location['status'] ?? 'ACTIVE') !== 'ACTIVE') {
            return $this->fail($health, 'offline', 'location_inactive', "The sync location ({$health['location']['name']}) is inactive on Square. Reactivate it or pick another.");
        }

        if (blank(config('square-sync.webhook_signature_key')) || blank(config('square-sync.notification_url'))) {
            $health['warnings'][] = [
                'key' => 'webhooks_unconfigured',
                'message' => 'Webhooks aren\'t set up (SQUARE_WEBHOOK_SIGNATURE_KEY / SQUARE_NOTIFICATION_URL), so Square sales only reach local stock on the 15-minute catch-up.',
            ];
        }

        // 5. The links.
        try {
            $health['links'] = $this->verifyLinks->handle();
        } catch (Throwable $e) {
            return $this->fail($health, 'offline', ...$this->describeFailure($e, 'product links'));
        }

        return $health;
    }

    private function fail(array $health, string $status, string $key, string $message): array
    {
        $health['status'] = $status;
        $health['problems'][] = ['key' => $key, 'message' => $message];

        return $health;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function describeFailure(Throwable $e, string $what): array
    {
        if ($e instanceof SquareException && $e->httpStatus() === 401) {
            return ['token_invalid', 'Square rejected the access token -- it was revoked, has expired, or belongs to the other environment ('.config('square-sync.environment').' is configured). Replace SQUARE_ACCESS_TOKEN.'];
        }

        if ($e instanceof SquareException && $e->httpStatus() === 403) {
            return ['forbidden', "The access token isn't allowed to read {$what}: {$e->getMessage()}"];
        }

        return ['unreachable', "Square couldn't be reached while checking {$what}: {$e->getMessage()}"];
    }

    /**
     * What an admin needs to act on, as comparable keys: connection
     * problems plus each link that isn't OK.
     *
     * @return array<string, string> key => human-readable line
     */
    private function issues(?array $health): array
    {
        if ($health === null) {
            return [];
        }

        $issues = [];

        foreach ([...$health['problems'] ?? [], ...$health['warnings'] ?? []] as $problem) {
            // One-off notices, not ongoing states.
            if ($problem['key'] === 'account_changed') {
                continue;
            }

            $issues["connection:{$problem['key']}"] = $problem['message'];
        }

        foreach ($health['links']['issues'] ?? [] as $issue) {
            $label = VerifySquareLinks::STATUS_LABELS[$issue['status']] ?? $issue['status'];
            $issues["link:{$issue['mapping_id']}:{$issue['status']}"] = ($issue['product_title'] ?? "Product #{$issue['product_id']}").": {$label}";
        }

        return $issues;
    }

    private function reportChange(?array $previous, array $health, ?string $correlationId): void
    {
        $before = $this->issues($previous);
        $after = $this->issues($health);
        $new = array_diff_key($after, $before);
        $url = url('/admin/square');

        $accountChanged = collect($health['warnings'])->firstWhere('key', 'account_changed');

        if ($new !== [] || $accountChanged !== null) {
            $lines = array_values([...($accountChanged ? [$accountChanged['message']] : []), ...$new]);

            $this->auditLog->record(
                type: 'square.health_degraded',
                description: 'Square sync needs attention: '.implode('; ', $lines),
                metadata: ['status' => $health['status'], 'new_issues' => array_keys($new), 'open_issues' => count($after)],
                severity: 'warning',
                correlationId: $correlationId,
            );

            $this->alerts->send(
                $health['status'] === 'online' ? 'Square sync needs attention' : 'Square sync is offline',
                $lines,
                'warning',
                $url,
            );

            return;
        }

        if ($before !== [] && $after === []) {
            $this->auditLog->record(
                type: 'square.health_restored',
                description: 'Square sync is healthy again -- '.count($before).' earlier issue(s) cleared',
                metadata: ['cleared' => array_keys($before)],
                severity: 'info',
                correlationId: $correlationId,
            );

            $this->alerts->send('Square sync is healthy again', array_values($before), 'info', $url);
        }
    }
}
