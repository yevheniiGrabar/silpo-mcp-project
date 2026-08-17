<?php

namespace App\Services;

use App\Models\FoodLog;
use App\Models\PurchaseItem;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Аналітика для сторінки «Аналітика»: гроші (витрати/категорії/економія),
 * їжа (калорії-тренд), топ-товари та патерн від Зоряни (день тижня → товари).
 * Агрегації робимо у PHP-колекціях — портативно між SQLite та MySQL.
 */
class AnalyticsService
{
    private const GOAL_KCAL = 1900;

    /** Ключові слова «частувань» для детекту патерну (пиво/чіпси/снеки…). */
    private const TREAT_KEYWORDS = [
        'пиво', 'чіпс', 'чипс', 'снек', 'сухарик',
        'енергетик', 'попкорн', 'начос', 'крекер',
    ];

    private const WEEKDAYS_UA = [
        1 => 'понеділок', 2 => 'вівторок', 3 => 'середа', 4 => 'четвер',
        5 => 'п’ятниця', 6 => 'субота', 7 => 'неділя',
    ];

    public function forUser(User $user): array
    {
        return [
            'spend' => $this->spend($user),
            'categories' => $this->categories($user),
            'savings' => $this->savings($user),
            'calories' => $this->calories($user),
            'top_items' => $this->topItems($user),
            'pattern' => $this->pattern($user),
        ];
    }

    /** Витрати: останні 6 календарних місяців + тренд поточного до попереднього. */
    private function spend(User $user): array
    {
        $from = Carbon::now()->subMonths(5)->startOfMonth();
        $rows = $user->purchases()->where('purchased_at', '>=', $from)->get(['total', 'purchased_at']);

        $monthly = [];
        for ($i = 5; $i >= 0; $i--) {
            $m = Carbon::now()->subMonths($i);
            $sum = $rows->filter(fn ($p) => $p->purchased_at->isSameMonth($m))->sum('total');
            $monthly[] = ['label' => $this->monthUa($m), 'amount' => (int) $sum];
        }

        $current = $monthly[5]['amount'];
        $prev = $monthly[4]['amount'];
        $trend = $prev > 0 ? (int) round(($current - $prev) / $prev * 100) : 0;

        return ['current_month' => $current, 'trend_pct' => $trend, 'monthly' => $monthly];
    }

    /** Куди йдуть гроші: топ категорій за поточний місяць. */
    private function categories(User $user): array
    {
        $items = $this->monthItems($user);
        $byCat = $items->groupBy('category')
            ->map(fn (Collection $g) => (int) $g->sum('price'))
            ->sortDesc();
        $total = max(1, $byCat->sum());

        return $byCat->take(6)->map(fn ($amount, $name) => [
            'name' => $name,
            'amount' => $amount,
            'pct' => (int) round($amount / $total * 100),
        ])->values()->all();
    }

    /** Економія: скільки заощаджено цього місяця + топ позицій. */
    private function savings(User $user): array
    {
        $items = $this->monthItems($user);
        $saved = (int) $items->sum('saved');
        $spent = max(1, (int) $items->sum('price'));

        $top = $items->where('saved', '>', 0)
            ->groupBy('name')
            ->map(fn (Collection $g) => (int) $g->sum('saved'))
            ->sortDesc()->take(4)
            ->map(fn ($amount, $name) => ['name' => $name, 'amount' => $amount])
            ->values()->all();

        return [
            'total' => $saved,
            'rate_pct' => (int) round($saved / ($spent + $saved) * 100),
            'top' => $top,
        ];
    }

