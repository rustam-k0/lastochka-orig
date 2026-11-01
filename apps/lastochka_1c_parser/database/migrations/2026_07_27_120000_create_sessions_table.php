<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SESSION_DRIVER по умолчанию = database (config/session.php, .env.example),
 * но миграции таблицы sessions в проекте не было: любой запрос через
 * StartSession (в т.ч. обмен /1c-exchange/{shop_code}) падал с
 * "Base table or view not found: 1146 Table '<db>.sessions' doesn't exist"
 * (GlitchTip 1C_PARSER-B).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('sessions')) {
            return;
        }

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
    }
};
