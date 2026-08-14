<?php

namespace Tests\Unit;

use App\Services\Silpo\SilpoClient;
use RuntimeException;
use Tests\TestCase;

class SilpoClientTest extends TestCase
{
    public function test_retries_transient_error_then_succeeds(): void
    {
        $client = new SilpoClient(maxRetries: 2, baseDelayMs: 0);
        $calls = 0;

        $result = $client->attempt(function () use (&$calls) {
            $calls++;
            if ($calls < 3) {
                throw new RuntimeException('The server responded with HTTP 503');
            }

            return 'ok';
        }, 'test');

        $this->assertSame('ok', $result);
        $this->assertSame(3, $calls); // 1 спроба + 2 ретрая
    }

    public function test_does_not_retry_non_transient_error(): void
    {
        $client = new SilpoClient(maxRetries: 3, baseDelayMs: 0);
        $calls = 0;

        try {
            $client->attempt(function () use (&$calls) {
                $calls++;
                throw new RuntimeException('HTTP 401 Authorization required');
            }, 'test');
            $this->fail('Expected exception');
        } catch (RuntimeException) {
            $this->assertSame(1, $calls); // без ретраїв на 401
        }
    }

    public function test_is_transient_classification(): void
    {
        $c = new SilpoClient;
        $this->assertTrue($c->isTransient(new RuntimeException('HTTP 429 Too Many Requests')));
        $this->assertTrue($c->isTransient(new RuntimeException('HTTP 503 bad gateway')));
        $this->assertTrue($c->isTransient(new RuntimeException('cURL connection timed out')));
        $this->assertFalse($c->isTransient(new RuntimeException('HTTP 401 Authorization required')));
        $this->assertFalse($c->isTransient(new RuntimeException('HTTP 400 bad request')));
    }
}
