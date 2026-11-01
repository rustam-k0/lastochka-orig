<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                if (Schema::hasColumn('products', 'brand_id')) {
                    $table->dropConstrainedForeignId('brand_id');
                }
                if (Schema::hasColumn('products', 'manufacturer_id')) {
                    $table->dropConstrainedForeignId('manufacturer_id');
                }
                if (Schema::hasColumn('products', 'country_id')) {
                    $table->dropConstrainedForeignId('country_id');
                }
            });
        }

        if (Schema::hasTable('shops') && Schema::hasColumn('shops', 'brand_id')) {
            Schema::table('shops', function (Blueprint $table) {
                $table->dropConstrainedForeignId('brand_id');
            });
        }

        Schema::dropIfExists('shop_brands');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('manufacturers');
        Schema::dropIfExists('countries');
    }

    public function down()
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('icon');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('manufacturers', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('icon');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->boolean('categories_page')->default(0);
            $table->boolean('is_popular')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('shop_brands', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->softDeletes();
            $table->timestamps();
        });

        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                $table->foreignId('manufacturer_id')->nullable()->constrained('manufacturers');
                $table->foreignId('country_id')->nullable()->constrained('countries');
                $table->foreignId('brand_id')->nullable()->constrained('brands');
            });
        }

        if (Schema::hasTable('shops')) {
            Schema::table('shops', function (Blueprint $table) {
                $table->foreignId('brand_id')->nullable()->after('type_id')->constrained('shop_brands');
            });
        }
    }
};
