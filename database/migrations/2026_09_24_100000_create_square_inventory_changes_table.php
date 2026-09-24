<?php

use App\Actions\UpdateSiteSetting;
use Cultpantry\SquareSync\Actions\PullSquareInventoryChanges;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ledger of Square inventory changes (sales, restocks) that have
        // been applied to local stock. Only applied changes are recorded
        // -- a recount or waste entry made on Square is never applied, so
        // there's nothing to dedupe.
        Schema::create('square_inventory_changes', function (Blueprint $table) {
            $table->id();

            // The exactly-once guarantee: PullSquareInventoryChanges walks
            // an overlapping time window on every run (Square's change log
            // is eventually consistent), so the same change is routinely
            // seen twice. This unique constraint, not a check-then-insert,
            // is what stops it being applied twice under concurrent runs.
            $table->string('square_change_id')->unique();

            $table->string('square_object_id');
            $table->unsignedBigInteger('local_item_id');

            // sale | restock -- see SquareInventoryChange::KINDS.
            $table->string('kind');

            // Signed as applied locally: negative for a sale, positive for a restock.
            $table->integer('quantity');

            $table->string('from_state')->nullable();
            $table->string('to_state')->nullable();

            // Square's links back to what caused the change -- the order
            // (transaction_id) for a sale, the refund for a restock.
            $table->string('transaction_id')->nullable()->index();
            $table->string('refund_id')->nullable()->index();

            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->index(['local_item_id', 'occurred_at']);
        });

        // Deploying this migration is the moment the old sync (which
        // copied Square's absolute count into local stock) stops. Seeding
        // the change-log watermark to now means the first pull starts
        // right there: sales before this point were already applied by
        // the old sync and must not be applied again as deltas.
        app(UpdateSiteSetting::class)->handle(PullSquareInventoryChanges::WATERMARK_KEY, now()->toIso8601String());
    }

    public function down(): void
    {
        Schema::dropIfExists('square_inventory_changes');
    }
};
