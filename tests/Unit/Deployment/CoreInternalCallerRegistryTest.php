<?php

namespace Tests\Unit\Deployment;

use App\Support\Deployment\Credentials\CoreInternalCallerRegistry;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CoreInternalCallerRegistryTest extends TestCase
{
    public function test_any_client_can_round_trip_its_scoped_registry(): void
    {
        foreach (['fixture-north-core', 'fixture-south-core'] as $client) {
            $registry = CoreInternalCallerRegistry::decode(null, $client, 'local')
                ->withCaller('fixture.checkout', 'seo', 'fixture-seo', 'http://site.test');
            $decoded = CoreInternalCallerRegistry::decode($registry->encode(), $client, 'local');
            self::assertSame($registry->encode(), $decoded->encode());
            $callers = $decoded->forPurpose('fixture.checkout', 'seo');
            self::assertSame('fixture-seo', $callers['fixture-seo']['caller_id']);
            self::assertSame('http://site.test', $callers['fixture-seo']['source_origin']);
        }
    }

    public function test_adding_a_caller_preserves_other_callers_and_purposes(): void
    {
        $one = CoreInternalCallerRegistry::decode(null, 'fixture-core', 'local')
            ->withCaller('fixture.first', 'seo', 'first-seo', 'http://first.test');
        $two = $one->withCaller('fixture.second', 'artist', 'second-artist', 'http://second.test');
        self::assertSame($one->forPurpose('fixture.first', 'seo'), $two->forPurpose('fixture.first', 'seo'));
        self::assertArrayHasKey('second-artist', $two->forPurpose('fixture.second', 'artist'));
    }

    public function test_identical_reconciliation_is_idempotent(): void
    {
        $registry = CoreInternalCallerRegistry::decode(null, 'fixture-core', 'local')
            ->withCaller('fixture.checkout', 'seo', 'fixture-seo', 'http://site.test');
        self::assertSame($registry, $registry->withCaller('fixture.checkout', 'seo', 'fixture-seo', 'http://site.test'));
    }

    public function test_changed_origin_for_the_same_caller_is_not_overwritten(): void
    {
        $registry = CoreInternalCallerRegistry::decode(null, 'fixture-core', 'local')
            ->withCaller('fixture.checkout', 'seo', 'fixture-seo', 'http://site.test');
        $this->expectException(RuntimeException::class);
        $registry->withCaller('fixture.checkout', 'seo', 'fixture-seo', 'http://other.test');
    }

    public function test_registry_cannot_be_copied_to_another_client_or_environment(): void
    {
        $encoded = CoreInternalCallerRegistry::decode(null, 'fixture-core', 'local')
            ->withCaller('fixture.checkout', 'seo', 'fixture-seo', 'http://site.test')->encode();
        foreach ([['other-core', 'local'], ['fixture-core', 'staging']] as [$client, $environment]) {
            try {
                CoreInternalCallerRegistry::decode($encoded, $client, $environment);
                self::fail('A registry belongs to one selected client/environment.');
            } catch (RuntimeException $exception) {
                self::assertSame('internal_caller_registry_invalid', $exception->getMessage());
            }
        }
    }

    public function test_canonical_encoding_is_independent_of_addition_order(): void
    {
        $empty = CoreInternalCallerRegistry::decode(null, 'fixture-core', 'local');
        $first = $empty->withCaller('fixture.checkout', 'seo', 'north-seo', 'http://north.test')
            ->withCaller('fixture.checkout', 'seo', 'south-seo', 'http://south.test');
        $second = $empty->withCaller('fixture.checkout', 'seo', 'south-seo', 'http://south.test')
            ->withCaller('fixture.checkout', 'seo', 'north-seo', 'http://north.test');
        self::assertSame($first->encode(), $second->encode());
    }

    public function test_secret_and_arbitrary_environment_fields_are_rejected(): void
    {
        $data = $this->data();
        $data['callers'][0]['secret'] = 'DO_NOT_EXPOSE';
        try {
            CoreInternalCallerRegistry::decode($this->encoded($data), 'fixture-core', 'local');
            self::fail('Caller metadata cannot contain secrets.');
        } catch (RuntimeException $exception) {
            self::assertSame('internal_caller_registry_invalid', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function test_duplicate_caller_records_are_not_silently_collapsed(): void
    {
        $data = $this->data();
        $data['callers'][] = $data['callers'][0];
        $this->expectException(RuntimeException::class);
        CoreInternalCallerRegistry::decode($this->encoded($data), 'fixture-core', 'local');
    }

    public function test_caller_identity_is_derived_from_the_selected_source_client(): void
    {
        $data = $this->data();
        $data['callers'][0]['caller_id'] = 'another-client';
        $this->expectException(RuntimeException::class);
        CoreInternalCallerRegistry::decode($this->encoded($data), 'fixture-core', 'local');
    }

    public function test_deployed_callers_require_https(): void
    {
        $registry = CoreInternalCallerRegistry::decode(null, 'fixture-core', 'production');
        $this->expectException(RuntimeException::class);
        $registry->withCaller('fixture.checkout', 'seo', 'fixture-seo', 'http://site.example');
    }

    public function test_staging_and_production_keep_the_same_schema_with_separate_scope(): void
    {
        foreach (['staging', 'production'] as $environment) {
            $registry = CoreInternalCallerRegistry::decode(null, 'fixture-core', $environment)
                ->withCaller('fixture.checkout', 'seo', 'fixture-seo', 'https://site.example');
            self::assertSame($environment, CoreInternalCallerRegistry::decode($registry->encode(), 'fixture-core', $environment)->environment);
        }
    }

    public function test_malformed_input_never_becomes_a_caller_configuration(): void
    {
        foreach (["bad\nassignment=1", 'A', str_repeat('A', 65537), 'e30'] as $encoded) {
            try {
                CoreInternalCallerRegistry::decode($encoded, 'fixture-core', 'local');
                self::fail('Malformed registry must be rejected.');
            } catch (RuntimeException $exception) {
                self::assertSame('internal_caller_registry_invalid', $exception->getMessage());
            }
        }
    }

    /** @return array<string, mixed> */
    private function data(): array
    {
        return ['callers' => [['caller_id' => 'fixture-seo', 'purpose' => 'fixture.checkout',
            'source_client' => 'fixture-seo', 'source_origin' => 'http://site.test', 'source_platform' => 'seo']],
            'client_key' => 'fixture-core', 'environment' => 'local', 'schema' => 1];
    }

    /** @param array<string, mixed> $data */
    private function encoded(array $data): string
    {
        return rtrim(strtr(base64_encode(json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }
}