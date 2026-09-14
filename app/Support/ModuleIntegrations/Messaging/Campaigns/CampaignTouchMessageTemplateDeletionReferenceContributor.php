<?php

namespace App\Support\ModuleIntegrations\Messaging\Campaigns;

use App\Modules\Messaging\Contracts\MessageTemplateDeletionReferenceContributor;
use App\Modules\Messaging\Data\MessageTemplateDeletionReference;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\MessageTemplatePreset;
use App\Modules\Messaging\Models\MessageTemplatePresetAssignment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

final class CampaignTouchMessageTemplateDeletionReferenceContributor implements MessageTemplateDeletionReferenceContributor
{
    public function references(
        MessageTemplatePreset $preset,
        ?MessageTemplate $template,
    ): iterable {
        yield from $this->campaignReferences($preset);
        yield from $this->annualTouchReferences($preset);
    }

    /**
     * @return iterable<int, MessageTemplateDeletionReference>
     */
    private function campaignReferences(MessageTemplatePreset $preset): iterable
    {
        if (! $this->hasCampaignTables()) {
            return;
        }

        $assignments = $preset->assignments()
            ->active()
            ->where('surface', 'campaigns')
            ->whereNotNull('campaign_key')
            ->whereNotNull('campaign_step')
            ->whereNotNull('campaign_step_variant_key')
            ->orderBy('id')
            ->get();
        $seen = [];

        foreach ($assignments as $assignment) {
            if (! $assignment instanceof MessageTemplatePresetAssignment) {
                continue;
            }

            $campaign = DB::table('campaigns as campaigns')
                ->join('campaign_steps as steps', 'steps.campaign_id', '=', 'campaigns.id')
                ->join('campaign_step_variants as variants', 'variants.campaign_step_id', '=', 'steps.id')
                ->where('campaigns.key', $assignment->campaign_key)
                ->where('campaigns.status', '!=', 'archived')
                ->where('steps.step_number', $assignment->campaign_step)
                ->where('variants.key', $assignment->campaign_step_variant_key)
                ->where('variants.channel', $assignment->channel)
                ->where('variants.purpose', $assignment->purpose)
                ->where('variants.scope', $assignment->scope)
                ->select([
                    'campaigns.id',
                    'campaigns.key',
                    'campaigns.name',
                ])
                ->first();

            if ($campaign === null) {
                continue;
            }

            $fingerprint = implode(':', [
                (int) $campaign->id,
                (int) $assignment->campaign_step,
                (string) $assignment->campaign_step_variant_key,
                (string) $assignment->channel,
            ]);

            if (isset($seen[$fingerprint])) {
                continue;
            }

            $seen[$fingerprint] = true;
            $campaignName = is_string($campaign->name) && trim($campaign->name) !== ''
                ? trim($campaign->name)
                : Str::headline(str_replace('_', ' ', (string) $campaign->key));
            $variantLabel = strtoupper((string) $assignment->channel);

            yield new MessageTemplateDeletionReference(
                moduleKey: 'campaigns',
                moduleLabel: 'Campaigns',
                contextLabel: $campaignName,
                detail: sprintf(
                    'Step %d %s variant still selects this template.',
                    (int) $assignment->campaign_step,
                    $variantLabel,
                ),
                url: Route::has('crm.campaigns.message-templates.index')
                    ? route('crm.campaigns.message-templates.index', [
                        'campaign' => (string) $campaign->key,
                        'step' => (int) $assignment->campaign_step,
                    ])
                    : null,
            );
        }
    }

    /**
     * @return iterable<int, MessageTemplateDeletionReference>
     */
    private function annualTouchReferences(MessageTemplatePreset $preset): iterable
    {
        if (! Schema::hasTable('campaign_touch_variants')
            || ! Schema::hasColumn('campaign_touch_variants', 'message_template_preset_id')
        ) {
            return;
        }

        $count = DB::table('campaign_touch_variants')
            ->where('message_template_preset_id', $preset->getKey())
            ->count();

        if ($count < 1) {
            return;
        }

        yield new MessageTemplateDeletionReference(
            moduleKey: 'campaigns',
            moduleLabel: 'Campaigns',
            contextLabel: 'Annual touch programs',
            detail: number_format($count).' annual-touch '.($count === 1 ? 'variant still selects' : 'variants still select').' this template.',
            url: Route::has('crm.campaigns.annual-touches.index')
                ? route('crm.campaigns.annual-touches.index')
                : null,
        );
    }

    private function hasCampaignTables(): bool
    {
        foreach ([
            'campaigns' => ['id', 'key', 'name', 'status'],
            'campaign_steps' => ['id', 'campaign_id', 'step_number'],
            'campaign_step_variants' => [
                'campaign_step_id',
                'key',
                'channel',
                'purpose',
                'scope',
            ],
        ] as $table => $columns) {
            if (! Schema::hasTable($table)) {
                return false;
            }

            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    return false;
                }
            }
        }

        return true;
    }
}