<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_fcm_tokens', function (Blueprint $table) {
            $columns = array_filter(
                ['period', 'sound'],
                fn (string $col) => Schema::hasColumn('user_fcm_tokens', $col),
            );

            if ($columns) {
                $table->dropColumn($columns);
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_fcm_tokens', function (Blueprint $table) {
            if (! Schema::hasColumn('user_fcm_tokens', 'period')) {
                $table->integer('period')->nullable();
            }
            if (! Schema::hasColumn('user_fcm_tokens', 'sound')) {
                $table->string('sound')->nullable();
            }
        });
    }
};
