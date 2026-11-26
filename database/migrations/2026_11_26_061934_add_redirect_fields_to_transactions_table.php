<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->string('success_redirect_url', 2048)
                ->nullable()
                ->after('status');
            $table->string('failure_redirect_url', 2048)
                ->nullable()
                ->after('success_redirect_url');
            $table->timestamp('callback_dispatched_at')
                ->nullable()
                ->after('failure_redirect_url');
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table) {
            $table->dropColumn([
                'callback_dispatched_at',
                'failure_redirect_url',
                'success_redirect_url',
            ]);
        });
    }
};
