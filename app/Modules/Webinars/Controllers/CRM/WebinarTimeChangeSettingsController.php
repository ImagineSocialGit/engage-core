<?php

namespace App\Modules\Webinars\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Core\Access\Services\UserAccessService;
use App\Modules\Messaging\Actions\PublishMessageTemplateVersionAction;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Services\WebinarTimeChangeTemplates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WebinarTimeChangeSettingsController extends Controller
{
    public function show(Request $request, WebinarSeries $series, UserAccessService $access, WebinarTimeChangeTemplates $templates): View
    {
        $this->authorizeSettings($request, $access);
        $defaults = [
            'email' => [
                'subject' => 'New time for {webinar_title}',
                'body' => "Hi {first_name},\n\nThe time for {webinar_title} has changed from {previous_webinar_time} to {current_webinar_time}.",
            ],
            'sms' => [
                'message' => 'Hi {first_name}! The time for {webinar_title} has changed from {previous_webinar_time} to {current_webinar_time}.',
            ],
        ];
        $published = [];
        $copy = [];

        foreach (['email', 'sms'] as $channel) {
            $version = $templates->version($series, $channel);
            $published[$channel] = $version;
            $copy[$channel] = array_replace($defaults[$channel], $version?->payload() ?? []);
        }

        return view('crm.webinars.time-change-settings', [
            'series' => $series,
            'copy' => $copy,
            'published' => $published,
            'autoSend' => $templates->autoSend($series),
            'selectedChannels' => $templates->configuredChannels($series),
            'availableChannels' => $templates->availableChannels(),
            'tokens' => WebinarTimeChangeTemplates::TOKENS,
        ]);
    }

    public function saveTemplate(
        Request $request,
        WebinarSeries $series,
        UserAccessService $access,
        WebinarTimeChangeTemplates $templates,
        PublishMessageTemplateVersionAction $publish,
    ): RedirectResponse {
        $this->authorizeSettings($request, $access);
        $input = $request->validate([
            'channel' => ['required', Rule::in(['email', 'sms'])],
            'subject' => ['required_if:channel,email', 'nullable', 'string', 'max:255'],
            'body' => ['required_if:channel,email', 'nullable', 'string', 'max:20000'],
            'message' => ['required_if:channel,sms', 'nullable', 'string', 'max:2000'],
        ]);
        $channel = $input['channel'];
        $payload = $channel === 'email'
            ? ['subject' => trim((string) $input['subject']), 'body' => trim((string) $input['body'])]
            : ['message' => trim((string) $input['message'])];
        $templates->validateCopy($channel, $payload);

        DB::transaction(function () use ($series, $channel, $payload, $request, $templates, $publish): void {
            $locked = WebinarSeries::query()->lockForUpdate()->findOrFail($series->getKey());
            $template = MessageTemplate::query()->firstOrCreate(
                ['key' => $templates->key($locked, $channel)],
                [
                    'name' => mb_substr($locked->title, 0, 150).' · Time change · '.strtoupper($channel),
                    'description' => 'Webinar time-change notification for this webinar type.',
                    'channel' => $channel,
                    'status' => MessageTemplate::STATUS_ACTIVE,
                    'source' => 'crm_webinar_time_change',
                    'source_version' => '1',
                    'is_customized' => true,
                    'customized_at' => now(),
                ],
            );

            $publish->handle($template, $payload, $request->user(), resolveComposition: false);
        }, 3);

        return redirect()->route('crm.webinar-series.time-change-settings.show', $series)
            ->with('status', 'Time-change message published. Future resyncs use this version until you publish a new one.');
    }

    public function savePolicy(
        Request $request,
        WebinarSeries $series,
        UserAccessService $access,
        WebinarTimeChangeTemplates $templates,
    ): RedirectResponse {
        $this->authorizeSettings($request, $access);
        $input = $request->validate([
            'auto_send' => ['required', 'boolean'],
            'channels' => ['nullable', 'array', 'max:2'],
            'channels.*' => ['required', 'string', 'distinct', Rule::in(['email', 'sms'])],
        ]);
        $channels = array_values($input['channels'] ?? []);
        $autoSend = (bool) $input['auto_send'];

        DB::transaction(function () use ($series, $templates, $channels, $autoSend): void {
            $locked = WebinarSeries::query()->lockForUpdate()->findOrFail($series->getKey());

            if ($autoSend) {
                $templates->validatedAutoVersions($locked, $channels);
            }

            $meta = is_array($locked->meta) ? $locked->meta : [];
            $meta['time_change_notifications'] = [
                'auto_send' => $autoSend,
                'channels' => $channels,
            ];
            $locked->forceFill(['meta' => $meta])->save();
        }, 3);

        return redirect()->route('crm.webinar-series.time-change-settings.show', $series)
            ->with('status', $autoSend
                ? 'Automatic time-change notices are on for future resyncs.'
                : 'Automatic time-change notices are off. Manual review remains available.');
    }

    private function authorizeSettings(Request $request, UserAccessService $access): void
    {
        abort_unless($access->allows($request->user(), 'contacts.view_all')
            && $access->allows($request->user(), 'contacts.manage'), 403);
    }
}