<?php

namespace Tests\Unit\Support\Deployment;

use App\Support\Deployment\RedisTransportConfiguration;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RedisTransportConfigurationTest extends TestCase
{
    public function test_staging_and_production_cannot_downgrade_tls(): void
    {
        foreach (['staging', 'production'] as $environment) {
            $options = RedisTransportConfiguration::forEnvironment(
                environment: $environment,
                requestedScheme: 'tcp',
                host: '10.108.48.3',
                caFile: '/etc/engage/tls/database-ca.crt.pem',
            );

            $this->assertSame('tls', $options['scheme']);
            $this->assertSame([
                'stream' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'peer_name' => '10.108.48.3',
                    'cafile' => '/etc/engage/tls/database-ca.crt.pem',
                ],
            ], $options['context']);
        }
    }

    public function test_local_tcp_and_opt_in_tls_have_separate_transport_options(): void
    {
        $tcp = RedisTransportConfiguration::forEnvironment('local', 'tcp', '127.0.0.1', null);
        $this->assertSame(['scheme' => 'tcp', 'context' => null], $tcp);

        $tls = RedisTransportConfiguration::forEnvironment('local', 'tls', 'redis.local', '/tmp/local-ca.pem');
        $this->assertSame('tls', $tls['scheme']);
        $this->assertSame('redis.local', $tls['context']['stream']['peer_name']);
        $this->assertTrue($tls['context']['stream']['verify_peer']);
        $this->assertTrue($tls['context']['stream']['verify_peer_name']);
        $this->assertSame('/tmp/local-ca.pem', $tls['context']['stream']['cafile']);
    }

    public function test_missing_ca_never_disables_certificate_verification(): void
    {
        $options = RedisTransportConfiguration::forEnvironment('production', 'tls', 'redis.example', null);

        $this->assertSame('tls', $options['scheme']);
        $this->assertTrue($options['context']['stream']['verify_peer']);
        $this->assertTrue($options['context']['stream']['verify_peer_name']);
        $this->assertArrayNotHasKey('cafile', $options['context']['stream']);
    }

    public function test_invalid_local_transport_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RedisTransportConfiguration::forEnvironment('local', 'udp', '127.0.0.1', null);
    }

    public function test_empty_host_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RedisTransportConfiguration::forEnvironment('staging', 'tls', ' ', null);
    }
}