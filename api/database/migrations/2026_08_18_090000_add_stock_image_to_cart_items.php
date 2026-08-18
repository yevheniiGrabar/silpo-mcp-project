<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Наявність + фото + к-сть для замовлення в позиціях кошика:
 * image_url — фото товару; available — є в наявності; order_qty — скільки слати
 * в Сільпо (упаковки для штучних, вага в кг для вагових).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->string('image_url')->nullable()->after('title');
            $table->boolean('available')->default(true)->after('is_private_label');
            $table->decimal('order_qty', 8, 3)->nullable()->after('qty');
        });
    }

    public function down(): void
    {
        Schema::table('cart_items', function (Blueprint $table) {
            $table->dropColumn(['image_url', 'available', 'order_qty']);
        });
    }
};
