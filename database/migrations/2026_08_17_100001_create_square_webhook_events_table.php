<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('square_webhook_events', function (Blueprint $table) {
            $table->id();

            // The idempotency guarantee itself: Square retries webhook
            // deliveries aggressively, and the existing Stripe integration
            // has no event-id dedupe at all. SquareWebhookEvent::claim()
            // relies on this unique constraint (not an application-level
            // check-then-insert) to be safe under concurrent duplicate
            // deliveries -- see that method's docblock.
            $table->string('square_event_id')->unique();

            // Left un-indexed on its own: the composite index below on
            // (event_type, created_at) already serves single-column
            // event_type lookups via its leftmost prefix, so a second
            // index here would just be dead weight on every insert.
            $table->string('event_type');

            $table->json('payload');

            // received | processed | failed | skipped -- see
            // SquareWebhookEvent::STATUSES.
            $table->string('status')->default('received');

            // Doubles as the free-text explanation for both 'failed' and
            // 'skipped' -- there's no separate reason column, since both
            // cases boil down to "here's why this event didn't result in
            // processed data".
            $table->text('error')->nullable();

            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            // Powers "recent events of this type" admin views without a
            // full table scan.
            $table->index(['event_type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('square_webhook_events');
    }
};
