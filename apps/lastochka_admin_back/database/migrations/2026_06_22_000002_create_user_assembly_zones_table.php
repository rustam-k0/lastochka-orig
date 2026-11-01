<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Привязка сотрудника-сборщика к зоне сборки в рамках магазина.
     *
     * date = NULL  → постоянная привязка (по умолчанию для этого сотрудника в магазине).
     * date = дата  → привязка на конкретную дату (имеет приоритет над постоянной).
     *
     * Один сотрудник в одном магазине на дату/постоянно — одна зона
     * (unique по user_id + shop_id + date).
     */
    public function up(): void
    {
        if (Schema::hasTable('user_assembly_zones')) {
            return;
        }

        Schema::create('user_assembly_zones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('shop_id')->constrained('shops')->cascadeOnDelete();
            $table->foreignId('assembly_zone_id')->constrained('assembly_zones')->cascadeOnDelete();
            $table->date('date')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'shop_id', 'date'], 'user_assembly_zones_user_shop_date_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_assembly_zones');
    }
};
