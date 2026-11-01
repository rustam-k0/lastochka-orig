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
        Schema::table('product_groups',function (Blueprint $table){
          $table->integer('status_id')
            ->default(\App\Models\Status::CODE_ACTIVE)
            ->after('position');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('product_groups',function (Blueprint $table){
          $table->dropColumn('status_id');
        });
    }
};
