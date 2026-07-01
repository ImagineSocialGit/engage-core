<?php

namespace App\Modules\Broadcasts\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Broadcasts\Actions\CancelBroadcastAction;
use App\Modules\Broadcasts\Actions\ScheduleBroadcastAction;
use App\Modules\Broadcasts\Models\Broadcast;
use App\Modules\Broadcasts\Models\BroadcastRecipient;
use App\Modules\Broadcasts\Requests\StoreBroadcastRequest;
use App\Modules\Broadcasts\Requests\UpdateBroadcastRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BroadcastController extends Controller
{
    public function index(): View
    {
        $broadcasts = Broadcast::query()
            ->latest()
            ->limit(50)
            ->get();

        return view('crm.broadcasts.index', [
            'title' => 'Broadcasts',
            'heading' => 'Broadcasts',
            'broadcasts' => $broadcasts,
        ]);
    }

    public function store(
        StoreBroadcastRequest $request,
        ScheduleBroadcastAction $scheduleBroadcastAction,
    ): RedirectResponse {
        $broadcast = Broadcast::query()->create($request->broadcastAttributes());

        if ($request->shouldSchedule()) {
            $broadcast = $scheduleBroadcastAction->handle($broadcast);

            return redirect()
                ->route('crm.broadcasts.show', $broadcast)
                ->with('success', 'Broadcast scheduled. Immediate sends use a 5-minute safety buffer.');
        }

        return redirect()
            ->route('crm.broadcasts.show', $broadcast)
            ->with('success', 'Broadcast draft saved.');
    }

    public function show(Broadcast $broadcast): View
    {
        $broadcast->loadCount([
            'recipients',
            'recipients as sent_recipients_count' => fn ($query) => $query->where('status', BroadcastRecipient::STATUS_SENT),
            'recipients as skipped_recipients_count' => fn ($query) => $query->where('status', BroadcastRecipient::STATUS_SKIPPED),
            'recipients as failed_recipients_count' => fn ($query) => $query->where('status', BroadcastRecipient::STATUS_FAILED),
            'recipients as cancelled_recipients_count' => fn ($query) => $query->where('status', BroadcastRecipient::STATUS_CANCELLED),
        ]);

        $recipients = $broadcast->recipients()
            ->with('contact')
            ->orderBy('id')
            ->limit(250)
            ->get();

        $scheduledMessages = $broadcast->scheduledMessages()
            ->latest()
            ->limit(250)
            ->get();

        return view('crm.broadcasts.show', [
            'title' => $broadcast->name,
            'heading' => $broadcast->name,
            'broadcast' => $broadcast,
            'recipients' => $recipients,
            'scheduledMessages' => $scheduledMessages,
        ]);
    }

    public function edit(Broadcast $broadcast): View|RedirectResponse
    {
        if ($broadcast->status !== Broadcast::STATUS_DRAFT) {
            return redirect()
                ->route('crm.broadcasts.show', $broadcast)
                ->with('error', 'Only draft broadcasts can be edited.');
        }

        return view('crm.broadcasts.edit', [
            'title' => 'Edit Broadcast',
            'heading' => 'Edit Broadcast',
            'broadcast' => $broadcast,
        ]);
    }

    public function update(
        UpdateBroadcastRequest $request,
        Broadcast $broadcast,
    ): RedirectResponse {
        if ($broadcast->status !== Broadcast::STATUS_DRAFT) {
            return redirect()
                ->route('crm.broadcasts.show', $broadcast)
                ->with('error', 'Only draft broadcasts can be edited.');
        }

        $broadcast->forceFill($request->broadcastAttributes())->save();

        return redirect()
            ->route('crm.broadcasts.show', $broadcast)
            ->with('success', 'Broadcast draft updated.');
    }

    public function schedule(
        Request $request,
        Broadcast $broadcast,
        ScheduleBroadcastAction $scheduleBroadcastAction,
    ): RedirectResponse {
        if ($broadcast->status !== Broadcast::STATUS_DRAFT) {
            return redirect()
                ->route('crm.broadcasts.show', $broadcast)
                ->with('error', 'Only draft broadcasts can be scheduled.');
        }

        $validated = $request->validate([
            'send_at' => ['nullable', 'date'],
        ]);

        $broadcast->forceFill([
            'send_at' => $validated['send_at'] ?? $broadcast->send_at,
        ])->save();

        $broadcast = $scheduleBroadcastAction->handle($broadcast);

        return redirect()
            ->route('crm.broadcasts.show', $broadcast)
            ->with('success', 'Broadcast scheduled. Immediate sends use a 5-minute safety buffer.');
    }

    public function cancel(
        Broadcast $broadcast,
        CancelBroadcastAction $cancelBroadcastAction,
    ): RedirectResponse {
        if (in_array($broadcast->status, [
            Broadcast::STATUS_COMPLETED,
            Broadcast::STATUS_CANCELLED,
        ], true)) {
            return redirect()
                ->route('crm.broadcasts.show', $broadcast)
                ->with('error', 'This broadcast is already terminal.');
        }

        $broadcast = $cancelBroadcastAction->handle($broadcast);

        return redirect()
            ->route('crm.broadcasts.show', $broadcast)
            ->with('success', 'Broadcast cancelled.');
    }
}