<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meal_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('branch_id')->nullable();
            $table->integer('budget');                 // ₴ на тиждень
            $table->unsignedTinyInteger('people')->default(2);
            $table->string('diet_style')->default('pp');
            $table->enum('mode', ['economy', 'quality'])->default('economy');
            $table->unsignedTinyInteger('budget_flex_pct')->default(0);
            $table->json('appliances')->nullable();
            $table->unsignedSmallInteger('max_cook_minutes')->nullable();
            $table->json('allergies')->nullable();
            $table->enum('status', ['pending', 'generating', 'ready', 'failed'])->default('pending');
            $table->string('currency', 3)->default('UAH');
            $table->json('plan_json')->nullable();     // меню (days/meals/ingredients) від агента
            $table->integer('naive_total')->nullable();
            $table->integer('optimized_total')->nullable();
            $table->integer('savings')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meal_plans');
    }
};
