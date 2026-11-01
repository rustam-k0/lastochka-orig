<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('products', 'showcase_category_id')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('showcase_category_id');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('products', 'showcase_category_id')) {
            return;
        }

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('showcase_category_id')->nullable()->constrained('categories')->nullOnDelete();
        });
    }
};
