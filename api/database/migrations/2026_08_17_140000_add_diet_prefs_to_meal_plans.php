<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Розширені вподобання раціону: система харчування (діета), кухні,
 * здорові фільтри — щоб MealPlannerAgent складав меню точно під вибір.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meal_plans', function (Blueprint $table) {
            $table->string('diet_system')->default('omnivore')->after('diet_style'); // omnivore|vegetarian|vegan|pescetarian|keto|paleo
            $table->json('cuisines')->nullable()->after('diet_system');              // м'які вподобання стилю
            $table->json('health_filters')->nullable()->after('cuisines');           // цілі оптимізації складу
        });
    }

    public function down(): void
    {
        Schema::table('meal_plans', function (Blueprint $table) {
            $table->dropColumn(['diet_system', 'cuisines', 'health_filters']);
        });
    }
};
