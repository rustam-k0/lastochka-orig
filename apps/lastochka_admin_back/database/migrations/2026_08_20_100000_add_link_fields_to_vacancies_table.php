<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ссылка на вакансию на внешнем ресурсе (например, hh.ru):
     * название ссылки и сам адрес.
     */
    public function up(): void
    {
        if (!Schema::hasTable('vacancies')) {
            return;
        }

        Schema::table('vacancies', function (Blueprint $table) {
            if (!Schema::hasColumn('vacancies', 'link_title')) {
                $table->string('link_title')->nullable()->after('conditions');
            }

            if (!Schema::hasColumn('vacancies', 'link_url')) {
                $table->string('link_url', 2048)->nullable()->after('link_title');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('vacancies')) {
            return;
        }

        Schema::table('vacancies', function (Blueprint $table) {
            foreach (['link_title', 'link_url'] as $column) {
                if (Schema::hasColumn('vacancies', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
