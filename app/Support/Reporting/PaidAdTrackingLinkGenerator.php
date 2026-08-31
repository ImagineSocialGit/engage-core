<?php

namespace App\Support\Reporting;

use LogicException;

final class PaidAdTrackingLinkGenerator
{
    /**
     * @return array<string, array{
     *     label: string,
     *     short_label: string,
     *     destination_label: string,
     *     parameters_label: string,
     *     parameters: string,
     *     instructions: string,
     *     notes: array<int, string>,
     *     custom_parameters: array<int, array{level: string, key: string, value_hint: string}>
     * }>
     */
    public function platforms(): array
    {
        return [
            'meta' => [
                'label' => 'Facebook + Instagram (Meta Ads)',
                'short_label' => 'Meta',
                'destination_label' => 'Website URL',
                'parameters_label' => 'URL Parameters',
                'parameters' => $this->queryString([
                    'utm_source' => '{{site_source_name}}',
                    'utm_medium' => 'paid_social',
                    'utm_campaign' => '{{campaign.name}}',
                    'utm_content' => '{{ad.name}}',
                    'utm_term' => '{{adset.name}}',
                    $this->externalKey('platform', 'engage_platform') => 'meta',
                    $this->externalKey('campaign_id', 'engage_campaign_id') => '{{campaign.id}}',
                    $this->externalKey('group_id', 'engage_group_id') => '{{adset.id}}',
                    $this->externalKey('creative_id', 'engage_creative_id') => '{{ad.id}}',
                    $this->externalKey('placement', 'engage_placement') => '{{placement}}',
                ]),
                'instructions' => 'Keep the webinar registration URL as the Website URL, then paste the tracking text into Meta Ads Manager’s URL Parameters field.',
                'notes' => [
                    'Do not change anything inside {{double braces}}. Meta fills those values automatically.',
                    'Do not paste the tracking text into the Website URL when Meta provides a separate URL Parameters field.',
                ],
                'custom_parameters' => [],
            ],
            'tiktok' => [
                'label' => 'TikTok Ads',
                'short_label' => 'TikTok',
                'destination_label' => 'Destination URL',
                'parameters_label' => 'URL tracking parameters',
                'parameters' => $this->queryString([
                    'utm_source' => 'tiktok',
                    'utm_medium' => 'paid_social',
                    'utm_campaign' => '__CAMPAIGN_NAME__',
                    'utm_content' => '__CID_NAME__',
                    'utm_term' => '__AID_NAME__',
                    $this->externalKey('platform', 'engage_platform') => 'tiktok',
                    $this->externalKey('campaign_id', 'engage_campaign_id') => '__CAMPAIGN_ID__',
                    $this->externalKey('group_id', 'engage_group_id') => '__AID__',
                    $this->externalKey('creative_id', 'engage_creative_id') => '__CID__',
                    $this->externalKey('placement', 'engage_placement') => '__PLACEMENT__',
                ]),
                'instructions' => 'Keep the webinar registration URL as the Destination URL. Add the tracking text with TikTok’s URL builder/custom-parameter fields, or paste it into the single tracking field when one is provided.',
                'notes' => [
                    'Do not change the values inside __double underscores__. TikTok fills those automatically.',
                    'If Auto-attach UTM is enabled, preview the ad and make sure the same UTM field is not added twice.',
                    'If the TikTok screen does not match these instructions, do not substitute a similar-looking field.',
                ],
                'custom_parameters' => [],
            ],
            'youtube' => [
                'label' => 'YouTube Ads (Google Ads)',
                'short_label' => 'YouTube',
                'destination_label' => 'Final URL',
                'parameters_label' => 'Final URL suffix',
                'parameters' => $this->queryString([
                    'utm_source' => 'youtube',
                    'utm_medium' => 'paid_video',
                    'utm_campaign' => '{_engcamp}',
                    'utm_content' => '{_engad}',
                    'utm_term' => '{_enggroup}',
                    $this->externalKey('platform', 'engage_platform') => 'google_ads',
                    $this->externalKey('campaign_id', 'engage_campaign_id') => '{campaignid}',
                    $this->externalKey('group_id', 'engage_group_id') => '{adgroupid}',
                    $this->externalKey('creative_id', 'engage_creative_id') => '{creative}',
                    $this->externalKey('placement', 'engage_placement') => '{placement}',
                ]),
                'instructions' => 'Keep the webinar registration URL as the Final URL. Create the three readable custom parameters below, then paste the tracking text into Final URL suffix.',
                'notes' => [
                    'Do not put a ? at the beginning of the Final URL suffix.',
                    'Do not change {campaignid}, {adgroupid}, {creative}, or {placement}. Google fills those automatically.',
                    'This setup is specifically for YouTube video advertising, not Google Search, Display, Performance Max, or another Google Ads campaign type.',
                ],
                'custom_parameters' => [
                    [
                        'level' => 'Campaign',
                        'key' => '_engcamp',
                        'value_hint' => 'Campaign name',
                    ],
                    [
                        'level' => 'Ad group',
                        'key' => '_enggroup',
                        'value_hint' => 'Ad group name',
                    ],
                    [
                        'level' => 'Ad',
                        'key' => '_engad',
                        'value_hint' => 'Ad / creative label',
                    ],
                ],
            ],
        ];
    }

    private function externalKey(string $dimension, string $fallback): string
    {
        $configured = config("reporting.attribution.external_keys.{$dimension}", $fallback);
        $key = is_string($configured) ? trim($configured) : '';

        if ($key === '' || preg_match('/^[A-Za-z][A-Za-z0-9_.-]*$/', $key) !== 1) {
            throw new LogicException(
                "Reporting external attribution key [{$dimension}] is invalid for paid ad tracking setup.",
            );
        }

        return $key;
    }

    /**
     * @param array<string, string> $pairs
     */
    private function queryString(array $pairs): string
    {
        return collect($pairs)
            ->map(fn (string $value, string $key): string => $key.'='.$value)
            ->implode('&');
    }
}