<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'pickup_only')) {
                $table->boolean('pickup_only')->default(false)->after('served_warm')->comment('Товар доступен только для самовывоза — ограничивает способы доставки заказа');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('pickup_only');
        });
    }
};
