<?php

namespace Cultpantry\SquareSync\Http\Controllers;

use App\Actions\RecordEvent;
use App\Http\Controllers\Controller;
use App\Models\Event;
use Cultpantry\SquareSync\Actions\ApplyInventoryCountFromSquare;
use Cultpantry\SquareSync\Actions\Exceptions\SquareWebhookVerificationException;
use Cultpantry\SquareSync\Actions\PullSquareCatalogDelta;
use Cultpantry\SquareSync\Actions\VerifySquareWebhookSignature;
use Cultpantry\SquareSync\Models\SquareWebhookEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Inbound half of the Square integration: Square -> this app. Mirrors the
 * shape of App\Http\Controllers\WebhookController::stripe() -- verify,
 * claim (Square's analogue of Stripe's implicit idempotency, made explicit
 * here because Square retries deliveries far more aggressively and the
 * existing Stripe integration has no event-id dedupe at all), switch on
 * type, thread a correlation id / parent event into every child event,
 * mark processed/failed.
 *
 * Status code contract -- this is what tells Square whether to retry:
 *  - 200: handled, OR a duplicate delivery, OR an unrecognised event type
 *         (retrying an event type this module will never handle just
 *         wastes Square's retry budget and ours).
 *  - 400: malformed request (missing signature header, unparseable body).
 *  - 401: signature present but doesn't verify.
 *  - 500: signature verified and body parsed fine, but something inside
 *         the actual handler threw -- Square should retry this one.
 */
class SquareWebhookController extends Controller
{
    public function __construct(
        private readonly VerifySquareWebhookSignature $verifySignature,
        private readonly ApplyInventoryCountFromSquare $applyInventoryCount,
        private readonly PullSquareCatalogDelta $pullCatalogDelta,
        private readonly RecordEvent $recordEvent,
    ) {}

    public function handle(Request $request): JsonResponse
    {
        try {
            $this->verifySignature->handle($request);
        } catch (SquareWebhookVerificationException $e) {
            Log::warning('Square webhook rejected', [
                'reason' => $e->reason,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['error' => $e->getMessage()], $e->httpStatus());
        }

        $payload = $request->json()->all();
        $squareEventId = $payload['event_id'] ?? null;
        $eventType = $payload['type'] ?? null;

        if (blank($squareEventId) || blank($eventType)) {
            Log::warning('Square webhook payload missing event_id or type', ['payload' => $payload]);

            return response()->json(['error' => 'Malformed webhook payload'], Response::HTTP_BAD_REQUEST);
        }

        // The idempotency gate. A retried delivery gets null here and must
        // not touch inventory, catalog state, or the audit log a second
        // time -- see SquareWebhookEvent::claim()'s docblock for why this
        // is safe under concurrent duplicate deliveries.
        $webhookEvent = SquareWebhookEvent::claim($squareEventId, $eventType, $payload);

        if ($webhookEvent === null) {
            return response()->json([
                'status' => 'duplicate',
                'message' => 'Event already processed, ignored.',
            ], Response::HTTP_OK);
        }

        // Square event ids are already unique and stable per delivery
        // (retries of the same delivery reuse it), which makes them a
        // ready-made correlation id -- every child event this request
        // produces threads back to the one webhook that caused it.
        $correlationId = $squareEventId;

        $parentEvent = $this->recordEvent->webhookReceived('Square', $eventType, [
            'square_event_id' => $squareEventId,
        ], $correlationId);

        try {
            return match ($eventType) {
                'inventory.count.updated' => $this->handleInventoryCountUpdated($payload, $webhookEvent, $correlationId, $parentEvent),
                'catalog.version.updated' => $this->handleCatalogVersionUpdated($webhookEvent, $correlationId, $parentEvent),
                default => $this->handleUnknownEvent($eventType, $webhookEvent, $correlationId, $parentEvent),
            };
        } catch (Throwable $e) {
            $webhookEvent->markFailed($e->getMessage());

            $this->recordEvent->webhookFailed('Square', $eventType, $e->getMessage(), [], $correlationId, $parentEvent);

            Log::error('Square webhook processing error', [
                'event_type' => $eventType,
                'square_event_id' => $squareEventId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // 500 so Square retries -- this branch is "a known event type
            // failed while processing", not "we don't understand this
            // event", which handleUnknownEvent covers separately with 200.
            return response()->json(['error' => 'Webhook processing failed'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function handleInventoryCountUpdated(array $payload, SquareWebhookEvent $webhookEvent, string $correlationId, Event $parentEvent): JsonResponse
    {
        $counts = $payload['data']['object']['inventory_counts'] ?? [];

        foreach ($counts as $count) {
            $this->applyInventoryCount->handle($count, $correlationId, $parentEvent);
        }

        $webhookEvent->markProcessed();

        $this->recordEvent->webhookProcessed('Square', 'inventory.count.updated', null, [
            'counts_processed' => count($counts),
        ], $correlationId, $parentEvent);

        return response()->json(['status' => 'success'], Response::HTTP_OK);
    }

    private function handleCatalogVersionUpdated(SquareWebhookEvent $webhookEvent, string $correlationId, Event $parentEvent): JsonResponse
    {
        // The payload only tells us *that* something changed, not *what* --
        // so the real work is a delta pull against Square's search API,
        // keyed off a watermark this action owns.
        $tally = $this->pullCatalogDelta->handle($correlationId, $parentEvent);

        $webhookEvent->markProcessed();

        $this->recordEvent->webhookProcessed('Square', 'catalog.version.updated', null, $tally, $correlationId, $parentEvent);

        return response()->json(['status' => 'success', ...$tally], Response::HTTP_OK);
    }

    private function handleUnknownEvent(string $eventType, SquareWebhookEvent $webhookEvent, string $correlationId, Event $parentEvent): JsonResponse
    {
        $reason = "Unhandled Square event type: {$eventType}";

        $webhookEvent->markSkipped($reason);

        $this->recordEvent->webhookProcessed('Square', $eventType, null, [
            'status' => 'skipped',
            'reason' => $reason,
        ], $correlationId, $parentEvent);

        // 200, not an error status -- an event type this module doesn't
        // (yet) handle is expected traffic, not a failure. A non-2xx here
        // would just make Square retry an event that will never process
        // differently.
        return response()->json(['status' => 'skipped', 'message' => $reason], Response::HTTP_OK);
    }
}
