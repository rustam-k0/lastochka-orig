<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('held_amount', 10, 2)->nullable()->after('is_paid');
            $table->decimal('captured_amount', 10, 2)->nullable()->after('held_amount');
            $table->decimal('refunded_amount', 10, 2)->nullable()->after('captured_amount');
            $table->foreignId('payment_card_id')->nullable()->after('refunded_amount')
                ->constrained('payment_cards')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropForeign(['payment_card_id']);
            $table->dropColumn(['held_amount', 'captured_amount', 'refunded_amount', 'payment_card_id']);
        });
    }
};
