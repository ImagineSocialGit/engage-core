<?php

namespace Tests\Feature\ModuleFacts;

use App\Modules\Core\Models\Contact;
use App\Support\ModuleFacts\Data\ModuleFactQuery;
use App\Support\ModuleFacts\Enums\ModuleFactCapability;
use App\Support\ModuleFacts\Enums\ModuleFactType;
use App\Support\ModuleFacts\ModuleFactRegistry;
use App\Support\ModuleFacts\Validation\ModuleFactsSetupValidationContributor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ModuleFactRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_exposes_birthday_as_a_typed_annualizable_contact_fact(): void
    {
        $registry = app(ModuleFactRegistry::class);
        $definition = $registry->require('core.contact.birthday');

        $this->assertSame('core', $definition->owner);
        $this->assertSame('Contact birthday', $definition->label);
        $this->assertSame(Contact::class, $definition->subject);
        $this->assertSame(ModuleFactType::Date, $definition->type);
        $this->assertTrue($definition->has(ModuleFactCapability::Annualizable));
        $this->assertTrue($definition->has(ModuleFactCapability::Writable));
        $this->assertSame('core.contact.birthday', $registry->canonicalKey('birthday'));

        $due = Contact::factory()->create(['birthday' => '1990-08-31']);
        Contact::factory()->create(['birthday' => '1990-09-01']);

        $query = Contact::query();
        $registry->apply(
            'birthday',
            $query,
            ModuleFactQuery::annualMonthDay(Carbon::parse('2026-08-31')),
        );

        $this->assertSame([$due->getKey()], $query->pluck('contacts.id')->all());
        $this->assertSame('1990-08-31', $registry->resolve('birthday', $due)?->toDateString());
    }

    public function test_registry_can_filter_definitions_by_consumer_capability(): void
    {
        $facts = app(ModuleFactRegistry::class)->matching(
            subject: Contact::class,
            type: ModuleFactType::Date,
            capability: ModuleFactCapability::Annualizable,
        );

        $keys = array_map(fn ($fact): string => $fact->key, $facts);

        $this->assertContains('core.contact.birthday', $keys);
        $this->assertSame(
            [],
            iterator_to_array(
                app(ModuleFactsSetupValidationContributor::class)->findings(),
            ),
        );
    }
}