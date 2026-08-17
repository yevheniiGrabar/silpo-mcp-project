<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Історія для аналітики: замовлення (purchases) + позиції (purchase_items)
 * + лог їжі (food_logs). Живить сторінку «Аналітика» реальними даними.
 * Суми — у гривнях (int, ₴), щоб збігатися з відображенням у застосунку.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('meal_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->string('store')->default('Сільпо');    // магазин
            $table->string('market', 8)->default('UA');     // ринок (UA/US/EU)
            $table->integer('total')->default(0);           // ₴ сплачено
            $table->integer('saved')->default(0);           // ₴ заощаджено (знижки+оптимізатор)
            $table->unsignedSmallInteger('items_count')->default(0);
            $table->timestamp('purchased_at')->index();
            $table->timestamps();
        });

        Schema::create('purchase_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->constrained()->cascadeOnDelete();
            $table->string('name')->index();
            $table->string('category')->default('Інше')->index();
            $table->unsignedSmallInteger('qty')->default(1);
            $table->integer('price')->default(0);           // ₴ за позицію (фактично сплачено)
            $table->integer('old_price')->nullable();       // ₴ до знижки
            $table->integer('saved')->default(0);           // ₴ заощаджено на позиції
            $table->integer('kcal')->nullable();            // ккал позиції (для патернів)
            $table->timestamps();
        });

        Schema::create('food_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->unsignedSmallInteger('grams')->default(0);
            $table->integer('kcal')->default(0);
            $table->integer('protein')->default(0);
            $table->integer('fat')->default(0);
            $table->integer('carbs')->default(0);
            $table->timestamp('logged_at')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('food_logs');
        Schema::dropIfExists('purchase_items');
        Schema::dropIfExists('purchases');
    }
};
