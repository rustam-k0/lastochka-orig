<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            if (!Schema::hasColumn('orders', 'shipping_tariff_code')) {
                $t->string('shipping_tariff_code', 64)->nullable()->after('shipping_method');
            }
            if (!Schema::hasColumn('orders', 'pickup_external_id')) {
                $t->string('pickup_external_id', 64)->nullable()->after('shipping_tariff_code');
            }
            if (!Schema::hasColumn('orders', 'pickup_address_data')) {
                $t->json('pickup_address_data')->nullable()->after('pickup_external_id');
            }
            if (!Schema::hasColumn('orders', 'cdek_number')) {
                $t->string('cdek_number', 64)->nullable()->after('pickup_address_data');
            }
            if (!Schema::hasColumn('orders', 'cdek_status')) {
                $t->string('cdek_status', 64)->nullable()->after('cdek_number');
            }
            if (!Schema::hasColumn('orders', 'rp_barcode')) {
                $t->string('rp_barcode', 64)->nullable()->after('cdek_status');
            }
            if (!Schema::hasColumn('orders', 'rp_status')) {
                $t->string('rp_status', 64)->nullable()->after('rp_barcode');
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $columns = [
                'shipping_tariff_code', 'pickup_external_id', 'pickup_address_data',
                'cdek_number', 'cdek_status', 'rp_barcode', 'rp_status',
            ];
            $existing = array_filter($columns, fn($c) => Schema::hasColumn('orders', $c));
            if ($existing) {
                $t->dropColumn(array_values($existing));
            }
        });
    }
};
