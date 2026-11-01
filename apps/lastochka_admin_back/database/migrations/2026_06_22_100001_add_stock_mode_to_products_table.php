<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'stock_mode')) {
                $table->string('stock_mode', 16)->nullable()->after('manual_quantum_unit_type_id');
            }
            if (!Schema::hasColumn('products', 'fixed_stock_quantity')) {
                $table->integer('fixed_stock_quantity')->nullable()->after('stock_mode');
            }
            if (!Schema::hasColumn('products', 'fixed_stock_unit_type_id')) {
                $table->integer('fixed_stock_unit_type_id')->nullable()->after('fixed_stock_quantity');
            }
            if (!Schema::hasColumn('products', 'effective_stock_mode')) {
                $table->string('effective_stock_mode', 16)->default('normal')->after('fixed_stock_unit_type_id');
            }
            if (!Schema::hasColumn('products', 'effective_fixed_stock')) {
                $table->integer('effective_fixed_stock')->nullable()->after('effective_stock_mode');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $columns = array_values(array_filter(
                ['stock_mode', 'fixed_stock_quantity', 'fixed_stock_unit_type_id', 'effective_stock_mode', 'effective_fixed_stock'],
                fn ($col) => Schema::hasColumn('products', $col)
            ));
            if ($columns) {
                $table->dropColumn($columns);
            }
        });
    }
};
