<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            // Удаляем индекс, т.к. поиск по хешу через индекс невозможен
            $table->dropIndex(['phone', 'code']);

            // Увеличиваем размер для bcrypt хеша (60 символов)
            $table->string('code', 60)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->string('code', 6)->change();
            $table->index(['phone', 'code']);
        });
    }
};
