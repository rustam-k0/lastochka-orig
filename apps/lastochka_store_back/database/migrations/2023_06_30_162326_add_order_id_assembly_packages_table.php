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
        Schema::table('assembly_packages', function (Blueprint $table) {
            $table->foreignId('order_id')->nullable()->after('type_id')->constrained();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('assembly_packages', function (Blueprint $table) {
            $table->dropConstrainedForeignId('order_id');
        });
    }
};
