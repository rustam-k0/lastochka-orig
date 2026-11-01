<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->string('source')->nullable();
            $table->string('external_id')->nullable();
            $table->string('question', 255);
            $table->text('answer')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('position')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['source', 'external_id']);
            $table->index('is_active');
            $table->index('position');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faqs');
    }
};
