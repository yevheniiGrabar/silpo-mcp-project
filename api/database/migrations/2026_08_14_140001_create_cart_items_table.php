<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('meal_plan_id')->constrained()->cascadeOnDelete();
            $table->string('ingredient');              // нормалізована назва інгредієнта
            $table->string('silpo_product_id');        // SKU
            $table->string('title');
            $table->unsignedInteger('qty')->default(1);
            $table->integer('price');                  // ціна за од.
            $table->integer('price_total');
            $table->boolean('is_promo')->default(false);
            $table->boolean('is_private_label')->default(false);
            $table->decimal('match_confidence', 3, 2)->default(1);
            $table->json('alt_options')->nullable();   // top-3 кандидати для swap
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_items');
    }
};
