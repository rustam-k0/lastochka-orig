<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $columns = [
            'address' => fn (Blueprint $t) => $t->string('address', 500)->nullable()->after('longitude'),
            'is_pickup_enabled' => fn (Blueprint $t) => $t->boolean('is_pickup_enabled')->default(false)->after('is_b2b'),
            'min_pickup_order_amount' => fn (Blueprint $t) => $t->unsignedInteger('min_pickup_order_amount')->nullable()->after('is_pickup_enabled'),
            'pickup_payment_methods' => fn (Blueprint $t) => $t->json('pickup_payment_methods')->nullable()->after('min_pickup_order_amount'),
            'pickup_auto_cancel_enabled' => fn (Blueprint $t) => $t->boolean('pickup_auto_cancel_enabled')->default(true)->after('pickup_payment_methods'),
            'pickup_wait_perishable_hours' => fn (Blueprint $t) => $t->unsignedTinyInteger('pickup_wait_perishable_hours')->default(1)->after('pickup_auto_cancel_enabled'),
            'pickup_wait_regular_hours' => fn (Blueprint $t) => $t->unsignedTinyInteger('pickup_wait_regular_hours')->default(3)->after('pickup_wait_perishable_hours'),
            'pickup_grace_period_hours' => fn (Blueprint $t) => $t->unsignedTinyInteger('pickup_grace_period_hours')->default(1)->after('pickup_wait_regular_hours'),
        ];

        foreach ($columns as $name => $addColumn) {
            if (! Schema::hasColumn('stores', $name)) {
                Schema::table('stores', fn (Blueprint $table) => $addColumn($table));
            }
        }
    }

    public function down(): void
    {
        Schema::table('stores', function (Blueprint $table) {
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
