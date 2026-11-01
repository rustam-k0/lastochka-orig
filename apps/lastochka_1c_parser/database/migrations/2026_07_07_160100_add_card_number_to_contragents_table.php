<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('contragents', 'card_number')) {
            return;
        }
        Schema::table('contragents', function (Blueprint $table) {
            $table->string('card_number', 16)->nullable()->after('loyalty_id');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('contragents', 'card_number')) {
            return;
        }
        Schema::table('contragents', function (Blueprint $table) {
            $table->dropColumn('card_number');
        });
    }
};
