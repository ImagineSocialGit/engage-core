<?php

namespace Tests\Feature\Messaging;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ResolvedMessageDispatchSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduled_messages_store_generic_behavior_owner_provenance(): void
    {
        $this->assertTrue(Schema::hasColumns('scheduled_messages', [
            'behavior_owner_type',
            'behavior_owner_id',
        ]));
    }

    public function test_message_template_presets_do_not_store_business_behavior(): void
    {
        $this->assertFalse(Schema::hasColumn('message_template_presets', 'timing'));
        $this->assertFalse(Schema::hasColumn('message_template_presets', 'schedule'));
        $this->assertFalse(Schema::hasColumn('message_template_presets', 'conditions'));
    }
}
