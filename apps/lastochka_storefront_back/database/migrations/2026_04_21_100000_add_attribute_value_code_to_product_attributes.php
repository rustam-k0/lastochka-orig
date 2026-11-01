<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_attributes', function (Blueprint $table) {
            $table->string('attribute_value_code', 200)->nullable()->after('attribute_value_id');
            $table->index(['code', 'attribute_value_code'], 'product_attributes_code_value_code_idx');
        });
    }

    public function down(): void
    {
        Schema::table('product_attributes', function (Blueprint $table) {
            $table->dropIndex('product_attributes_code_value_code_idx');
            $table->dropColumn('attribute_value_code');
        });
    }
};
