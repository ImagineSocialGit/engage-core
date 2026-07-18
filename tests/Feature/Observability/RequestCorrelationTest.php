<?php

namespace Tests\Feature\Observability;

use Tests\TestCase;

class RequestCorrelationTest extends TestCase
{
    public function test_web_responses_include_a_request_id(): void
    {
        $response = $this->get(route('login'));

        $response->assertHeader('X-Request-ID');
        $this->assertNotSame('', (string) $response->headers->get('X-Request-ID'));
    }

    public function test_a_valid_upstream_request_id_is_preserved(): void
    {
        $response = $this
            ->withHeader('X-Request-ID', 'nginx-request-12345678')
            ->get(route('login'));

        $response->assertHeader('X-Request-ID', 'nginx-request-12345678');
    }

    public function test_an_invalid_upstream_request_id_is_replaced(): void
    {
        $response = $this
            ->withHeader('X-Request-ID', "invalid request id\n")
            ->get(route('login'));

        $response->assertHeader('X-Request-ID');
        $this->assertNotSame(
            'invalid request id',
            (string) $response->headers->get('X-Request-ID'),
        );
    }
}
