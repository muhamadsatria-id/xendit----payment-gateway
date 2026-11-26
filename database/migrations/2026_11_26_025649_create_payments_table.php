
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();

            $table->foreignId('transaction_id')
                ->constrained('transactions')
                ->cascadeOnDelete();

            $table->string('provider', 30)->default('xendit');

            $table->string('external_id', 100);
            $table->string('gateway_invoice_id', 100)->nullable();

            $table->text('invoice_url')->nullable();

            $table->unsignedBigInteger('amount');
            $table->char('currency', 3)->default('IDR');

            $table->string('status', 20)->default('PENDING');

            $table->timestamp('expired_at')->nullable();
            $table->timestamp('paid_at')->nullable();

            $table->json('gateway_response')->nullable();

            $table->timestamps();

            $table->unique(['provider', 'external_id']);
            $table->unique(['provider', 'gateway_invoice_id']);

            $table->index(['transaction_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
