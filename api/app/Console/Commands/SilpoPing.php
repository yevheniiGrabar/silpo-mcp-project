<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Laravel\Mcp\Facades\Mcp;
use Throwable;

/**
 * Доводить живий звʼязок BFF → Silpo MCP: кличе silpo_list_branches
 * і пише JSON-RPC у канал `silpo-mcp`. Потрібен SILPO_DEMO_TOKEN у .env.
 */
class SilpoPing extends Command
{
    protected $signature = 'silpo:ping {tool=silpo_list_branches} {--args=} ';

    protected $description = 'Ping Silpo MCP: list tools + call a tool (default silpo_list_branches)';

    public function handle(): int
    {
        if (! config('services.silpo.demo_token')) {
            $this->warn('SILPO_DEMO_TOKEN не заданий у .env — виклик буде без авторизації.');
        }

        $client = Mcp::client('silpo');
        $tool = (string) $this->argument('tool');
        $args = $this->option('args') ? (array) json_decode((string) $this->option('args'), true) : [];

        try {
            // 1) Список tools (доказ, що сервер відповідає + бачимо silpo_* префікс)
            $tools = $client->tools();
            $this->info(sprintf('MCP підключено: %d tools.', $tools->count()));
            $this->line($tools->take(8)->map(fn ($t) => '· '.$t->name)->implode(PHP_EOL));

            // 2) Виклик конкретного tool
            Log::channel('silpo-mcp')->info('CALL', ['tool' => $tool, 'args' => $args]);
            $result = $client->callTool($tool, $args);
            Log::channel('silpo-mcp')->info('RESULT', [
                'tool' => $tool,
                'isError' => $result->isError,
                'text' => mb_substr($result->text(), 0, 4000),
            ]);

            if ($result->isError) {
                $this->error("Tool {$tool} повернув помилку:");
                $this->line($result->text());

                return self::FAILURE;
            }

            $this->info("Відповідь {$tool} (обрізано):");
            $this->line(mb_substr($result->text(), 0, 1500));

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::channel('silpo-mcp')->error('EXCEPTION', ['tool' => $tool, 'error' => $e->getMessage()]);
            $this->error('MCP помилка: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
