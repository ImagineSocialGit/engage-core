<?php

namespace Tests\Feature\Broadcasts;

use App\Models\User;
use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Broadcasts\Requests\PreviewBroadcastAudienceRequest;
use App\Modules\Broadcasts\Services\BroadcastAudiencePreviewService;
use App\Modules\Broadcasts\Services\BroadcastRecipientResolver;
use App\Modules\Core\Access\Models\UserAccessProfile;
use App\Modules\Core\Models\Contact;
use App\Modules\Core\Models\ContactTag;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BroadcastAudienceReviewAndExclusionTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_returns_visible_contacts_and_honors_criteria_and_manual_exclusions(): void
    {
        $user = User::factory()->create();
        UserAccessProfile::query()->create([
            'user_id' => $user->getKey(),
            'role_key' => 'member',
            'is_active' => true,
            'capability_overrides' => null,
        ]);

        $kept = Contact::factory()->create(['assigned_user_id' => $user->getKey()]);
        $excludedByTag = Contact::factory()->create(['assigned_user_id' => $user->getKey()]);
        $excludedManually = Contact::factory()->create(['assigned_user_id' => $user->getKey()]);
        $notVisible = Contact::factory()->create();

        foreach ([$kept, $excludedByTag, $excludedManually, $notVisible] as $contact) {
            ContactTag::query()->create([
                'contact_id' => $contact->getKey(),
                'tag' => 'missed_webinar',
            ]);
        }
        ContactTag::query()->create([
            'contact_id' => $excludedByTag->getKey(),
            'tag' => 'do_not_nurture',
        ]);

        $preview = app(BroadcastAudiencePreviewService::class)->preview([
            'type' => 'criteria',
            'criteria' => ['tag' => ['missed_webinar']],
            'exclude_criteria' => ['tag' => ['do_not_nurture']],
            'exclude_contact_ids' => [$excludedManually->getKey()],
        ], $user);

        $this->assertSame(1, $preview['selected_count']);
        $this->assertSame([$kept->getKey()], array_column($preview['contacts'], 'id'));
        $this->assertFalse($preview['contacts_truncated']);
    }

    public function test_saved_broadcast_resolver_never_expands_beyond_creator_contact_visibility(): void
    {
        $user = User::factory()->create();
        UserAccessProfile::query()->create([
            'user_id' => $user->getKey(),
            'role_key' => 'member',
            'is_active' => true,
            'capability_overrides' => null,
        ]);

        $visible = Contact::factory()->create(['assigned_user_id' => $user->getKey()]);
        $hidden = Contact::factory()->create();

        $broadcast = Broadcast::factory()->create([
            'user_id' => $user->getKey(),
            'recipient_filter' => ['type' => 'all'],
        ]);

        $ids = app(BroadcastRecipientResolver::class)
            ->query($broadcast)
            ->pluck('contacts.id')
            ->all();

        $this->assertSame([$visible->getKey()], $ids);
        $this->assertNotContains($hidden->getKey(), $ids);
    }

    public function test_preview_request_persists_shared_exclusion_shape(): void
    {
        $included = Contact::factory()->create();
        $excluded = Contact::factory()->create();

        ContactTag::query()->create([
            'contact_id' => $included->getKey(),
            'tag' => 'missed_webinar',
        ]);
        ContactTag::query()->create([
            'contact_id' => $excluded->getKey(),
            'tag' => 'do_not_nurture',
        ]);

        $request = PreviewBroadcastAudienceRequest::create('/broadcasts/audience-preview', 'POST', [
            'recipient_filter_type' => 'criteria',
            'recipient_criteria' => [
                'tag' => ['missed_webinar'],
            ],
            'exclude_contact_criteria' => [
                'tag' => ['do_not_nurture'],
            ],
            'exclude_contact_ids' => [$excluded->getKey()],
        ]);
        $request->setContainer($this->app);
        $request->setRedirector($this->app->make('redirect'));
        $request->validateResolved();

        $this->assertSame([
            'type' => 'criteria',
            'criteria' => [
                'tag' => ['missed_webinar'],
            ],
            'exclude_criteria' => [
                'tag' => ['do_not_nurture'],
            ],
            'exclude_contact_ids' => [$excluded->getKey()],
        ], $request->recipientFilter());
    }
}