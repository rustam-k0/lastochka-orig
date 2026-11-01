<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('products', 'category_id')) {
            return;
        }

        DB::table('category_product')->insertUsing(
            ['product_id', 'category_id', 'position'],
            DB::table('products')
                ->select('id', 'category_id', DB::raw('0 as position'))
                ->whereNotNull('category_id')
                ->whereNotExists(function ($query) {
                    $query->select(DB::raw(1))
                        ->from('category_product as cp')
                        ->whereColumn('cp.product_id', 'products.id')
                        ->whereColumn('cp.category_id', 'products.category_id');
                })
        );
    }

    public function down(): void
    {
        // Безопасный откат через drop pivot из D1.
    }
};
