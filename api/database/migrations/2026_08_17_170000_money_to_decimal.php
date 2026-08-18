<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Гроші з копійками: суми плану та ціни позицій — decimal(10,2) замість integer,
 * щоб відповідати реальним цінам Сільпо (69.90 ₴, а не 70 ₴).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_plans', function (Blueprint $table) {
            $table->decimal('naive_total', 10, 2)->nullable()->change();
            $table->decimal('optimized_total', 10, 2)->nullable()->change();
            $table->decimal('savings', 10, 2)->nullable()->change();
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->decimal('price', 10, 2)->change();
            $table->decimal('old_price', 10, 2)->nullable()->change();
            $table->decimal('price_total', 10, 2)->change();
        });
    }

    public function down(): void
    {
        Schema::table('meal_plans', function (Blueprint $table) {
            $table->integer('naive_total')->nullable()->change();
            $table->integer('optimized_total')->nullable()->change();
            $table->integer('savings')->nullable()->change();
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->integer('price')->change();
            $table->integer('old_price')->nullable()->change();
            $table->integer('price_total')->change();
        });
    }
};
