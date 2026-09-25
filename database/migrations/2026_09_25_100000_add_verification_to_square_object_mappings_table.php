<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('square_object_mappings', function (Blueprint $table) {
            // Result of the last VerifySquareLinks run for this link:
            // ok | missing | archived | not_at_location -- see
            // SquareObjectMapping::VERIFICATION_STATUSES. Null until the
            // first check.
            $table->string('verification_status')->nullable()->after('sync_status');
            $table->timestamp('verified_at')->nullable()->after('verification_status');

            $table->index('verification_status');
        });
    }

    public function down(): void
    {
        Schema::table('square_object_mappings', function (Blueprint $table) {
            $table->dropIndex(['verification_status']);
            $table->dropColumn(['verification_status', 'verified_at']);
        });
    }
};
