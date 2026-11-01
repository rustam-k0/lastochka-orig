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
        Schema::table('client_delivery_infos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('shop_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('client_delivery_infos', function (Blueprint $table) {
            $table->foreignId('shop_id')->nullable()->constrained();
        });
    }
};
