<?php

use App\Actions\UpdateSiteSetting;
use Cultpantry\SquareSync\Actions\PullSquareSales;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ledger of Square sales and refunds already handed to the host's
        // SquareSaleRecorder -- the exactly-once guarantee for sales
        // records, the same way square_inventory_changes is for stock.
        Schema::create('square_imported_sales', function (Blueprint $table) {
            $table->id();

            // sale | refund -- see SquareImportedSale::KINDS.
            $table->string('kind');

            // A Square order id for a sale, a refund id (or, for a return
            // with no refund attached, the return order's id) for a refund.
            // Unique per kind, which is what claim() relies on.
            $table->string('square_id');

            // The sale a refund belongs to; equal to square_id for a sale.
            $table->string('square_order_id')->index();

            // Whatever the host's recorder returned (e.g. its order id).
            $table->string('local_reference')->nullable();

            $table->timestamp('occurred_at')->nullable();
            $table->timestamps();

            $table->unique(['kind', 'square_id']);
        });

        // Ongoing pulls start at deploy time; anything earlier is the
        // backfill's job (square:import-sales --since=...).
        app(UpdateSiteSetting::class)->handle(PullSquareSales::WATERMARK_KEY, now()->toIso8601String());
    }

    public function down(): void
    {
        Schema::dropIfExists('square_imported_sales');
    }
};
