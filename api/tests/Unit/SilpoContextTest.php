<?php

namespace Tests\Unit;

use App\Services\Silpo\SilpoClient;
use App\Services\Silpo\SilpoContextService;
use PHPUnit\Framework\TestCase;

class SilpoContextTest extends TestCase
{
    private function service(): SilpoContextService
    {
        return new SilpoContextService(new SilpoClient);
    }

    public function test_picks_first_available_slot(): void
    {
        $slot = $this->service()->pickAvailableSlot(['slots' => [
            ['available' => false, 'start' => '09:00', 'end' => '11:00'],
            ['available' => true, 'start' => '13:00', 'end' => '15:00'],
        ]]);

        $this->assertSame('13:00', $slot['start']);
        $this->assertSame('15:00', $slot['end']);
    }

    public function test_handles_nested_days_slots(): void
    {
        $slot = $this->service()->pickAvailableSlot(['results' => [
            ['slots' => [
                ['available' => false, 'timeslotStart' => 'a'],
                ['available' => true, 'timeslotStart' => 'b', 'timeslotEnd' => 'c'],
            ]],
        ]]);

        $this->assertSame('b', $slot['start']);
        $this->assertSame('c', $slot['end']);
    }

    public function test_empty_when_no_available_slot(): void
    {
        $this->assertSame([], $this->service()->pickAvailableSlot(['slots' => [
            ['available' => false, 'start' => '09:00'],
        ]]));
    }
}
