<?php

namespace Tests\Feature\Messaging;

use App\Modules\Messaging\Actions\PublishMessageTemplateVersionAction;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplateVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class MessageTemplateVersionPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_only_distinct_immutable_versions_and_can_reselect_an_existing_version(): void
    {
        $template = MessageTemplate::query()->create([
            'key' => 'email.transactional.example.confirmation',
            'name' => 'Example Confirmation',
            'channel' => 'email',
            'status' => MessageTemplate::STATUS_ACTIVE,
            'source' => 'test',
        ]);

        $publisher = app(PublishMessageTemplateVersionAction::class);

        $first = $publisher->handle($template, [
            'subject' => 'Welcome {first_name}',
            'body' => 'Thanks for joining.',
            'cta' => [
                'label' => 'Open',
                'url' => 'https://example.test/open',
            ],
        ]);

        $same = $publisher->handle($template, [
            'cta' => [
                'url' => 'https://example.test/open',
                'label' => 'Open',
            ],
            'body' => 'Thanks for joining.',
            'subject' => 'Welcome {first_name}',
        ]);

        $second = $publisher->handle($template, [
            'subject' => 'Updated welcome {first_name}',
            'body' => 'Thanks for joining.',
            'cta' => [
                'label' => 'Open',
                'url' => 'https://example.test/open',
            ],
        ]);

        $reselected = $publisher->handle($template, [
            'subject' => 'Welcome {first_name}',
            'body' => 'Thanks for joining.',
            'cta' => [
                'label' => 'Open',
                'url' => 'https://example.test/open',
            ],
        ]);

        $this->assertSame($first->getKey(), $same->getKey());
        $this->assertSame(1, $first->version);
        $this->assertSame(2, $second->version);
        $this->assertSame($first->getKey(), $reselected->getKey());
        $this->assertSame(2, MessageTemplateVersion::query()->count());
        $this->assertSame($first->getKey(), $template->refresh()->current_version_id);
        $this->assertSame('Welcome {first_name}', $first->subject);
        $this->assertEquals([
            'body' => 'Thanks for joining.',
            'cta' => [
                'label' => 'Open',
                'url' => 'https://example.test/open',
            ],
        ], $first->content);
        $this->assertEquals([
            'subject' => 'Welcome {first_name}',
            'body' => 'Thanks for joining.',
            'cta' => [
                'label' => 'Open',
                'url' => 'https://example.test/open',
            ],
        ], $first->payload());
    }

    public function test_published_versions_cannot_be_updated_or_deleted_through_eloquent(): void
    {
        $template = MessageTemplate::query()->create([
            'key' => 'sms.transactional.example.reminder',
            'name' => 'Example Reminder',
            'channel' => 'sms',
            'status' => MessageTemplate::STATUS_ACTIVE,
        ]);

        $version = app(PublishMessageTemplateVersionAction::class)->handle(
            $template,
            ['message' => 'Your reminder is ready.'],
        );

        try {
            $version->forceFill(['content' => ['message' => 'Changed.']])->save();
            $this->fail('Updating a MessageTemplateVersion should fail.');
        } catch (LogicException $exception) {
            $this->assertSame(
                'MessageTemplateVersion records are immutable.',
                $exception->getMessage(),
            );
        }

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('MessageTemplateVersion records are immutable.');

        $version->delete();
    }
}