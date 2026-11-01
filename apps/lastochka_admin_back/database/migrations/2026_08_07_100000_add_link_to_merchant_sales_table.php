<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchant_sales', function (Blueprint $table) {
            if (!Schema::hasColumn('merchant_sales', 'link')) {
                $table->string('link')->nullable()->after('title');
            }
        });
    }

    public function down(): void
    {
        Schema::table('merchant_sales', function (Blueprint $table) {
            $table->dropColumn('link');
        });
    }
};
