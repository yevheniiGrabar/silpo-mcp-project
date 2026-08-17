<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Навчання матчингу на свапах: коли користувач замінює підібраний товар,
 * запам'ятовуємо «інгредієнт → обраний SKU», щоб наступного разу пінити його.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('match_corrections', function (Blueprint $table) {
            $table->id();
            $table->string('ingredient')->index(); // нормалізована назва інгредієнта
            $table->string('sku');
            $table->string('title');
            $table->unsignedInteger('hits')->default(1);
            $table->timestamps();
            $table->unique(['ingredient', 'sku']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_corrections');
    }
};