    /** Калорії: сер. ккал/день, тренд по 4 тижнях, дотримання цілі, стрік. */
    private function calories(User $user): array
    {
        $from = Carbon::now()->subDays(27)->startOfDay();
        $logs = $user->foodLogs()->where('logged_at', '>=', $from)->get(['kcal', 'logged_at']);

        // Сума ккал по днях.
        $byDay = $logs->groupBy(fn (FoodLog $l) => $l->logged_at->toDateString())
            ->map(fn (Collection $g) => (int) $g->sum('kcal'));

        $avg = $byDay->isNotEmpty() ? (int) round($byDay->avg()) : 0;

        // Середнє по тижнях (Т1..Т4, найдавніший → поточний).
        $weekly = [];
        for ($w = 3; $w >= 0; $w--) {
            $start = Carbon::now()->subDays(7 * $w + 6)->startOfDay();
            $end = Carbon::now()->subDays(7 * $w)->endOfDay();
            $days = $byDay->filter(function ($kcal, $date) use ($start, $end) {
                $d = Carbon::parse($date);

                return $d->betweenIncluded($start, $end);
            });
            $weekly[] = $days->isNotEmpty() ? (int) round($days->avg()) : 0;
        }

        // Дотримання цілі (днів у межах ±10% цілі) та поточний стрік логування.
        $within = $byDay->filter(fn ($kcal) => $kcal > 0 && $kcal <= self::GOAL_KCAL * 1.1)->count();

        $streak = 0;
        for ($i = 0; $i < 60; $i++) {
            $date = Carbon::now()->subDays($i)->toDateString();
            if (($byDay[$date] ?? 0) > 0) {
                $streak++;
            } elseif ($i > 0) {
                break; // сьогодні ще може бути порожнім — не рвемо стрік на дні 0
            }
        }

        return [
            'avg_per_day' => $avg,
            'goal' => self::GOAL_KCAL,
            'weekly' => $weekly,
            'adherence' => ['within' => $within, 'total' => $byDay->count()],
            'streak' => $streak,
        ];
    }

    /** Твої постійні: найчастіше куповані товари (останні 3 місяці). */
    private function topItems(User $user): array
    {
        $from = Carbon::now()->subMonths(3)->startOfDay();
        $items = PurchaseItem::query()
            ->whereHas('purchase', fn ($q) => $q->where('user_id', $user->id)->where('purchased_at', '>=', $from))
            ->get(['name']);

        return $items->groupBy('name')
            ->map(fn (Collection $g) => $g->count())
            ->sortDesc()->take(6)
            ->map(fn ($count, $name) => ['name' => $name, 'count' => $count])
            ->values()->all();
    }

    /**
     * Патерн від Зоряни: у який день тижня людина найчастіше бере «частування»
     * (пиво/чіпси/снеки) — щоб запропонувати «як завжди» + легшу заміну.
     */
    private function pattern(User $user): ?array
    {
        // Фільтруємо у PHP (mb_*), бо SQLite LIKE не враховує регістр кирилиці.
        $treats = PurchaseItem::query()
            ->whereHas('purchase', fn ($q) => $q->where('user_id', $user->id))
            ->with('purchase:id,purchased_at')
            ->get()
            ->filter(function (PurchaseItem $i) {
                $name = mb_strtolower($i->name);
                foreach (self::TREAT_KEYWORDS as $k) {
                    if (mb_strpos($name, $k) !== false) {
                        return true;
                    }
                }

                return false;
            });

        if ($treats->isEmpty()) {
            return null;
        }

        // Групуємо за днем тижня, беремо день з найбільшою к-стю замовлень-частувань.
        $byWeekday = $treats->groupBy(fn (PurchaseItem $i) => $i->purchase->purchased_at->dayOfWeekIso);
        $best = $byWeekday->sortByDesc(fn (Collection $g) => $g->pluck('purchase_id')->unique()->count())->keys()->first();

        $group = $byWeekday[$best];
        $purchaseCount = max(1, $group->pluck('purchase_id')->unique()->count());
        if ($purchaseCount < 2) {
            return null; // не патерн, а разова покупка
        }

        $itemNames = $group->groupBy('name')
            ->sortByDesc(fn (Collection $g) => $g->count())
            ->keys()->take(3)->all();

        $estPrice = (int) round($group->sum('price') / $purchaseCount);
        $estKcal = (int) round($group->sum('kcal') / $purchaseCount);

        return [
            'weekday_iso' => (int) $best,
            'weekday' => self::WEEKDAYS_UA[$best] ?? '',
            'items' => $itemNames,
            'occurrences' => $purchaseCount,
            'est_price' => $estPrice,
            'est_kcal' => $estKcal,
        ];
    }

    /** Позиції поточного календарного місяця. */
    private function monthItems(User $user): Collection
    {
        $start = Carbon::now()->startOfMonth();

        return PurchaseItem::query()
            ->whereHas('purchase', fn ($q) => $q->where('user_id', $user->id)->where('purchased_at', '>=', $start))
            ->get(['name', 'category', 'price', 'saved']);
    }

    private function monthUa(Carbon $c): string
    {
        return ['', 'Січ', 'Лют', 'Бер', 'Кві', 'Тра', 'Чер', 'Лип', 'Сер', 'Вер', 'Жов', 'Лис', 'Гру'][$c->month];
    }
}
