<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->index(
                ['status', 'created_at'],
                'payments_status_created_at_index'
            );
            $table->index('external_id', 'payments_external_id_index');
        });

        Schema::table('payment_logs', function (Blueprint $table) {
            $table->index('created_at', 'payment_logs_created_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('payment_logs', function (Blueprint $table) {
            $table->dropIndex('payment_logs_created_at_index');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('payments_status_created_at_index');
            $table->dropIndex('payments_external_id_index');
        });
    }
};
