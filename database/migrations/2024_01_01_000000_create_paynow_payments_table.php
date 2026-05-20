<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paynow_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('paynow_payment_id', 20)->unique();
            $table->string('external_id', 36)->index();
            $table->string('status', 20);
            $table->unsignedInteger('amount');
            $table->char('currency', 3)->default('PLN');
            $table->string('redirect_url', 500)->nullable();
            $table->foreignId('parent_payment_id')->nullable()->constrained('paynow_payments')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paynow_payments');
    }
};
