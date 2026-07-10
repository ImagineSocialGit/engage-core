<?php

namespace Tests\Feature\AutomationOpportunities;

use App\Support\AutomationOpportunities\Services\AutomationBehaviorFingerprintBuilder;
use InvalidArgumentException;
use Tests\TestCase;

class AutomationBehaviorFingerprintBuilderTest extends TestCase
{
    public function test_it_produces_the_same_fingerprint_for_equivalent_associative_parts(): void
    {
        $builder = app(AutomationBehaviorFingerprintBuilder::class);

        $first = $builder->build([
            'contact_status_key' => 'attempting_contact',
            'task' => [
                'priority' => 'high',
                'title' => 'Call this contact',
            ],
        ]);

        $second = $builder->build([
            'task' => [
                'title' => 'Call this contact',
                'priority' => 'high',
            ],
            'contact_status_key' => 'attempting_contact',
        ]);

        $this->assertSame($first, $second);
    }

    public function test_it_preserves_list_order_as_meaningful_fingerprint_input(): void
    {
        $builder = app(AutomationBehaviorFingerprintBuilder::class);

        $first = $builder->build([
            'steps' => ['call', 'email'],
        ]);

        $second = $builder->build([
            'steps' => ['email', 'call'],
        ]);

        $this->assertNotSame($first, $second);
    }

    public function test_it_handles_unicode_and_slashes_deterministically(): void
    {
        $builder = app(AutomationBehaviorFingerprintBuilder::class);

        $parts = [
            'title' => 'Call José / follow up',
            'path' => 'tasks/manual/contact',
        ];

        $this->assertSame(
            $builder->build($parts),
            $builder->build($parts),
        );
    }

    public function test_it_rejects_empty_fingerprint_parts(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Automation behavior fingerprint parts cannot be empty.'
        );

        app(AutomationBehaviorFingerprintBuilder::class)->build([]);
    }
}
