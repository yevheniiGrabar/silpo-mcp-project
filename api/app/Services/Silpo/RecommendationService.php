<?php

namespace App\Services\Silpo;

/**
 * Дані для головного екрана: сьогоднішні акції + «патерн дня тижня»
 * (частокуповані саме в цей день позиції з історії замовлень).
 */
class RecommendationService
{
    public function __construct(
        private readonly SilpoClient $silpo,
        private readonly SilpoContextService $context,
    ) {}

    public function home(?string $branchId, int $weekday): array
    {
        $ctx = $this->context->resolve($branchId);

        $promotions = $ctx
            ? ($this->silpo->call('silpo_get_promotions', $ctx)->structuredContent ?? [])
            : [];

        $offline = $this->silpo->call('silpo_get_my_offline_orders')->structuredContent ?? [];
        $online = $this->silpo->call('silpo_get_my_online_orders')->structuredContent ?? [];

        return [
            'weekday' => $weekday,
            'promotions' => $promotions['collections'] ?? $promotions['promotions'] ?? $promotions,
            'pattern' => $this->weekdayPattern(array_merge(
                $this->flattenOrders($offline),
                $this->flattenOrders($online),
            ), $weekday),
        ];
    }

    /**
     * Чиста функція: топ позицій, куплених саме у цей день тижня.
     *
     * @param  array<int, array{weekday:int, items:string[]}>  $orders
     * @return array{weekday:int, items:array<int, array{name:string, count:int}>}
     */
    public function weekdayPattern(array $orders, int $weekday, int $topN = 5): array
    {
        $counts = [];
        foreach ($orders as $order) {
            if (($order['weekday'] ?? null) !== $weekday) {
                continue;
            }
            foreach ($order['items'] ?? [] as $name) {
                $key = mb_strtolower(trim((string) $name));
                if ($key !== '') {
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                }
            }
        }

        arsort($counts);
        $items = [];
        foreach (array_slice($counts, 0, $topN, true) as $name => $count) {
            $items[] = ['name' => $name, 'count' => $count];
        }

        return ['weekday' => $weekday, 'items' => $items];
    }

    /** @return array<int, array{weekday:int, items:string[]}> */
    private function flattenOrders(array $raw): array
    {
        $orders = [];
        foreach (($raw['orders'] ?? $raw['results'] ?? $raw) as $o) {
            if (! is_array($o)) {
                continue;
            }
            $ts = $o['createdAt'] ?? $o['date'] ?? $o['orderDate'] ?? null;
            $weekday = isset($o['weekday']) ? (int) $o['weekday'] : ($ts ? (int) date('N', strtotime((string) $ts)) : 0);
            $items = [];
            foreach ($o['items'] ?? $o['products'] ?? [] as $it) {
                $items[] = (string) (is_array($it) ? ($it['title'] ?? $it['name'] ?? '') : $it);
            }
            $orders[] = ['weekday' => $weekday, 'items' => array_filter($items)];
        }

        return $orders;
    }
}
