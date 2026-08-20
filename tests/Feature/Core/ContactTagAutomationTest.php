<?php

namespace Tests\Feature\Core;

use App\Modules\Core\Automation\AddContactTagAutomationActionHandler;
use App\Modules\Core\Automation\RemoveContactTagAutomationActionHandler;
use App\Modules\Core\Models\Contact;
use App\Support\AutomationCapabilities\AutomationActionRegistry;
use App\Support\AutomationCapabilities\AutomationCapabilityRegistry;
use App\Support\AutomationCapabilities\AutomationPointDefinitionRegistry;
use App\Support\AutomationCapabilities\Data\AutomationActionContext;
use App\Support\AutomationCapabilities\Data\AutomationActionResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactTagAutomationTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_registers_contact_tag_automation_capabilities_definitions_and_actions(): void
    {
        $capabilities = app(AutomationCapabilityRegistry::class)->definitions();

        $this->assertArrayHasKey('core.add_contact_tag', $capabilities);
        $this->assertArrayHasKey('core.remove_contact_tag', $capabilities);
        $this->assertSame('add_contact_tag', $capabilities['core.add_contact_tag']->pointType);
        $this->assertSame('remove_contact_tag', $capabilities['core.remove_contact_tag']->pointType);

        $definitions = app(AutomationPointDefinitionRegistry::class);
        $this->assertTrue($definitions->has('add_contact_tag'));
        $this->assertTrue($definitions->has('remove_contact_tag'));

        $actions = app(AutomationActionRegistry::class);
        $this->assertTrue($actions->has('core.add_contact_tag'));
        $this->assertTrue($actions->has('core.remove_contact_tag'));
    }

    public function test_add_contact_tag_is_idempotent_and_remove_contact_tag_is_idempotent(): void
    {
        $contact = Contact::factory()->create();
        $context = new AutomationActionContext(
            input: ['tag' => 'webinar:attended'],
            models: ['current_contact' => $contact],
        );

        $first = app(AddContactTagAutomationActionHandler::class)->handle($context);
        $second = app(AddContactTagAutomationActionHandler::class)->handle($context);

        $this->assertSame(AutomationActionResult::STATUS_COMPLETED, $first->status);
        $this->assertSame('contact_tag_added', $first->reason);
        $this->assertSame(AutomationActionResult::STATUS_COMPLETED, $second->status);
        $this->assertSame('contact_tag_already_present', $second->reason);
        $this->assertDatabaseCount('contact_tags', 1);
        $this->assertDatabaseHas('contact_tags', [
            'contact_id' => $contact->getKey(),
            'tag' => 'webinar:attended',
        ]);

        $removed = app(RemoveContactTagAutomationActionHandler::class)->handle($context);
        $removedAgain = app(RemoveContactTagAutomationActionHandler::class)->handle($context);

        $this->assertSame(AutomationActionResult::STATUS_COMPLETED, $removed->status);
        $this->assertSame('contact_tag_removed', $removed->reason);
        $this->assertSame(AutomationActionResult::STATUS_COMPLETED, $removedAgain->status);
        $this->assertSame('contact_tag_already_absent', $removedAgain->reason);
        $this->assertDatabaseMissing('contact_tags', [
            'contact_id' => $contact->getKey(),
            'tag' => 'webinar:attended',
        ]);
    }
}