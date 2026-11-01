<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_receipts', function (Blueprint $table) {
            if (! Schema::hasColumn('payment_receipts', 'notified_at')) {
                $table->dateTime('notified_at')->nullable()->after('receipt_url');
            }
        });
    }

    public function down(): void
    {
        Schema::table('payment_receipts', function (Blueprint $table) {
            if (Schema::hasColumn('payment_receipts', 'notified_at')) {
                $table->dropColumn('notified_at');
            }
        });
    }
};
