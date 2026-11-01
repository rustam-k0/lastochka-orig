<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('yookassa_receipt_id')->unique();
            $table->string('type'); // payment | refund
            $table->string('status'); // pending | succeeded | canceled
            $table->string('fiscal_storage_number')->nullable();   // fn
            $table->string('fiscal_document_number')->nullable();  // fd
            $table->string('fiscal_attribute')->nullable();        // fp
            $table->dateTime('registered_at')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->json('items');
            $table->json('raw');
            $table->timestamps();

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_receipts');
    }
};
