<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
  /**
   * Описание атрибута — контент INFO-модалки в карточке товара
   * (экран «Характеристики», ELH-117).
   */
  public function up(): void
  {
    if (Schema::hasColumn('attributes', 'description')) {
      return;
    }

    Schema::table('attributes', function (Blueprint $table) {
      $table->text('description')->nullable()->after('code');
    });
  }

  public function down(): void
  {
    if (!Schema::hasColumn('attributes', 'description')) {
      return;
    }

    Schema::table('attributes', function (Blueprint $table) {
      $table->dropColumn('description');
    });
  }
};
