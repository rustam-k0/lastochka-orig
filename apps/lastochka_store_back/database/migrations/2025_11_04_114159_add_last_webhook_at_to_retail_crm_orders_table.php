<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('retail_crm_orders', function (Blueprint $table) {
            $table->timestamp('last_webhook_at')->nullable()->after('last_sync_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('retail_crm_orders', function (Blueprint $table) {
            $table->dropColumn('last_webhook_at');
        });
    }
};
