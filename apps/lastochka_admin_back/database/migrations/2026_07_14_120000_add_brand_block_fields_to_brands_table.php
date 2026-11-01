<?php

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
            if (! Schema::hasColumn('brands', 'link')) {
                $table->string('link', 500)->nullable()->after('title');
            }
            if (! Schema::hasColumn('brands', 'country_origin')) {
                $table->string('country_origin', 255)->nullable()->after('link');
            }
            if (! Schema::hasColumn('brands', 'country_production')) {
                $table->string('country_production', 255)->nullable()->after('country_origin');
            }
            if (! Schema::hasColumn('brands', 'certificate_path')) {
                $table->string('certificate_path', 500)->nullable()->after('country_production');
            }
            if (! Schema::hasColumn('brands', 'certificate_name')) {
                $table->string('certificate_name', 255)->nullable()->after('certificate_path');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('brands')) {
            return;
        }

        Schema::table('brands', function (Blueprint $table) {
            foreach (['certificate_name', 'certificate_path', 'country_production', 'country_origin', 'link'] as $column) {
                if (Schema::hasColumn('brands', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
