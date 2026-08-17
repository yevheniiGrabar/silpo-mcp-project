<?php

namespace App\Services\Silpo;

/**
 * Резолв контексту доставки для пошуку/акцій (docs/06 — ОБОВʼЯЗКОВО):
 * branchId → deliveryType → time-slot з available:true. З недоступним слотом
 * silpo_find_products_batch / silpo_get_promotions повертають 0 результатів.
 */
class SilpoContextService
{
    public function __construct(private readonly SilpoClient $silpo) {}

    /** @return array{branchId?:string,deliveryType?:string,timeslotStart?:string,timeslotEnd?:string} */
    public function resolve(?string $branchId): array
    {
        if (! $branchId) {
            return [];
        }

        // Самовивіз — не потребує адреси; має слоти й асортимент (docs/06).
        $deliveryType = 'SelfPickup';

        $slotsRaw = $this->silpo->callData('silpo_get_time_slots', [
            'branchId' => $branchId,
            'deliveryType' => $deliveryType,
        ]);

        $slot = $this->pickAvailableSlot($slotsRaw);

        return array_filter([
            'branchId' => $branchId,
            'deliveryType' => $deliveryType,
            'timeslotStart' => $slot['start'] ?? null,
            'timeslotEnd' => $slot['end'] ?? null,
        ], fn ($v) => $v !== null);
    }

    /**
     * Обрати перший доступний слот. Чиста функція — покрита тестом.
     *
     * @return array{start?:string,end?:string}
     */
    public function pickAvailableSlot(array $raw): array
    {
        foreach ($this->flatten($raw) as $slot) {
            if (($slot['available'] ?? false) === true) {
                return [
                    'start' => $slot['start'] ?? $slot['timeslotStart'] ?? null,
                    'end' => $slot['end'] ?? $slot['timeslotEnd'] ?? null,
                ];
            }
        }

        return [];
    }

    /** Розплющити можливі форми відповіді (days[].slots[] або плаский список). */
    private function flatten(array $raw): array
    {
        $list = $raw['slots'] ?? $raw['timeslots'] ?? $raw['results'] ?? $raw;
        $out = [];
        foreach ($list as $item) {
            if (! is_array($item)) {
                continue;
            }
            if (isset($item['slots']) && is_array($item['slots'])) {
                foreach ($item['slots'] as $s) {
                    $out[] = $s;
                }
            } else {
                $out[] = $item;
            }
        }

        return $out;
    }
}
