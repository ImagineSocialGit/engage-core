<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Contracts\Contacts\ContactImportTreatmentTarget;
use App\Modules\Core\Data\Contacts\ContactImportField;
use App\Modules\Core\Data\Contacts\ContactImportTreatmentApplication;
use App\Modules\Core\Data\Contacts\ContactImportTreatmentDefinition;
use App\Modules\Core\Support\Contacts\ContactImportRegistry;
use App\Modules\Core\Support\Contacts\ContactImportTreatmentRegistry;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ContactImportTreatmentRegistryTest extends TestCase
{
    public function test_fixed_and_column_treatments_resolve_to_field_overrides(): void
    {
        $registry = $this->registry();

        $fixed = $registry->normalizeSubmitted([
            'test_field' => [
                'mode' => 'fixed',
                'fixed_values' => ['one'],
            ],
        ], ['Source']);

        $this->assertSame(
            ['test_field' => 'one'],
            $registry->resolveRow(['Source' => 'anything'], $fixed)->fieldOverrides,
        );

        $column = $registry->normalizeSubmitted([
            'test_field' => [
                'mode' => 'column',
                'source_column' => 'Source',
                'value_map' => [
                    'mapped' => [
                        'source' => 'Legacy One',
                        'values' => ['two'],
                    ],
                ],
            ],
        ], ['Source']);

        $resolved = $registry->resolveRow(['Source' => 'Legacy One'], $column);
        $this->assertSame(['test_field' => 'two'], $resolved->fieldOverrides);
        $this->assertSame('applied', $resolved->targets['test_field']['state']);

        $unmapped = $registry->resolveRow(['Source' => 'Other'], $column);
        $this->assertSame([], $unmapped->fieldOverrides);
        $this->assertSame('unmapped', $unmapped->targets['test_field']['state']);
    }

    public function test_unknown_treatment_and_invalid_source_column_fail_closed(): void
    {
        $registry = $this->registry();

        try {
            $registry->normalizeSubmitted([
                'unknown' => [
                    'mode' => 'fixed',
                    'fixed_values' => ['one'],
                ],
            ], ['Source']);
            $this->fail('Unknown treatment should have failed.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('treatments', $exception->errors());
        }

        $this->expectException(ValidationException::class);

        $registry->normalizeSubmitted([
            'test_field' => [
                'mode' => 'column',
                'source_column' => 'Missing',
                'value_map' => [
                    'mapped' => [
                        'source' => 'Legacy One',
                        'values' => ['one'],
                    ],
                ],
            ],
        ], ['Source']);
    }

    private function registry(): ContactImportTreatmentRegistry
    {
        $imports = (new ContactImportRegistry)->registerField(
            ContactImportField::make(
                key: 'test_field',
                label: 'Test Field',
            ),
        );

        return (new ContactImportTreatmentRegistry($imports))
            ->registerTarget(TestFieldTreatmentTarget::class);
    }
}

final class TestFieldTreatmentTarget implements ContactImportTreatmentTarget
{
    public function available(): bool
    {
        return true;
    }

    public function definition(): ContactImportTreatmentDefinition
    {
        return new ContactImportTreatmentDefinition(
            key: 'test_field',
            label: 'Test Field',
            section: 'Test',
            options: [
                ['value' => 'one', 'label' => 'One'],
                ['value' => 'two', 'label' => 'Two'],
            ],
        );
    }

    public function normalizeValues(array $values): array
    {
        $value = $values[0] ?? null;

        if (! is_string($value) || ! in_array($value, ['one', 'two'], true)) {
            throw ValidationException::withMessages([
                'treatments.test_field' => 'Invalid test value.',
            ]);
        }

        return [$value];
    }

    public function fieldOverrides(array $values): array
    {
        return ['test_field' => $values[0]];
    }

    public function apply(ContactImportTreatmentApplication $application): void
    {
        // No side effect needed for this registry contract test.
    }
}