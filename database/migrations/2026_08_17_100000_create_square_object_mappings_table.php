<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('square_object_mappings', function (Blueprint $table) {
            $table->id();

            // Polymorphic on purpose: today the only mappable type is
            // App\Models\Product, but Square tracks inventory at the
            // ItemVariation level under a CatalogItem parent, and this app
            // has neither an external-id column on products nor a product-
            // variants table yet. morphs() keeps the pair non-nullable and
            // also creates the (mappable_type, mappable_id) index this
            // table needs, so real variants can be mapped later without a
            // schema rewrite here.
            $table->morphs('mappable');

            $table->string('square_object_id')->unique();

            // Set only when this row maps an ITEM_VARIATION -- links back
            // to the parent CatalogItem id. Inventory counts live on the
            // variation in Square, but a Product conceptually maps to the
            // item, so the parent link is what lets the sync code find
            // "the item this variation belongs to" without a round trip.
            $table->string('square_parent_object_id')->nullable();

            // 'ITEM' or 'ITEM_VARIATION'.
            $table->string('square_object_type');

            // Square's optimistic-concurrency token. Required on every
            // upsert -- Square rejects a write made against a stale
            // version, so this has to be captured on every pull and sent
            // back unchanged on every push that isn't itself the write
            // that bumps it.
            $table->unsignedBigInteger('square_version')->nullable();

            $table->timestamp('last_pushed_at')->nullable();
            $table->timestamp('last_pulled_at')->nullable();

            // Hash of the payload we last pushed. Square's
            // catalog.version.updated webhook fires on our own writes too
            // (an echo of the push we just made), so comparing an inbound
            // payload's hash against this is how the pull handler tells
            // "Square changed this independently" from "we just pushed
            // this ourselves" without a racy timestamp comparison.
            $table->string('last_pushed_hash')->nullable();

            // linked | pending | conflict | orphaned -- see
            // SquareObjectMapping::SYNC_STATUSES.
            $table->string('sync_status')->default('pending')->index();

            // Soft-deletes rather than hard-deletes so unlinking a mapping
            // (Product::unlink() equivalent) keeps its sync history
            // queryable instead of erasing the fact a link ever existed.
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('square_object_mappings');
    }
};
