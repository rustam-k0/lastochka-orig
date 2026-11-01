<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('is_pickup_enabled')->default(false)->after('is_b2b');
            $table->unsignedInteger('min_pickup_order_amount')->nullable()->after('is_pickup_enabled');
            $table->json('pickup_payment_methods')->nullable()->after('min_pickup_order_amount');
            $table->boolean('pickup_auto_cancel_enabled')->default(true)->after('pickup_payment_methods');
            $table->unsignedTinyInteger('pickup_wait_perishable_hours')->default(1)->after('pickup_auto_cancel_enabled');
            $table->unsignedTinyInteger('pickup_wait_regular_hours')->default(3)->after('pickup_wait_perishable_hours');
            $table->unsignedTinyInteger('pickup_grace_period_hours')->default(1)->after('pickup_wait_regular_hours');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn([
                'is_pickup_enabled',
                'min_pickup_order_amount',
                'pickup_payment_methods',
                'pickup_auto_cancel_enabled',
                'pickup_wait_perishable_hours',
                'pickup_wait_regular_hours',
                'pickup_grace_period_hours',
            ]);
        });
    }
};
