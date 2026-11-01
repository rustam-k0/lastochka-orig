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
    Schema::create('banners', function (Blueprint $table) {
      $table->id();
      $table->string('title');
      $table->string('link');
      $table->text('description')->nullable();
      $table->integer('position')->default(0);
      $table->boolean('main_page')->default(0);
      $table->boolean('account_page')->default(0);
      $table->boolean('category_page')->default(0);
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
    Schema::dropIfExists('banners');
  }
};
