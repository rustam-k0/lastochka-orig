<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('categories', 'tag_text')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->string('tag_text', 100)->nullable()->after('show_on_main');
            });
        }

        if (!Schema::hasColumn('categories', 'tag_color')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->string('tag_color', 7)->nullable()->after('tag_text');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('categories', 'tag_color')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->dropColumn('tag_color');
            });
        }

        if (Schema::hasColumn('categories', 'tag_text')) {
            Schema::table('categories', function (Blueprint $table) {
                $table->dropColumn('tag_text');
            });
        }
    }
};
