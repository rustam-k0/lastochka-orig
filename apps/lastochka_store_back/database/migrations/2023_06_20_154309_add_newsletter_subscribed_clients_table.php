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
        Schema::table('clients',function (Blueprint $table){
          $table->boolean('newsletter_subscribed')->default(0)->after('gender_type_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('clients',function (Blueprint $table){
          $table->dropColumn('newsletter_subscribed');
        });
    }
};
