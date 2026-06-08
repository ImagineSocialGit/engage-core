<?php

namespace App\Http\Controllers\Public;

use App\Actions\Messaging\GrantMessageConsentAction;
use App\Actions\Webinars\GetActiveWebinarSeriesAction;
use App\Enums\MessageChannel;
use App\Enums\MessagePurpose;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\WebinarWaitlistSignup;
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
            'transactional_email_consent' => $request->boolean('transactional_email_consent'),
            'transactional_sms_consent' => $request->boolean('transactional_sms_consent'),
            'marketing_email_consent' => $request->boolean('marketing_email_consent'),
            'marketing_sms_consent' => $request->boolean('marketing_sms_consent'),
        ]);

        $validator = Validator::make($request->all(), [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'transactional_email_consent' => ['required', 'boolean'],
            'transactional_sms_consent' => ['required', 'boolean'],
            'marketing_email_consent' => ['required', 'boolean'],
            'marketing_sms_consent' => ['required', 'boolean'],
        ]);

        $validator->after(function ($validator) use ($request): void {
            if (
                ! $request->boolean('transactional_email_consent')
                && ! $request->boolean('transactional_sms_consent')
            ) {
                $validator->errors()->add(
                    'transactional_consent',
                    'At least one of Email or SMS transactional messages containing links are required for this webinar.'
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

        if ($validated['transactional_email_consent']) {
            $grantMessageConsentAction->handle($contact, [
                'channel' => MessageChannel::Email->value,
                'purpose' => MessagePurpose::Transactional->value,
                'scope' => 'webinar_waitlist',
                'consented_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'source' => 'webinar_waitlist',
            ]);
        }

        if ($validated['transactional_sms_consent'] && $phone) {
            $grantMessageConsentAction->handle($contact, [
                'channel' => MessageChannel::Sms->value,
                'purpose' => MessagePurpose::Transactional->value,
                'scope' => 'webinar_waitlist',
                'consented_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'source' => 'webinar_waitlist',
            ]);
        }

        if ($validated['marketing_email_consent']) {
            $grantMessageConsentAction->handle($contact, [
                'channel' => MessageChannel::Email->value,
                'purpose' => MessagePurpose::Marketing->value,
                'scope' => 'general',
                'consented_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'source' => 'webinar_waitlist',
            ]);
        }

        if ($validated['marketing_sms_consent'] && $phone) {
            $grantMessageConsentAction->handle($contact, [
                'channel' => MessageChannel::Sms->value,
                'purpose' => MessagePurpose::Marketing->value,
                'scope' => 'general',
                'consented_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'source' => 'webinar_waitlist',
            ]);
        }

        return redirect()
            ->route('webinar.show', $series->slug)
            ->with('success', 'You’re on the list. We’ll let you know when the next webinar is scheduled.');
    }
}