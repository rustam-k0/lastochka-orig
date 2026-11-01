<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('products', 'barcodes')) {
            Schema::table('products', function (Blueprint $table) {
                $table->json('barcodes')->nullable()->after('barcode');
            });
        }

        DB::statement(
            'UPDATE products
                SET barcodes = JSON_ARRAY(barcode)
              WHERE barcode IS NOT NULL
                AND barcode != ""
                AND (barcodes IS NULL OR JSON_LENGTH(barcodes) = 0)'
        );
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'barcodes')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('barcodes');
            });
        }
    }
};
