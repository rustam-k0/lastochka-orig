<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_issue_reports')) {
            return;
        }

        Schema::create('product_issue_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('store_id')->constrained()->cascadeOnDelete();
            $table->string('email', 255);
            $table->string('type', 50)->default('description_error');
            $table->text('message');
            $table->timestamps();

            $table->index('product_id');
            $table->index(['store_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_issue_reports');
    }
};
