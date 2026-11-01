<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('brands')) {
            return;
        }

        Schema::table('brands', function (Blueprint $table) {
            if (! Schema::hasColumn('brands', 'country_origin_code')) {
                $table->char('country_origin_code', 2)->nullable()->after('country_origin');
            }
            if (! Schema::hasColumn('brands', 'country_production_code')) {
                $table->char('country_production_code', 2)->nullable()->after('country_production');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('brands')) {
            return;
        }

        Schema::table('brands', function (Blueprint $table) {
            foreach (['country_production_code', 'country_origin_code'] as $column) {
                if (Schema::hasColumn('brands', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
