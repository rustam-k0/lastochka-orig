<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('brands')) {
            return;
        }

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('title', 255);
            $table->integer('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_active');
            $table->index('position');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
