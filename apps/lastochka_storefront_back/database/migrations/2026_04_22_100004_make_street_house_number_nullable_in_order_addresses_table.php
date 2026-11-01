<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_addresses', function (Blueprint $table) {
            $table->string('street', 200)->nullable()->change();
            $table->string('house_number', 20)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('order_addresses', function (Blueprint $table) {
            $table->string('street', 200)->nullable(false)->change();
            $table->string('house_number', 20)->nullable(false)->change();
        });
    }
};
