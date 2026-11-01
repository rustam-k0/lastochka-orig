<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('id')
                ->constrained('order_items')->cascadeOnDelete();
            $table->foreignId('source_supplement_value_id')->nullable()->after('parent_id')
                ->constrained('supplement_values')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropConstrainedForeignId('source_supplement_value_id');
        });
    }
};
