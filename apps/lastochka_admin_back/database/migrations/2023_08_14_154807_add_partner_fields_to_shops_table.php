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
            $table->float('commission')->nullable()->after('slot_time');
            $table->float('deposit')->nullable()->after('commission');
            $table->integer('type_id')->nullable()->after('commission')->default(\App\Models\Type::SHOP_TYPE_STORE);
            $table->text('description')->nullable()->after('type_id');
            $table->foreignId('brand_id')->nullable()->after('type_id')->constrained('shop_brands');
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
          $table->dropColumn('commission');
          $table->dropColumn('type_id');
          $table->dropColumn('description');
          $table->dropColumn('deposit');
          $table->dropConstrainedForeignId('brand_id');
        });
    }
};
