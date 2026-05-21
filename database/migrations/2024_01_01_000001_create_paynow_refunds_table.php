<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paynow_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paynow_payment_id')->constrained('paynow_payments')->cascadeOnDelete();
            $table->foreignId('lunar_transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
            $table->string('refund_id', 20)->unique();
            $table->string('status', 20);
            $table->unsignedInteger('amount');
            $table->string('failure_reason', 50)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paynow_refunds');
    }
};
