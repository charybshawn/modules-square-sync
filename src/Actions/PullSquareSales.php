<?php

namespace Cultpantry\SquareSync\Actions;

use App\Actions\GetSiteSetting;
use App\Actions\UpdateSiteSetting;
use Carbon\CarbonInterface;
use Cultpantry\SquareSync\Contracts\AuditLog;
use Cultpantry\SquareSync\Contracts\Sales\SquareRefund;
use Cultpantry\SquareSync\Contracts\Sales\SquareSale;
use Cultpantry\SquareSync\Contracts\SquareSaleRecorder;
use Cultpantry\SquareSync\Models\SquareImportedSale;
use Cultpantry\SquareSync\Models\SquareObjectMapping;
use Cultpantry\SquareSync\Square\SquareClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Hands every completed Square order (and every refund) to the host's
 * SquareSaleRecorder, exactly once each, for its own sales records.
 *
 * Record-only: stock is PullSquareInventoryChanges' job. Orders are read
 * from Search Orders rather than inferred from the inventory change log
 * because the change log only sees items that track inventory -- a sale of
 * an untracked item or a custom amount is still revenue.
 *
 * Incremental runs (webhook-triggered, scheduled) walk forward from a
 * watermark with the same overlap-and-dedupe approach as the change log
 * pull. The backfill (square:import-sales --since) runs the same code over
 * an explicit window and leaves the watermark alone.
 *
 * One order failing to record doesn't stop the run: it's rolled back
 * (claim included), logged as square.sale_import_failed, and can be
 * retried by re-running the backfill over its date -- the ledger makes
 * that safe.
 */
class PullSquareSales
{
    public const WATERMARK_KEY = 'square_sync.sales_watermark';

    private const OVERLAP_MINUTES = 10;

    private const LOCK_SECONDS = 600;

    /**
     * Orders per customer-lookup batch.
     */
    private const CHUNK = 100;

    public function __construct(
        private readonly SquareClient $client,
        private readonly GetSiteSetting $getSetting,
        private readonly UpdateSiteSetting $updateSetting,
        private readonly GetSquareLocationId $getLocationId,
        private readonly MapSquareOrder $mapOrder,
        private readonly SquareSaleRecorder $recorder,
        private readonly AuditLog $auditLog,
    ) {}

    /**
     * @param  CarbonInterface|null  $since  explicit window start (backfill);
     *                                       null to continue from the watermark and advance it
     * @return array{sales: int, refunds: int, failed: int}
     */
    public function handle(?CarbonInterface $since = null, ?string $correlationId = null, ?int $parentRef = null): array
    {
        $locationId = $this->getLocationId->handle();

        if (blank($locationId)) {
            return ['sales' => 0, 'refunds' => 0, 'failed' => 0];
        }

        return Cache::lock('square-sync:sales', self::LOCK_SECONDS)
            ->block(30, fn () => $this->pull($locationId, $since, $correlationId, $parentRef));
    }

    /**
     * @return array{sales: int, refunds: int, failed: int}
     */
    private function pull(string $locationId, ?CarbonInterface $since, ?string $correlationId, ?int $parentRef): array
    {
        $incremental = $since === null;
        $pullStartedAt = now();

        if ($incremental) {
            $storedWatermark = $this->getSetting->handle(self::WATERMARK_KEY);
            $since = ($storedWatermark ? Carbon::parse($storedWatermark) : $pullStartedAt->copy())
                ->subMinutes(self::OVERLAP_MINUTES);
        }

        $tally = ['sales' => 0, 'refunds' => 0, 'failed' => 0];

        $this->client->orders()
            ->searchCompleted([$locationId], $since)
            ->chunk(self::CHUNK)
            ->each(function ($orders) use (&$tally, $correlationId, $parentRef) {
                $orders = $orders->values()->all();
                $customers = $this->fetchCustomers($orders);
                $localItemIds = $this->localItemIds($orders);

                foreach ($orders as $order) {
                    $this->importOrder($order, $customers, $localItemIds, $tally, $correlationId, $parentRef);
                }
            });

        if ($incremental) {
            $this->updateSetting->handle(self::WATERMARK_KEY, $pullStartedAt->toIso8601String());
        }

        if ($tally['sales'] + $tally['refunds'] + $tally['failed'] > 0) {
            $this->auditLog->record(
                type: 'square.sales_pulled',
                description: "Square sales recorded ({$tally['sales']} sale(s), {$tally['refunds']} refund(s)"
                    .($tally['failed'] > 0 ? ", {$tally['failed']} failed)" : ')'),
                metadata: ['since' => $since->toIso8601String(), 'backfill' => ! $incremental, ...$tally],
                severity: $tally['failed'] > 0 ? 'warning' : 'info',
                direction: 'inbound',
                correlationId: $correlationId,
                parentRef: $parentRef,
            );
        }

        return $tally;
    }

