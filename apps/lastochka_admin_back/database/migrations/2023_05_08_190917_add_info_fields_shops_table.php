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
        Schema::table('shops', function (Blueprint $table) {
            $table->string('phone')->nullable()->after('default');
            $table->string('email')->nullable()->after('default');
            $table->integer('slot_time')->default(60)->after('default');
            $table->integer('slot_max_orders')->default(2)->after('default');
            $table->string('longitude')->nullable()->after('default');
            $table->string('latitude')->nullable()->after('default');
            $table->string('address')->nullable()->after('default');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('phone');
            $table->dropColumn('email');
            $table->dropColumn('slot_time');
            $table->dropColumn('slot_max_orders');
            $table->dropColumn('longitude');
            $table->dropColumn('latitude');
            $table->dropColumn('address');
        });
    }
};
