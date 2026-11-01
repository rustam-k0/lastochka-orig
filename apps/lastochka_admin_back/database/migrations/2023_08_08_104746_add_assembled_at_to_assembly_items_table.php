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
        Schema::table('assembly_items', function (Blueprint $table) {
            $table->timestamp('assembled_at')->nullable()->after('explain_reason_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('assembly_items', function (Blueprint $table) {
          $table->dropColumn('assembled_at');
        });
    }
};
