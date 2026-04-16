<?php

namespace App\Http\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Http\Requests\CRM\StoreLeadTaskRequest;
use App\Models\Lead;
use App\Models\Task;

class LeadTaskController extends Controller
{
    public function store(StoreLeadTaskRequest $request, Lead $lead)
    {
        $lead->tasks()->create([
            'title' => $request->validated()['title'],
            'due_at' => $request->validated()['due_at'] ?? null,
            'status' => 'open',
        ]);

        return redirect()->back();
    }

    public function complete(Lead $lead, Task $task)
    {
        abort_unless($task->lead_id === $lead->id, 404);

        $task->update([
            'status' => 'completed',
            'completed_at' => now(),
        ]);

        $lead->update([
            'last_contacted_at' => now(),
        ]);

        return redirect()->back();
    }

    public function reopen(Lead $lead, Task $task)
    {
        abort_unless($task->lead_id === $lead->id, 404);

        $task->update([
            'status' => 'open',
            'completed_at' => null,
        ]);

        return redirect()->back();
    }
}