<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_id')->nullable()->after('id');
            $table->boolean('is_configurable')->default(false)->after('status_id');

            $table->foreign('parent_id')
                ->references('id')
                ->on('products')
                ->nullOnDelete();

            $table->index('parent_id');
            $table->index('is_configurable');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropIndex(['parent_id']);
            $table->dropIndex(['is_configurable']);
            $table->dropColumn(['parent_id', 'is_configurable']);
        });
    }
};
