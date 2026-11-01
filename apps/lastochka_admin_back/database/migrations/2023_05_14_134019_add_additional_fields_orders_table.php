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
        Schema::table('orders', function (Blueprint $table) {
            $table->integer('payment_type_id')->after('warning_time');
            $table->integer('on_change_action_id')->nullable()->after('payment_type_id');
            $table->boolean('paid')->default(0)->after('on_change_action_id');
            $table->string('client_comment')->nullable()->after('paid');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('orders', function (Blueprint $table) {
          $table->dropColumn('payment_type_id');
          $table->dropColumn('on_change_action_id');
          $table->dropColumn('paid');
          $table->dropColumn('client_comment');
        });
    }
};
