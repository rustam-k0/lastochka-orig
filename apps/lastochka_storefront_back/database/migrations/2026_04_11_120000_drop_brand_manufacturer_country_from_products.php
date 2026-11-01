<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'manufacturer')) {
                $table->dropColumn('manufacturer');
            }
            if (Schema::hasColumn('products', 'brand')) {
                $table->dropColumn('brand');
            }
            if (Schema::hasColumn('products', 'country')) {
                $table->dropColumn('country');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('manufacturer', 200)->nullable();
            $table->string('brand', 200)->nullable();
            $table->string('country', 100)->nullable();
        });
    }
};
