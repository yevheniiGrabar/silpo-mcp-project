<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Реальна фасовка товару + залишок на тиждень (щоб бачити недовикористання). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->integer('pack_size')->nullable()->after('old_price'); // g/ml/шт у фасовці
            $table->integer('leftover')->nullable()->after('pack_size');   // залишок після тижня
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropColumn(['pack_size', 'leftover']);
        });
    }
};
