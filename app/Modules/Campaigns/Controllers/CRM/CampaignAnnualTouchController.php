<?php

namespace App\Modules\Campaigns\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Campaigns\Models\CampaignTouchDate;
use App\Modules\Campaigns\Models\CampaignTouchProgram;
use App\Modules\Campaigns\Actions\ProcessDueCampaignTouchDatesAction;
use App\Modules\Campaigns\Models\CampaignTouchVariant;
use App\Modules\Campaigns\Requests\CreateCampaignAnnualTouchMessageTemplateRequest;
use App\Modules\Campaigns\Services\CampaignAnnualTouchAudienceService;
use App\Modules\Messaging\Actions\CreateReusableMessageTemplateAction;
use App\Modules\Messaging\Data\ReusableMessageTemplateAuthoringContext;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Services\MessageTemplateAuthoringFieldPresenter;
use App\Modules\Messaging\Services\ReusableMessageTemplateCatalog;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use App\Modules\Core\Models\Contact;
use App\Support\ModuleFacts\Data\ModuleFactDefinition;
use App\Support\ModuleFacts\Enums\ModuleFactCapability;
use App\Support\ModuleFacts\Enums\ModuleFactType;
use App\Support\ModuleFacts\ModuleFactRegistry;

class CampaignAnnualTouchController extends Controller
{
    public function __construct(
        private readonly ReusableMessageTemplateCatalog $reusableTemplates,
        private readonly MessageTemplateAuthoringFieldPresenter $authoringFields,
        private readonly CampaignAnnualTouchAudienceService $annualTouchAudience,
        private readonly ModuleFactRegistry $moduleFacts,
    ) {}

    public function index(Request $request): View
    {
        $programs = CampaignTouchProgram::query()
            ->with('touchDates.variants.messageTemplatePreset')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $editingProgram = null;
        $editingProgramId = $request->integer('edit');

        if ($editingProgramId > 0) {
            $editingProgram = $programs->firstWhere('id', $editingProgramId);
        }

        $templates = $this->annualTouchTemplatesForIndex($editingProgram)
            ->groupBy('channel');

        return view('crm.campaigns.annual-touches.index', [
            'programs' => $programs,
            'editingProgram' => $editingProgram,
            'audience' => $this->annualTouchAudience->forProgram($editingProgram),
            'programAudienceSummaries' => $programs
                ->mapWithKeys(fn (CampaignTouchProgram $program): array => [
                    (int) $program->getKey() => $this->annualTouchAudience->summaryForProgram($program),
                ]),
            'emailTemplates' => $templates->get('email', collect())->values(),
            'smsTemplates' => $templates->get('sms', collect())->values(),
            'annualTouchAvailableFields' => $this->authoringFields->groupsForContext(
                ProcessDueCampaignTouchDatesAction::DISPATCH_KEY,
            ),
            'annualDateSources' => collect($this->annualDateFacts())
                ->map(fn (ModuleFactDefinition $fact): array => [
                    'key' => $fact->key,
                    'label' => $fact->label,
                    'description' => $fact->description,
                    'owner' => $fact->owner,
                ])
                ->values()
                ->all(),
            'annualDateSourceKeys' => $this->moduleFacts->acceptedKeys(
                Contact::class,
                ModuleFactType::Date,
                ModuleFactCapability::Annualizable,
            ),
        ]);
    }

    public function storeMessageTemplate(
        CreateCampaignAnnualTouchMessageTemplateRequest $request,
        CreateReusableMessageTemplateAction $createReusableMessageTemplate,
    ): JsonResponse {
        try {
            $preset = $createReusableMessageTemplate->handle(
                name: $request->templateName(),
                channel: $request->channel(),
                payload: $request->payload(),
                context: $this->annualTouchAuthoringContext(
                    channel: $request->channel(),
                    payloadClass: $request->payloadClass(),
                ),
                createdBy: $request->user(),
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'message_template' => $exception->getMessage(),
            ]);
        }

