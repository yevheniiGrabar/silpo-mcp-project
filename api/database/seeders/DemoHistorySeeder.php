<?php

namespace Database\Seeders;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * Демо-історія для сторінки «Аналітика»: ~12 тижнів покупок + 28 днів
 * харчового щоденника для demo@mealize.app, з навмисним патерном
 * «п’ятниця = пиво + чіпси», щоб Зоряна його виявляла.
 */
class DemoHistorySeeder extends Seeder
{
    /** [назва, категорія, ціна ₴, ккал/порція] */
    private const PRODUCTS = [
        // М'ясо і риба
        ['Куряче філе', "М'ясо і риба", 165, 495],
        ['Фарш свинячий', "М'ясо і риба", 140, 640],
        ['Лосось стейк', "М'ясо і риба", 220, 400],
        ['Яловичина', "М'ясо і риба", 260, 500],
        // Овочі та фрукти
        ['Банани', 'Овочі та фрукти', 46, 320],
        ['Помідори', 'Овочі та фрукти', 58, 90],
        ['Огірки', 'Овочі та фрукти', 52, 60],
        ['Яблука', 'Овочі та фрукти', 40, 260],
        ['Картопля', 'Овочі та фрукти', 32, 320],
        // Молочне і яйця
        ['Молоко 2.5%', 'Молочне і яйця', 42, 600],
        ['Яйця С1', 'Молочне і яйця', 68, 780],
        ['Сир твердий', 'Молочне і яйця', 130, 1600],
        ['Йогурт натуральний', 'Молочне і яйця', 38, 120],
        // Крупи, бакалія
        ['Гречка', 'Крупи, бакалія', 55, 1300],
        ['Вівсянка', 'Крупи, бакалія', 34, 1400],
        ['Рис', 'Крупи, бакалія', 48, 1300],
        ['Олія соняшникова', 'Крупи, бакалія', 82, 3700],
        ['Хліб цільнозерновий', 'Крупи, бакалія', 36, 650],
        // Солодке
        ['Печиво вівсяне', 'Солодке', 44, 450],
        ['Шоколад чорний', 'Солодке', 52, 550],
    ];

    /** «Частування» для патерну п’ятниці. */
    private const TREATS = [
        ['Пиво світле 0.5л', 'Напої', 45, 210],
        ['Чіпси зі смаком сиру', 'Снеки', 52, 510],
        ['Сухарики', 'Снеки', 28, 380],
    ];

    /** [назва, ккал, білки, жири, вуглеводи] на порцію */
    private const MEALS = [
        ['Вівсянка з бананом', 380, 12, 8, 62],
        ['Куряче філе з рисом', 520, 45, 10, 55],
        ['Грецький салат', 240, 8, 18, 12],
        ['Омлет із сиром', 330, 22, 24, 6],
        ['Гречка з овочами', 410, 14, 9, 68],
        ['Йогурт з гранолою', 260, 10, 8, 38],
        ['Борщ', 320, 12, 14, 34],
        ['Риба з картоплею', 480, 34, 16, 44],
        ['Банан', 90, 1, 0, 23],
        ['Кава з молоком', 60, 3, 3, 6],
    ];

    public function run(): void
    {
        $user = User::firstOrCreate(
            ['email' => 'demo@mealize.app'],
            ['name' => 'Demo', 'password' => bcrypt(Str::random(40))],
        );

        // Ідемпотентність: чистимо попередню демо-історію.
        $user->purchases()->delete();
        $user->foodLogs()->delete();

        $this->seedPurchases($user);
        $this->seedFoodLogs($user);

        $this->command?->info("Demo history seeded: {$user->purchases()->count()} purchases, {$user->foodLogs()->count()} food logs.");
    }

    private function seedPurchases(User $user): void
    {
        for ($week = 11; $week >= 0; $week--) {
            $monday = Carbon::now()->startOfWeek()->subWeeks($week);

            // Великий тижневий закуп (субота).
            $this->makePurchase($user, $monday->copy()->addDays(5)->setTime(11, rand(0, 59)),
                $this->pickProducts(rand(8, 12)));

            // Докупівля серед тижня (вівторок/середа).
            $this->makePurchase($user, $monday->copy()->addDays(rand(1, 2))->setTime(18, rand(0, 59)),
                $this->pickProducts(rand(3, 5)));

            // Патерн: п’ятниця — пиво + чіпси (не щотижня, але часто).
            if (rand(1, 10) <= 8) {
                $treats = array_slice(self::TREATS, 0, rand(2, 3));
                $this->makePurchase($user, $monday->copy()->addDays(4)->setTime(19, rand(0, 59)), $treats, isTreat: true);
            }
        }
    }

    /** @param array<int, array{0:string,1:string,2:int,3:int}> $products */
    private function makePurchase(User $user, Carbon $at, array $products, bool $isTreat = false): void
    {
        $rows = [];
        $total = 0;
        $saved = 0;

        foreach ($products as $p) {
            [$name, $cat, $price, $kcal] = $p;
            $qty = $isTreat ? 1 : (rand(1, 10) <= 7 ? 1 : 2);

            // Іноді знижка (частіше у великому закупі).
            $old = null;
            $lineSaved = 0;
            if (! $isTreat && rand(1, 10) <= 3) {
                $old = (int) round($price * (1 + rand(15, 35) / 100));
                $lineSaved = ($old - $price) * $qty;
            }

            $linePrice = $price * $qty;
            $total += $linePrice;
            $saved += $lineSaved;

            $rows[] = [
                'name' => $name, 'category' => $cat, 'qty' => $qty,
                'price' => $linePrice, 'old_price' => $old ? $old * $qty : null,
                'saved' => $lineSaved, 'kcal' => $kcal * $qty,
            ];
        }

        $purchase = $user->purchases()->create([
            'store' => 'Сільпо', 'market' => 'UA',
            'total' => $total, 'saved' => $saved,
            'items_count' => count($rows), 'purchased_at' => $at,
        ]);
        $purchase->items()->createMany($rows);
    }

    /** @return array<int, array{0:string,1:string,2:int,3:int}> */
    private function pickProducts(int $n): array
    {
        return Arr::random(self::PRODUCTS, min($n, count(self::PRODUCTS)));
    }

    private function seedFoodLogs(User $user): void
    {
        for ($d = 27; $d >= 0; $d--) {
            $day = Carbon::now()->subDays($d)->startOfDay();
            $target = rand(1700, 2050);
            $sum = 0;
            $meals = 0;
            $hour = 8;

            // Набираємо 3–6 прийомів їжі, поки не наберемо ~денну норму.
            while ($sum < $target && $meals < 6) {
                [$title, $kcal, $pr, $fat, $carb] = self::MEALS[array_rand(self::MEALS)];
                $user->foodLogs()->create([
                    'title' => $title, 'grams' => rand(120, 320),
                    'kcal' => $kcal, 'protein' => $pr, 'fat' => $fat, 'carbs' => $carb,
                    'logged_at' => $day->copy()->setTime(min($hour, 21), rand(0, 59)),
                ]);
                $sum += $kcal;
                $meals++;
                $hour += 3;
            }
        }
    }
}
