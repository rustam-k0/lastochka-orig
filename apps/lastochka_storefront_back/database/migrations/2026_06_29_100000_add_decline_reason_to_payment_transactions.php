<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            // Машинный код причины отказа ЮKassa (cancellation_details.reason),
            // напр. 'insufficient_funds'. Клиент идентифицирует кейс по коду,
            // а не по тексту error_message.
            if (! Schema::hasColumn('payment_transactions', 'decline_reason')) {
                $table->string('decline_reason', 50)->nullable()->after('error_message');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('payment_transactions', 'decline_reason')) {
                $table->dropColumn('decline_reason');
            }
        });
    }
};
