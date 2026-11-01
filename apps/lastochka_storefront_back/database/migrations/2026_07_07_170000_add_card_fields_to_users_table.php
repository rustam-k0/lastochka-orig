<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'card_number')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            $table->string('card_number', 16)->nullable()->unique()->after('loyalty_id');
            $table->timestamp('card_created_at')->nullable()->after('card_number');
            $table->timestamp('card_confirmed_at')->nullable()->after('card_created_at');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('users', 'card_number')) {
            return;
        }
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['card_number', 'card_created_at', 'card_confirmed_at']);
        });
    }
};
