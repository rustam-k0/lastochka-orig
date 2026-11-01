<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('source')->nullable();
            $table->string('external_id')->nullable();
            $table->string('title', 255);
            $table->string('logo_path', 500)->nullable();
            $table->integer('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['source', 'external_id']);
            $table->index('is_active');
            $table->index('position');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
