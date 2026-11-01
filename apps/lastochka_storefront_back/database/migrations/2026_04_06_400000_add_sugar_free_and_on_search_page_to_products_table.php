<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'sugar_free')) {
                $table->boolean('sugar_free')->default(false)->after('is_culinary');
            }
            if (!Schema::hasColumn('products', 'on_search_page')) {
                $table->boolean('on_search_page')->default(false)->after('sugar_free');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['sugar_free', 'on_search_page']);
        });
    }
};
