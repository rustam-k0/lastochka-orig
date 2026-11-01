<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('barcode_prefixes')) {
            return;
        }
        Schema::create('barcode_prefixes', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32)->unique();
            $table->string('value', 16);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('barcode_prefixes');
    }
};
