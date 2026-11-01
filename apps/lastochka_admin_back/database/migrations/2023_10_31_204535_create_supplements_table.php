<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
  /**
   * Run the migrations.
   *
   * @return void
   */
  public function up()
  {
    Schema::create('supplements', function (Blueprint $table) {
      $table->id();
      $table->string('title');
      $table->foreignId('product_id');
      $table->enum('type', ['one', 'many']);
      $table->boolean('is_required')->default(0);
      $table->softDeletes();
      $table->timestamps();
    });
  }

  /**
   * Reverse the migrations.
   *
   * @return void
   */
  public function down()
  {
    Schema::dropIfExists('supplements');
  }
};
