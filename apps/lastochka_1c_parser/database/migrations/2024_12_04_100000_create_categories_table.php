<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('external_id', 36)->unique()->comment('GUID из 1С');
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name', 255);
            $table->integer('sort')->default(500)->index();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('synced_at')->nullable()->comment('Время последней синхронизации');
            $table->timestamps();

            $table->index('external_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
