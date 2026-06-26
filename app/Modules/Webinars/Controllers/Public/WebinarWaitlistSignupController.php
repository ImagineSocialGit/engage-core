<?php

namespace App\Modules\Webinars\Controllers\Public;

use App\Modules\Messaging\Actions\GrantMessageConsentAction;
use App\Modules\Webinars\Actions\GetActiveWebinarSeriesAction;
use App\Modules\Messaging\Enums\MessageChannel;
use App\Modules\Messaging\Enums\MessagePurpose;
use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Contact;
use App\Modules\Webinars\Models\WebinarWaitlistSignup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class WebinarWaitlistSignupController extends Controller
{
    public function __invoke(
        Request $request,
        string $seriesSlug,
        GrantMessageConsentAction $grantMessageConsentAction,
    ): RedirectResponse {
        $series = app(GetActiveWebinarSeriesAction::class)
            ->findBySlug($seriesSlug);

        abort_unless($series, 404);

        $request->merge([
            'marketing_email_consent' => $request->boolean('marketing_email_consent'),
            'marketing_sms_consent' => $request->boolean('marketing_sms_consent'),
        ]);

        $validator = Validator::make($request->all(), [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'marketing_email_consent' => ['required', 'boolean'],
            'marketing_sms_consent' => ['required', 'boolean'],
        ]);

        $validator->after(function ($validator) use ($request): void {
            if (
                ! $request->boolean('marketing_email_consent')
                && ! $request->boolean('marketing_sms_consent')
            ) {
                $validator->errors()->add(
                    'marketing_consent',
                    'Please choose at least one way to be notified when the next webinar is scheduled.'
                );
            }
        });

        $validated = $validator->validate();

        $email = str($validated['email'])->lower()->trim()->toString();

        $phone = filled($validated['phone'] ?? null)
            ? preg_replace('/[^\d+]/', '', $validated['phone'])
            : null;

        $contact = Contact::query()->updateOrCreate(
            ['email' => $email],
            [
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'] ?? null,
                'phone' => $phone,
                'source' => 'webinar_waitlist',
            ],
        );

        WebinarWaitlistSignup::query()->updateOrCreate(
            [
                'webinar_series_id' => $series->id,
                'contact_id' => $contact->id,
            ],
            [
                'first_name' => $validated['first_name'],
                'last_name' => $validated['last_name'] ?? null,
                'email' => $email,
                'phone' => $phone,
                'source_page' => route('webinar.show', $series->slug),
                'meta' => [
                    'series_slug' => $series->slug,
                    'series_title' => $series->title,
                ],
            ],
        );

        if ($validated['marketing_email_consent']) {
            $grantMessageConsentAction->handle(
                $contact,
                [
                    'channel' => MessageChannel::Email->value,
                    'purpose' => MessagePurpose::Marketing->value,
                    'scope' => 'webinar_waitlist',
                    'consented_at' => now(),
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'source' => 'webinar_waitlist',
                ],
                optInPayload: [
                    'series_slug' => $series->slug,
                    'series_title' => $series->title,
                ],
                resolverContext: [
                    'series_slug' => $series->slug,
                ],
            );
        }

        if ($validated['marketing_sms_consent'] && $phone) {
            $grantMessageConsentAction->handle(
                $contact,
                [
                    'channel' => MessageChannel::Sms->value,
                    'purpose' => MessagePurpose::Marketing->value,
                    'scope' => 'webinar_waitlist',
                    'consented_at' => now(),
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'source' => 'webinar_waitlist',
                ],
                optInPayload: [
                    'series_slug' => $series->slug,
                    'series_title' => $series->title,
                ],
                resolverContext: [
                    'series_slug' => $series->slug,
                ],
            );
        }

        return redirect()
            ->route('webinar.show', $series->slug)
            ->with('success', 'You’re on the list. We’ll let you know when the next webinar is scheduled.');
    }
}