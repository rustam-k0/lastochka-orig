<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            if (!Schema::hasColumn('categories', 'stock_mode')) {
                $table->string('stock_mode', 16)->default('normal')->after('quantum_unit_type_id');
            }
            if (!Schema::hasColumn('categories', 'fixed_stock_quantity')) {
                $table->integer('fixed_stock_quantity')->nullable()->after('stock_mode');
            }
            if (!Schema::hasColumn('categories', 'fixed_stock_unit_type_id')) {
                $table->integer('fixed_stock_unit_type_id')->nullable()->after('fixed_stock_quantity');
            }
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $columns = array_values(array_filter(
                ['stock_mode', 'fixed_stock_quantity', 'fixed_stock_unit_type_id'],
                fn ($col) => Schema::hasColumn('categories', $col)
            ));
            if ($columns) {
                $table->dropColumn($columns);
            }
        });
    }
};
