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
    Schema::create('products', function (Blueprint $table) {
      $table->id();
      $table->string('barcode')->nullable();
      $table->string('articul')->nullable();
      $table->string('title',500);
      $table->text('description')->nullable();
      $table->string('meta_title')->nullable();
      $table->text('meta_description')->nullable();
      $table->text('meta_keywords')->nullable();
      $table->foreignId('manufacturer_id')->nullable()->constrained('manufacturers');
      $table->foreignId('country_id')->nullable()->constrained('countries');
      $table->foreignId('brand_id')->nullable()->constrained('brands');
      $table->foreignId('unit_type_id');
      $table->integer('status_id');
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
    Schema::dropIfExists('products');
  }
};