        return response()->json([
            'id' => (int) $preset->getKey(),
            'name' => (string) $preset->name,
            'channel' => (string) $preset->channel,
        ], 201);
    }

    public function previewAudience(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'program_id' => ['nullable', 'integer', 'min:1'],
            'audience_mode' => ['required', Rule::in(CampaignAnnualTouchAudienceService::MODES)],
            'audience_criteria' => ['nullable', 'array'],
            'audience_criteria.*' => ['nullable', 'array'],
            'audience_criteria.*.*' => ['string', 'max:191'],
            'audience_contact_ids' => ['nullable', 'array', 'max:500'],
            'audience_contact_ids.*' => ['integer', 'min:1'],
            'audience_exclude_criteria' => ['nullable', 'array'],
            'audience_exclude_criteria.*' => ['nullable', 'array'],
            'audience_exclude_criteria.*.*' => ['string', 'max:191'],
            'audience_exclude_contact_ids' => ['nullable', 'array', 'max:500'],
            'audience_exclude_contact_ids.*' => ['integer', 'min:1'],
        ]);

        $program = isset($validated['program_id'])
            ? CampaignTouchProgram::query()->find((int) $validated['program_id'])
            : null;

        $filter = $this->annualTouchAudience->normalize(
            input: [
                'mode' => $validated['audience_mode'],
                'criteria' => $validated['audience_criteria'] ?? [],
                'contact_ids' => $validated['audience_contact_ids'] ?? [],
                'exclude_criteria' => $validated['audience_exclude_criteria'] ?? [],
                'exclude_contact_ids' => $validated['audience_exclude_contact_ids'] ?? [],
            ],
            program: $program,
        );

        return response()->json([
            'matching_count' => $this->annualTouchAudience->matchingCountForFilter($filter),
            'summary' => $this->annualTouchAudience->summaryForFilter($filter),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);

        $program = DB::transaction(function () use ($validated): CampaignTouchProgram {
            $program = CampaignTouchProgram::query()->create([
                'key' => $this->newProgramKey(),
                'name' => 'Annual touch-base dates',
                'audience_type' => CampaignTouchProgram::AUDIENCE_FILTER,
                'audience_key' => null,
                'audience_filter' => $validated['audience_filter'],
                'recurrence' => CampaignTouchProgram::RECURRENCE_ANNUAL,
                'repeat_years' => (int) $validated['repeat_years'],
                'starts_on' => $validated['starts_on'] ?? null,
                'is_active' => (bool) ($validated['is_active'] ?? false),
            ]);

            $this->syncTouches($program, $validated['touches']);

            return $program;
        }, 3);

        return redirect()
            ->route('crm.campaigns.annual-touches.index', ['edit' => $program->getKey()])
            ->with('status', 'Recurring annual touch-base dates saved.');
    }

    public function update(
        Request $request,
        CampaignTouchProgram $campaignTouchProgram,
    ): RedirectResponse {
        $validated = $this->validated($request, $campaignTouchProgram);

        DB::transaction(function () use ($campaignTouchProgram, $validated): void {
            $campaignTouchProgram->forceFill([
                'audience_type' => CampaignTouchProgram::AUDIENCE_FILTER,
                'audience_key' => null,
                'audience_filter' => $validated['audience_filter'],
                'recurrence' => CampaignTouchProgram::RECURRENCE_ANNUAL,
                'repeat_years' => (int) $validated['repeat_years'],
                'starts_on' => $validated['starts_on'] ?? null,
                'is_active' => (bool) ($validated['is_active'] ?? false),
            ])->save();

            $this->syncTouches($campaignTouchProgram, $validated['touches']);
        }, 3);

        return redirect()
            ->route('crm.campaigns.annual-touches.index', ['edit' => $campaignTouchProgram->getKey()])
            ->with('status', 'Recurring annual touch-base dates updated.');
    }

    public function destroy(CampaignTouchProgram $campaignTouchProgram): RedirectResponse
    {
        $campaignTouchProgram->forceFill(['is_active' => false])->save();

        return redirect()
            ->route('crm.campaigns.annual-touches.index')
            ->with('status', 'Annual touch-base program turned off. Its history was preserved.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(
        Request $request,
        ?CampaignTouchProgram $program = null,
    ): array {
        $rules = [
            'audience_mode' => ['required', Rule::in(CampaignAnnualTouchAudienceService::MODES)],
            'audience_criteria' => ['nullable', 'array'],
            'audience_criteria.*' => ['nullable', 'array'],
            'audience_criteria.*.*' => ['string', 'max:191'],
            'audience_contact_ids' => ['nullable', 'array', 'max:500'],
            'audience_contact_ids.*' => ['integer', 'min:1'],
            'audience_exclude_criteria' => ['nullable', 'array'],
            'audience_exclude_criteria.*' => ['nullable', 'array'],
            'audience_exclude_criteria.*.*' => ['string', 'max:191'],
            'audience_exclude_contact_ids' => ['nullable', 'array', 'max:500'],
            'audience_exclude_contact_ids.*' => ['integer', 'min:1'],
            'repeat_years' => ['required', 'integer', 'min:1', 'max:50'],
            'starts_on' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
            'touches' => ['required', 'array', 'min:1', 'max:50'],
            'touches.*.id' => ['nullable', 'integer'],
            'touches.*.name' => ['required', 'string', 'max:191'],
            'touches.*.source_type' => ['required', Rule::in([CampaignTouchDate::SOURCE_CONTACT_FIELD, CampaignTouchDate::SOURCE_REGISTERED_DATE, CampaignTouchDate::SOURCE_FIXED_DATE])],
            'touches.*.source_key' => [
                'nullable',
                'string',
                Rule::in(array_keys($this->moduleFacts->acceptedKeys(
                    Contact::class,
                    ModuleFactType::Date,
                    ModuleFactCapability::Annualizable,
                ))),
            ],
            'touches.*.month' => ['nullable', 'integer', 'between:1,12'],
            'touches.*.day' => ['nullable', 'integer', 'between:1,31'],
            'touches.*.send_time' => ['required', 'date_format:H:i'],
            'touches.*.email_template_preset_id' => ['nullable', 'integer'],
            'touches.*.sms_template_preset_id' => ['nullable', 'integer'],
        ];

        $validated = $request->validate($rules);
        $validated['audience_filter'] = $this->annualTouchAudience->normalize(
            input: [
                'mode' => $validated['audience_mode'],
                'criteria' => $validated['audience_criteria'] ?? [],
                'contact_ids' => $validated['audience_contact_ids'] ?? [],
                'exclude_criteria' => $validated['audience_exclude_criteria'] ?? [],
                'exclude_contact_ids' => $validated['audience_exclude_contact_ids'] ?? [],
            ],
            program: $program,
        );

        foreach ($validated['touches'] as $index => $touch) {
            $field = 'touches.'.$index;

            if (in_array(($touch['source_type'] ?? null), [
                CampaignTouchDate::SOURCE_CONTACT_FIELD,
                CampaignTouchDate::SOURCE_REGISTERED_DATE,
            ], true)
                && ! $this->annualDateFact((string) ($touch['source_key'] ?? ''))
            ) {
                throw ValidationException::withMessages([
                    $field.'.source_key' => 'Choose an available annual date source.',
                ]);
            }

            if (($touch['source_type'] ?? null) === CampaignTouchDate::SOURCE_FIXED_DATE) {
                $month = (int) ($touch['month'] ?? 0);
                $day = (int) ($touch['day'] ?? 0);

                try {
                    Carbon::createSafe(2000, $month, $day);
                } catch (\Throwable) {
                    throw ValidationException::withMessages([
                        $field.'.day' => 'Choose a valid annual month and day.',
                    ]);
                }
            }

            $emailId = $this->nullableId($touch['email_template_preset_id'] ?? null);
            $smsId = $this->nullableId($touch['sms_template_preset_id'] ?? null);

            if ($emailId === null && $smsId === null) {
                throw ValidationException::withMessages([
                    $field.'.email_template_preset_id' => 'Choose an email template, an SMS template, or both.',
                ]);
            }

            if ($emailId !== null) {
                $this->assertTemplate($emailId, 'email', $field.'.email_template_preset_id', $program);
            }

            if ($smsId !== null) {
                $this->assertTemplate($smsId, 'sms', $field.'.sms_template_preset_id', $program);
            }
        }

        return $validated;
    }

    /**
     * @param array<int, array<string, mixed>> $touches
     */
    private function syncTouches(CampaignTouchProgram $program, array $touches): void
    {
        $program->loadMissing('touchDates.variants');
        $existingDates = $program->touchDates->keyBy('id');
        $keptDateIds = [];

        foreach (array_values($touches) as $index => $touch) {
            $dateId = $this->nullableId($touch['id'] ?? null);
            $date = $dateId !== null ? $existingDates->get($dateId) : null;

            if (! $date instanceof CampaignTouchDate) {
                $date = new CampaignTouchDate([
                    'campaign_touch_program_id' => $program->getKey(),
                    'key' => $this->newTouchKey($program, (string) $touch['name'], $index),
                ]);
            }

            $usesRegisteredDate = in_array($touch['source_type'], [
                CampaignTouchDate::SOURCE_CONTACT_FIELD,
                CampaignTouchDate::SOURCE_REGISTERED_DATE,
            ], true);

            $date->forceFill([
                'campaign_touch_program_id' => $program->getKey(),
                'name' => trim((string) $touch['name']),
                'source_type' => $usesRegisteredDate
                    ? CampaignTouchDate::SOURCE_REGISTERED_DATE
                    : CampaignTouchDate::SOURCE_FIXED_DATE,
                'source_key' => $usesRegisteredDate
                    ? $this->moduleFacts->canonicalKey((string) $touch['source_key'])
                    : null,
                'month' => $usesRegisteredDate ? null : (int) $touch['month'],
                'day' => $usesRegisteredDate ? null : (int) $touch['day'],
                'offset_days' => 0,
                'send_time' => $touch['send_time'].':00',
                'sort_order' => ($index + 1) * 10,
                'is_active' => true,
            ])->save();

            $keptDateIds[] = (int) $date->getKey();
            $this->syncVariant($date, 'email', $touch['email_template_preset_id'] ?? null, 10, $program);
            $this->syncVariant($date, 'sms', $touch['sms_template_preset_id'] ?? null, 20, $program);
        }

        $program->touchDates()
            ->whereNotIn('id', $keptDateIds)
            ->update(['is_active' => false]);

        CampaignTouchVariant::query()
            ->whereHas('touchDate', fn ($query) => $query
                ->where('campaign_touch_program_id', $program->getKey())
                ->where('is_active', false))
            ->update(['is_active' => false]);
    }

    private function syncVariant(
        CampaignTouchDate $date,
        string $channel,
        mixed $presetId,
        int $sortOrder,
        CampaignTouchProgram $program,
    ): void {
        $variant = CampaignTouchVariant::query()
            ->where('campaign_touch_date_id', $date->getKey())
            ->where('key', $channel)
            ->first();
        $presetId = $this->nullableId($presetId);

        if ($presetId === null) {
            $variant?->forceFill(['is_active' => false])->save();

            return;
        }

        $preset = $this->assertTemplate($presetId, $channel, $channel.'_template_preset_id', $program);

        if (! $variant instanceof CampaignTouchVariant) {
            $variant = new CampaignTouchVariant([
                'campaign_touch_date_id' => $date->getKey(),
                'key' => $channel,
            ]);
        }

        $variant->forceFill([
            'campaign_touch_date_id' => $date->getKey(),
            'name' => strtoupper($channel).' annual touch',
            'sort_order' => $sortOrder,
            'channel' => $channel,
            'purpose' => (string) $preset->purpose,
            'scope' => (string) $preset->scope,
            'message_template_preset_id' => $preset->getKey(),
            'is_active' => true,
        ])->save();
    }

    private function assertTemplate(
        int $id,
        string $channel,
        string $field,
        ?CampaignTouchProgram $program = null,
    ): MessageTemplatePreset {
        $preset = $this->reusableTemplates
            ->presets([$channel], 'marketing', 'campaign_annual_touch')
            ->firstWhere('id', $id);

        if ($preset instanceof MessageTemplatePreset) {
            return $preset;
        }

        if ($program instanceof CampaignTouchProgram
            && $this->programAlreadyUsesTemplate($program, $id, $channel)
        ) {
            $legacyPreset = MessageTemplatePreset::query()
                ->active()
                ->whereKey($id)
                ->where('channel', $channel)
                ->where('purpose', 'marketing')
                ->first();

            if ($legacyPreset instanceof MessageTemplatePreset) {
                return $legacyPreset;
            }
        }

        throw ValidationException::withMessages([
            $field => 'Choose a saved reusable marketing '.$channel.' template.',
        ]);
    }

    /** @return \Illuminate\Support\Collection<int, MessageTemplatePreset> */
    private function annualTouchTemplatesForIndex(
        ?CampaignTouchProgram $editingProgram,
    ): \Illuminate\Support\Collection {
        $templates = $this->reusableTemplates->presets(['email', 'sms'], 'marketing', 'campaign_annual_touch');

        if (! $editingProgram instanceof CampaignTouchProgram) {
            return $templates;
        }

        $editingProgram->loadMissing('touchDates.variants');
        $currentIds = $editingProgram->touchDates
            ->flatMap(fn (CampaignTouchDate $date) => $date->variants)
            ->filter(fn (mixed $variant): bool => $variant instanceof CampaignTouchVariant && $variant->is_active)
            ->pluck('message_template_preset_id')
            ->filter(fn (mixed $id): bool => is_numeric($id) && (int) $id > 0)
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($currentIds->isEmpty()) {
            return $templates;
        }

        $grandfathered = MessageTemplatePreset::query()
            ->active()
            ->whereIn('id', $currentIds->all())
            ->where('purpose', 'marketing')
            ->whereIn('channel', ['email', 'sms'])
            ->orderBy('channel')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        return $templates
            ->concat($grandfathered)
            ->unique(fn (MessageTemplatePreset $preset): int => (int) $preset->getKey())
            ->sortBy(fn (MessageTemplatePreset $preset): array => [
                (string) $preset->channel,
                mb_strtolower((string) $preset->name),
                (int) $preset->getKey(),
            ])
            ->values();
    }

    private function programAlreadyUsesTemplate(
        CampaignTouchProgram $program,
        int $presetId,
        string $channel,
    ): bool {
        return CampaignTouchVariant::query()
            ->where('message_template_preset_id', $presetId)
            ->where('channel', $channel)
            ->where('is_active', true)
            ->whereHas('touchDate', fn ($query) => $query
                ->where('campaign_touch_program_id', $program->getKey()))
            ->exists();
    }

    private function annualTouchAuthoringContext(
        string $channel,
        string $payloadClass,
    ): ReusableMessageTemplateAuthoringContext {
        $channelLabel = $channel === 'sms' ? 'SMS' : 'Email';

        return new ReusableMessageTemplateAuthoringContext(
            contextKey: 'campaign_annual_touch',
            purpose: CampaignTouchProgram::MESSAGE_PURPOSE,
            scope: CampaignTouchProgram::MESSAGE_SCOPE,
            dispatchKey: ProcessDueCampaignTouchDatesAction::DISPATCH_KEY,
            messageType: 'campaign_annual_touch',
            payloadClass: $payloadClass,
            queue: 'marketing',
            moduleKey: 'campaigns',
            moduleLabel: 'Campaigns',
            surface: 'campaigns',
            groupKey: 'annual_touches:'.$channel,
            groupLabel: 'Annual Touches — '.$channelLabel,
            usageType: 'campaign_annual_touch',
            selectionContexts: ['campaign_annual_touch'],
            description: 'Reusable CRM-authored standalone annual-touch message.',
        );
    }

    private function nullableId(mixed $value): ?int
    {
        if (! is_numeric($value) || (int) $value < 1) {
            return null;
        }

        return (int) $value;
    }

    /** @return array<int, ModuleFactDefinition> */
    private function annualDateFacts(): array
    {
        return array_values(array_filter(
            $this->moduleFacts->matching(
                Contact::class,
                ModuleFactType::Date,
                ModuleFactCapability::Annualizable,
            ),
            fn (ModuleFactDefinition $definition): bool => $definition->queryResolver !== null,
        ));
    }

    private function annualDateFact(string $key): ?ModuleFactDefinition
    {
        $definition = $this->moduleFacts->find($key);

        return $definition instanceof ModuleFactDefinition
            && $definition->subject === Contact::class
            && $definition->type === ModuleFactType::Date
            && $definition->has(ModuleFactCapability::Annualizable)
            && $definition->queryResolver !== null
                ? $definition
                : null;
    }

    private function newProgramKey(): string
    {
        do {
            $key = 'annual_touch_'.Str::lower(Str::random(12));
        } while (CampaignTouchProgram::query()->where('key', $key)->exists());

        return $key;
    }

    private function newTouchKey(
        CampaignTouchProgram $program,
        string $name,
        int $index,
    ): string {
        $base = Str::slug($name, '_');
        $base = $base !== '' ? $base : 'annual_touch_'.($index + 1);
        $candidate = Str::limit($base, 100, '');
        $suffix = 1;

        while ($program->touchDates()->where('key', $candidate)->exists()) {
            $suffix++;
            $candidate = Str::limit($base, 92, '').'_'.$suffix;
        }

        return $candidate;
    }
}