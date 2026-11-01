<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('measurement_unit', 20)->nullable()->after('quantity');
        });

        // Заполнить существующие записи из связанного product
        if (DB::getDriverName() === 'mysql') {
            DB::statement('
                UPDATE order_items oi
                JOIN products p ON oi.product_id = p.id
                SET oi.measurement_unit = p.measurement_unit
                WHERE oi.measurement_unit IS NULL
            ');
        } else {
            DB::statement('
                UPDATE order_items
                SET measurement_unit = (
                    SELECT p.measurement_unit FROM products p WHERE p.id = order_items.product_id
                )
                WHERE measurement_unit IS NULL AND product_id IS NOT NULL
            ');
        }
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('measurement_unit');
        });
    }
};
