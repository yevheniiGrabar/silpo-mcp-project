<?php

namespace App\Ai\Tools;

/**
 * Request-scoped: тулзи Зоряни записують сюди id плану, який вони змінили/створили,
 * а AssistantController віддає його клієнту, щоб застосунок перечитав план (live-refresh).
 */
class ToolActionContext
{
    public ?int $planId = null;

    public function touch(int $planId): void
    {
        $this->planId = $planId;
    }
}
