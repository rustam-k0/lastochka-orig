<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Хранит TOTP-timestep последнего успешно использованного кода
            // (защита от replay: следующий код должен быть строго новее).
            $table->unsignedBigInteger('two_factor_last_otp_timestamp')->nullable()->after('two_factor_confirmed_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('two_factor_last_otp_timestamp');
        });
    }
};
