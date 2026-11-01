<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'stock_mode')) {
                $table->string('stock_mode', 16)->default('normal')->after('quantity_step');
            }
            if (!Schema::hasColumn('products', 'fixed_stock')) {
                $table->integer('fixed_stock')->nullable()->after('stock_mode');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $columns = array_values(array_filter(
                ['stock_mode', 'fixed_stock'],
                fn ($col) => Schema::hasColumn('products', $col)
            ));
            if ($columns) {
                $table->dropColumn($columns);
            }
        });
    }
};
