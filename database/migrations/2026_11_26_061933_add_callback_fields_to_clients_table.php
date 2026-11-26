<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('callback_url', 2048)->nullable()->after('status');
            $table->text('callback_secret_encrypted')->nullable()->after('callback_url');
            $table->json('allowed_redirect_domains')->nullable()->after('callback_secret_encrypted');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn([
                'allowed_redirect_domains',
                'callback_secret_encrypted',
                'callback_url',
            ]);
        });
    }
};
