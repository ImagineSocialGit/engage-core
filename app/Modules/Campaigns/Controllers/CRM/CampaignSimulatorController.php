<?php

namespace App\Modules\Campaigns\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Campaigns\Models\Campaign;
use App\Modules\Campaigns\Models\CampaignEnrollment;
use App\Modules\Campaigns\Services\CampaignSimulationService;
use App\Modules\Core\Models\Contact;
use App\Support\TestingTools\TestingToolGuard;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class CampaignSimulatorController extends Controller
{
    public function __construct(
        private readonly TestingToolGuard $guard,
        private readonly CampaignSimulationService $simulator,
    ) {}

    public function index(): View
    {
        $this->guard->assertAvailable();

        return view('crm.campaigns.simulator.index', [
            'campaigns' => Campaign::query()
                ->active()
                ->whereNotNull('message_chain_id')
                ->with('messageChain.currentVersion')
                ->orderBy('name')
                ->get(),
            'contacts' => Contact::query()
                ->orderByDesc('id')
                ->limit(250)
                ->get(),
            'runs' => $this->simulator->runs(),
            'timezone' => $this->timezone(),
            'defaultFakeNow' => now($this->timezone())->format('Y-m-d\TH:i'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->guard->assertAvailable();

        $validated = $request->validate([
            'campaign_id' => ['required', 'integer', 'exists:campaigns,id'],
            'contact_id' => ['required', 'integer', 'exists:contacts,id'],
            'fake_now' => ['required', 'date'],
        ]);

        try {
            $simulation = $this->simulator->start(
                campaign: Campaign::query()->findOrFail((int) $validated['campaign_id']),
                contact: Contact::query()->findOrFail((int) $validated['contact_id']),
                fakeNow: (string) $validated['fake_now'],
                user: $request->user(),
            );
        } catch (InvalidArgumentException|RuntimeException $exception) {
            return back()
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('crm.campaigns.simulator.show', $simulation)
            ->with('status', 'Campaign simulation started. No provider message has been sent.');
    }

    public function show(CampaignEnrollment $simulation): View
    {
        $this->guard->assertAvailable();

        try {
            $snapshot = $this->simulator->snapshot($simulation);
        } catch (RuntimeException $exception) {
            abort(404, $exception->getMessage());
        }

        return view('crm.campaigns.simulator.show', [
            'simulation' => $simulation->refresh(),
            'snapshot' => $snapshot,
            'timezone' => $this->timezone(),
            'fakeCurrentLocal' => $this->simulator
                ->currentAt($simulation)
                ->timezone($this->timezone()),
        ]);
    }

    public function process(CampaignEnrollment $simulation): RedirectResponse
    {
        $this->guard->assertAvailable();

        try {
            $this->simulator->process($simulation);
        } catch (RuntimeException $exception) {
            return $this->runRedirect($simulation)
                ->with('error', $exception->getMessage());
        }

        return $this->runRedirect($simulation)
            ->with('status', 'Due Campaign work processed at the current fake time.');
    }

    public function advance(
        Request $request,
        CampaignEnrollment $simulation,
    ): RedirectResponse {
        $this->guard->assertAvailable();

        $validated = $request->validate([
            'mode' => ['required', 'in:next,hour,day,custom'],
            'fake_now' => ['nullable', 'date', 'required_if:mode,custom'],
        ]);

        try {
            $current = $this->simulator->currentAt($simulation);
            $target = match ($validated['mode']) {
                'next' => $this->simulator->nextEventAt($simulation)
                    ?? throw new RuntimeException('This simulation has no next scheduled event.'),
                'hour' => $current->copy()->addHour(),
                'day' => $current->copy()->addDay(),
                'custom' => $this->simulator->parseTime((string) $validated['fake_now']),
            };

            $this->simulator->advanceAndProcess($simulation, $target);
        } catch (RuntimeException $exception) {
            return $this->runRedirect($simulation)
                ->with('error', $exception->getMessage());
        }

        return $this->runRedirect($simulation)
            ->with('status', 'Fake time advanced and due Campaign work processed.');
    }

    public function destroy(CampaignEnrollment $simulation): RedirectResponse
    {
        $this->guard->assertAvailable();

        try {
            $this->simulator->reset($simulation);
        } catch (RuntimeException $exception) {
            return $this->runRedirect($simulation)
                ->with('error', $exception->getMessage());
        }

        return redirect()
            ->route('crm.campaigns.simulator.index')
            ->with('status', 'Campaign simulation reset and simulator-owned runtime records removed.');
    }

    private function runRedirect(CampaignEnrollment $simulation): RedirectResponse
    {
        return redirect()->route('crm.campaigns.simulator.show', $simulation);
    }

    private function timezone(): string
    {
        return (string) config('client.timezone', config('app.timezone', 'UTC'));
    }
}