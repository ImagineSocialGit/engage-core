<?php

namespace Tests\Feature\Ui;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

class MessageEditorComponentTest extends TestCase
{
    public function test_editor_renders_owner_supplied_plain_field_contracts(): void
    {
        $html = Blade::render(<<<'BLADE'
<x-ui.message-editor
    :subject="[
        'label' => 'Email Subject',
        'name' => 'subject',
        'value' => 'Hello there',
        'required' => true,
    ]"
    :body="[
        'label' => 'Email Body',
        'name' => 'body',
        'value' => 'Longer copy',
        'rows' => 10,
    ]"
    :sms="[
        'label' => 'SMS Message',
        'name' => 'message',
        'value' => 'Short copy',
        'maxlength' => 1600,
    ]"
/>
BLADE);

        $this->assertStringContainsString('data-message-editor', $html);
        $this->assertStringContainsString('data-message-editor-field="subject"', $html);
        $this->assertStringContainsString('name="subject"', $html);
        $this->assertStringContainsString('value="Hello there"', $html);
        $this->assertStringContainsString('name="body"', $html);
        $this->assertStringContainsString('Longer copy', $html);
        $this->assertStringContainsString('name="message"', $html);
        $this->assertStringContainsString('maxlength="1600"', $html);
    }

    public function test_editor_preserves_dynamic_owner_bindings_without_owning_request_shape(): void
    {
        $html = Blade::render(<<<'BLADE'
<x-ui.message-editor
    :subject="[
        'name_bind' => '`steps[${index}][subject]`',
        'model' => 'step.subject',
        'visible_bind' => 'step.channels.includes(\'email\')',
    ]"
    :body="[
        'name_bind' => '`steps[${index}][message]`',
        'model' => 'step.message',
        'required' => true,
    ]"
/>
BLADE);

        $this->assertStringContainsString('x-bind:name="`steps[${index}][subject]`"', $html);
        $this->assertStringContainsString('x-model="step.subject"', $html);
        $this->assertStringContainsString('x-bind:name="`steps[${index}][message]`"', $html);
        $this->assertStringContainsString('x-model="step.message"', $html);
        $this->assertStringContainsString('data-message-editor-field="body"', $html);
    }

    public function test_editor_can_bind_to_alpine_state_without_emitting_form_names(): void
    {
        $html = Blade::render(<<<'BLADE'
<x-ui.message-editor
    :subject="[
        'model' => 'subject',
        'focus' => 'lastField = \'subject\'',
        'data_field' => 'subject',
        'visible_bind' => 'channel === \'email\'',
    ]"
    :sms="[
        'model' => 'message',
        'focus' => 'lastField = \'message\'',
        'data_field' => 'message',
        'visible_bind' => 'channel === \'sms\'',
    ]"
/>
BLADE);

        $this->assertStringContainsString('x-model="subject"', $html);
        $this->assertStringContainsString('data-template-authoring-field="subject"', $html);
        $this->assertStringContainsString('x-model="message"', $html);
        $this->assertStringContainsString('data-template-authoring-field="message"', $html);
        $this->assertStringNotContainsString('name="subject"', $html);
        $this->assertStringNotContainsString('name="message"', $html);
    }
}