<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            if (!Schema::hasColumn('orders', 'shipping_method')) {
                $t->enum('shipping_method', ['own_courier', 'cdek', 'russian_post'])
                    ->default('own_courier')
                    ->index();
            }
            if (!Schema::hasColumn('orders', 'shipping_tariff_code')) {
                $t->string('shipping_tariff_code', 64)->nullable();
            }
            if (!Schema::hasColumn('orders', 'pickup_external_id')) {
                $t->string('pickup_external_id', 64)->nullable();
            }
            if (!Schema::hasColumn('orders', 'pickup_address_data')) {
                $t->json('pickup_address_data')->nullable();
            }
            if (!Schema::hasColumn('orders', 'cdek_order_uuid')) {
                $t->string('cdek_order_uuid', 64)->nullable()->unique();
            }
            if (!Schema::hasColumn('orders', 'cdek_number')) {
                $t->string('cdek_number', 64)->nullable();
            }
            if (!Schema::hasColumn('orders', 'cdek_status')) {
                $t->string('cdek_status', 64)->nullable();
            }
            if (!Schema::hasColumn('orders', 'cdek_sent_at')) {
                $t->timestamp('cdek_sent_at')->nullable();
            }
            if (!Schema::hasColumn('orders', 'cdek_response')) {
                $t->json('cdek_response')->nullable();
            }
            if (!Schema::hasColumn('orders', 'rp_shipment_id')) {
                $t->string('rp_shipment_id', 64)->nullable();
            }
            if (!Schema::hasColumn('orders', 'rp_barcode')) {
                $t->string('rp_barcode', 64)->nullable();
            }
            if (!Schema::hasColumn('orders', 'rp_status')) {
                $t->string('rp_status', 64)->nullable();
            }
            if (!Schema::hasColumn('orders', 'rp_sent_at')) {
                $t->timestamp('rp_sent_at')->nullable();
            }
            if (!Schema::hasColumn('orders', 'rp_response')) {
                $t->json('rp_response')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $t) {
            $columns = [
                'shipping_method', 'shipping_tariff_code', 'pickup_external_id', 'pickup_address_data',
                'cdek_order_uuid', 'cdek_number', 'cdek_status', 'cdek_sent_at', 'cdek_response',
                'rp_shipment_id', 'rp_barcode', 'rp_status', 'rp_sent_at', 'rp_response',
            ];
            $existing = array_filter($columns, fn($c) => Schema::hasColumn('orders', $c));
            if ($existing) {
                $t->dropColumn(array_values($existing));
            }
        });
    }
};
