<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'adult_confirmed')) {
                $table->boolean('adult_confirmed')->default(false)->after('email_enabled')
                    ->comment('Подтвердил совершеннолетие (18+) — показывать adult_only товары');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'adult_confirmed')) {
                $table->dropColumn('adult_confirmed');
            }
        });
    }
};
