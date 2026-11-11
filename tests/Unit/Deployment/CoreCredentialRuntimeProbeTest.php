<?php

namespace Tests\Unit\Deployment;

use App\Support\Deployment\Credentials\CoreCredentialRuntimeBinding;
use App\Support\Deployment\Credentials\CoreCredentialRuntimeContributor;
use App\Support\Deployment\Credentials\CoreCredentialRuntimeProbe;
use Closure;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CoreCredentialRuntimeProbeTest extends TestCase
{
    private const KEY = 'FIXTURE_CALLER_SIGNING_SECRET';
    private const DESTINATION = 'http://webhooks.fixture.test';

    public function test_inventory_does_not_invoke_consumers(): void
    {
        $calls = 0;
        $probe = $this->probe(function () use (&$calls): string {
            $calls++;
            throw new RuntimeException('PRIVATE');
        });
        $report = $probe->inventory($this->plan(), self::DESTINATION);
        self::assertSame(0, $calls);
        self::assertSame('fixture_caller', $report['bindings'][0]['caller_id']);
        self::assertFalse($report['writes_allowed']);
        self::assertArrayNotHasKey('proof', $report);
    }

    public function test_all_callers_are_discovered_and_proven_with_independent_credentials(): void
    {
        $first = str_repeat('A', 64);
        $second = str_repeat('B', 64);
        $secondKey = 'FIXTURE_SECOND_SIGNING_SECRET';
        $probe = new CoreCredentialRuntimeProbe([$this->contributor([
            new CoreCredentialRuntimeBinding('fixture', 'fixture.checkout', 'seo', 'second_caller',
                'http://second.test', $secondKey, static fn (): string => $second),
            $this->binding(static fn (): string => $first),
        ])]);
        $plan = $this->plan();
        $plan['environment_requirements'][] = [
            ...$plan['environment_requirements'][0], 'key' => $secondKey,
        ];
        $inventory = $probe->inventory($plan, self::DESTINATION);
        self::assertCount(2, $inventory['bindings']);
        self::assertEqualsCanonicalizing(['fixture_caller', 'second_caller'], array_column($inventory['bindings'], 'caller_id'));
        $values = [self::KEY => $first, $secondKey => $second];
        $nonce = str_repeat('b', 64);
        $one = $probe->prove($plan, self::DESTINATION, 'fixture.checkout', 'seo', 'fixture_caller', $nonce, $values);
        $two = $probe->prove($plan, self::DESTINATION, 'fixture.checkout', 'seo', 'second_caller', $nonce, $values);
        self::assertNotSame($one['proof'], $two['proof']);
        $canonical = implode("\n", ['engage-internal-runtime-v1', $nonce, 'core', 'fixture-core',
            'local', 'fixture.checkout', 'seo', 'second_caller', 'http://second.test', self::DESTINATION]);
        self::assertSame(hash_hmac('sha256', $canonical, $second), $two['proof']);
    }

    public function test_consumer_proof_is_bound_to_the_entire_runtime_context(): void
    {
        $secret = str_repeat('A', 64);
        $nonce = str_repeat('b', 64);
        $probe = $this->probe(static fn (): string => $secret);
        $report = $this->prove($probe, $secret, $nonce);
        $canonical = implode("\n", [
            'engage-internal-runtime-v1', $nonce, 'core', 'fixture-core', 'local',
            'fixture.checkout', 'seo', 'fixture_caller', 'http://fixture-seo.test', self::DESTINATION,
        ]);
        self::assertSame(hash_hmac('sha256', $canonical, $secret), $report['proof']);
        self::assertNotSame($report['proof'], $this->prove($probe, $secret, str_repeat('c', 64))['proof']);
        self::assertStringNotContainsString($secret, json_encode($report, JSON_THROW_ON_ERROR));
        self::assertFalse($report['credential_equality_verified']);
        self::assertFalse($report['network_verified']);
    }

    public function test_file_presence_cannot_replace_a_runtime_consumer(): void
    {
        $this->expectException(RuntimeException::class);
        $this->prove($this->probe(static fn (): ?string => null), str_repeat('A', 64));
    }

    public function test_stale_loaded_material_fails_without_revealing_either_value(): void
    {
        try {
            $this->prove($this->probe(static fn (): string => str_repeat('B', 64)), str_repeat('A', 64));
            self::fail('A stale consumer must fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('credential_runtime_verification_failed', $exception->getMessage());
        }
    }

    public function test_root_scope_secret_is_rejected_even_if_it_matches_client_scope(): void
    {
        $secret = str_repeat('A', 64);
        $this->expectException(RuntimeException::class);
        $this->probe(static fn (): string => $secret)->prove(
            $this->plan(), self::DESTINATION, 'fixture.checkout', 'seo', 'fixture_caller',
            str_repeat('b', 64), [self::KEY => $secret], [self::KEY => $secret],
        );
    }

    public function test_unknown_caller_cannot_obtain_another_callers_proof(): void
    {
        $secret = str_repeat('A', 64);
        $this->expectException(RuntimeException::class);
        $this->probe(static fn (): string => $secret)->prove(
            $this->plan(), self::DESTINATION, 'fixture.checkout', 'seo', 'different',
            str_repeat('b', 64), [self::KEY => $secret],
        );
    }

    public function test_missing_requirement_or_wrong_ownership_blocks_inventory(): void
    {
        foreach (['owner' => 'another', 'scope' => 'root', 'secret' => false, 'requirement' => 'optional'] as $field => $value) {
            $plan = $this->plan();
            $plan['environment_requirements'][0][$field] = $value;
            try {
                $this->probe(static fn (): ?string => null)->inventory($plan, self::DESTINATION);
                self::fail('An unowned runtime binding must fail.');
            } catch (RuntimeException $exception) {
                self::assertSame('credential_runtime_inventory_failed', $exception->getMessage());
            }
        }
    }

    public function test_duplicate_callers_and_shared_secret_keys_are_rejected(): void
    {
        $binding = $this->binding(static fn (): ?string => null);
        $another = new CoreCredentialRuntimeBinding('fixture', 'fixture.checkout', 'seo', 'another_caller',
            'http://another.test', self::KEY, static fn (): ?string => null);
        foreach ([$binding, $another] as $duplicate) {
            try {
                (new CoreCredentialRuntimeProbe([$this->contributor([$binding, $duplicate])]))->inventory($this->plan(), self::DESTINATION);
                self::fail('Duplicate caller identities and shared keys must fail.');
            } catch (RuntimeException $exception) {
                self::assertSame('credential_runtime_inventory_failed', $exception->getMessage());
            }
        }
    }

    public function test_empty_registry_never_mints_a_proof(): void
    {
        $probe = new CoreCredentialRuntimeProbe([]);
        self::assertSame([], $probe->inventory($this->plan(), self::DESTINATION)['bindings']);
        $this->expectException(RuntimeException::class);
        $this->prove($probe, str_repeat('A', 64));
    }

    public function test_consumer_exceptions_are_redacted(): void
    {
        try {
            $this->prove($this->probe(static function (): string {
                throw new RuntimeException('DO_NOT_EXPOSE_CONSUMER_MATERIAL');
            }), str_repeat('A', 64));
            self::fail('A failing consumer must fail.');
        } catch (RuntimeException $exception) {
            self::assertSame('credential_runtime_verification_failed', $exception->getMessage());
            self::assertNull($exception->getPrevious());
        }
    }

    public function test_malformed_challenge_does_not_invoke_consumer(): void
    {
        $probe = $this->probe(static function (): string {
            self::fail('Consumer must not be called.');
        });
        $this->expectException(RuntimeException::class);
        $this->prove($probe, str_repeat('A', 64), "bad\nchallenge");
    }

    public function test_production_inventory_rejects_plain_http_origins(): void
    {
        $plan = $this->plan();
        $plan['environment'] = 'production';
        $this->expectException(RuntimeException::class);
        $this->probe(static fn (): ?string => null)->inventory($plan, self::DESTINATION);
    }

    private function probe(Closure $consumer): CoreCredentialRuntimeProbe
    {
        return new CoreCredentialRuntimeProbe([$this->contributor([$this->binding($consumer)])]);
    }

    private function binding(Closure $consumer): CoreCredentialRuntimeBinding
    {
        return new CoreCredentialRuntimeBinding('fixture', 'fixture.checkout', 'seo',
            'fixture_caller', 'http://fixture-seo.test', self::KEY, $consumer);
    }

    /** @param list<CoreCredentialRuntimeBinding> $bindings */
    private function contributor(array $bindings): CoreCredentialRuntimeContributor
    {
        return new class($bindings) implements CoreCredentialRuntimeContributor {
            public function __construct(private readonly array $bindings) {}
            public function owner(): string { return 'fixture'; }
            public function runtimeBindings(): iterable { yield from $this->bindings; }
        };
    }

    /** @return array<string, mixed> */
    private function plan(): array
    {
        return [
            'client_key' => 'fixture-core', 'environment' => 'local',
            'covered_owners' => ['fixture'], 'environment_integrity_findings' => [],
            'environment_requirements' => [[
                'key' => self::KEY, 'owner' => 'fixture', 'scope' => 'client',
                'secret' => true, 'requirement' => 'required',
                'target_path' => 'client/fixture-core/.env',
            ]],
        ];
    }

    /** @return array<string, mixed> */
    private function prove(CoreCredentialRuntimeProbe $probe, string $secret, string $nonce = ''): array
    {
        return $probe->prove($this->plan(), self::DESTINATION, 'fixture.checkout', 'seo',
            'fixture_caller', $nonce === '' ? str_repeat('b', 64) : $nonce, [self::KEY => $secret]);
    }
}