<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('categories', 'attribute_family_id')) {
            return;
        }

        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('attribute_family_id')
                ->nullable()
                ->after('is_perishable')
                ->constrained('attribute_families')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('attribute_family_id');
        });
    }
};