    private function importOrder(array $order, array $customers, array $localItemIds, array &$tally, ?string $correlationId, ?int $parentRef): void
    {
        try {
            $sale = $this->mapOrder->toSale($order, $customers, $localItemIds);

            if ($sale !== null && $this->recordSale($sale)) {
                $tally['sales']++;
            }

            $refund = $this->mapOrder->toRefund($order, $localItemIds);

            if ($refund !== null && $this->recordRefund($refund)) {
                $tally['refunds']++;
            }
        } catch (Throwable $e) {
            $tally['failed']++;

            $this->auditLog->record(
                type: 'square.sale_import_failed',
                description: "Square order {$order['id']} could not be recorded: {$e->getMessage()}",
                metadata: ['square_order_id' => $order['id'] ?? null, 'error' => $e->getMessage()],
                severity: 'error',
                direction: 'inbound',
                correlationId: $correlationId,
                parentRef: $parentRef,
            );
        }
    }

    /**
     * @return bool false if this sale was already recorded
     */
    private function recordSale(SquareSale $sale): bool
    {
        return DB::transaction(function () use ($sale) {
            $claim = SquareImportedSale::claim([
                'kind' => 'sale',
                'square_id' => $sale->squareOrderId,
                'square_order_id' => $sale->squareOrderId,
                'occurred_at' => $sale->placedAt,
            ]);

            if ($claim === null) {
                return false;
            }

            $claim->update(['local_reference' => $this->recorder->recordSale($sale)]);

            return true;
        });
    }

    /**
     * @return bool false if this refund was already recorded
     */
    private function recordRefund(SquareRefund $refund): bool
    {
        return DB::transaction(function () use ($refund) {
            $claim = SquareImportedSale::claim([
                'kind' => 'refund',
                'square_id' => $refund->squareRefundId,
                'square_order_id' => $refund->squareOrderId,
                'occurred_at' => $refund->refundedAt,
            ]);

            if ($claim === null) {
                return false;
            }

            $claim->update(['local_reference' => $this->recorder->recordRefund($refund)]);

            return true;
        });
    }

    /**
     * One bulk lookup per chunk of orders rather than one per order.
     *
     * @return array<string, array>
     */
    private function fetchCustomers(array $orders): array
    {
        $ids = collect($orders)->pluck('customer_id')->filter()->unique()->values()->all();

        return $ids === [] ? [] : $this->client->customers()->bulkRetrieve($ids);
    }

    /**
     * @return array<string, int> square_object_id => local item id, for
     *                            every linked variation sold or returned in these orders
     */
    private function localItemIds(array $orders): array
    {
        $variationIds = collect($orders)
            ->flatMap(fn (array $order) => [
                ...array_column($order['line_items'] ?? [], 'catalog_object_id'),
                ...collect($order['returns'] ?? [])->flatMap(fn ($return) => array_column($return['return_line_items'] ?? [], 'catalog_object_id'))->all(),
            ])
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($variationIds === []) {
            return [];
        }

        return SquareObjectMapping::forLocalCatalog()
            ->whereIn('square_object_id', $variationIds)
            ->pluck('mappable_id', 'square_object_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
