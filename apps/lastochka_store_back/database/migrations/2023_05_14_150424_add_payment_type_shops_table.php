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
        Schema::table('shops',function (Blueprint $table){
          $table->integer('payment_type_id')->default(\App\Models\Type::SHOP_PAYMENT_ASSEMBLY)->after('phone');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('shops',function (Blueprint $table){
          $table->dropColumn('payment_type_id');
        });
    }
};
