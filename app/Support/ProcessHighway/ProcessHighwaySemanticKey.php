<?php

namespace App\Support\ProcessHighway;

final class ProcessHighwaySemanticKey
{
    public static function campaign(string $campaignKey): string
    {
        return 'campaigns:campaign:'.self::segment($campaignKey);
    }

    public static function campaignState(string $campaignKey, string $state): string
    {
        return self::campaign($campaignKey).':state:'.self::segment($state);
    }

    public static function campaignFamilyState(string $familyKey, string $state): string
    {
        return 'campaigns:family:'.self::segment($familyKey).':state:'.self::segment($state);
    }

    public static function flowRoute(string $routeKey): string
    {
        return 'flow_routes:route:'.self::segment($routeKey);
    }

    public static function flowRoutePoint(string $routeKey, string $pointKey): string
    {
        return self::flowRoute($routeKey).':point:'.self::segment($pointKey);
    }

    public static function status(string $statusKey): string
    {
        return 'workflow:status:'.self::segment($statusKey);
    }

    public static function tag(string $tag, bool $present = true): string
    {
        return 'core:contact_tag:'.($present ? 'present:' : 'absent:').self::segment($tag);
    }

    public static function source(string $source, string $criterion = 'source'): string
    {
        return 'core:'.self::segment($criterion).':'.self::segment($source);
    }

    public static function relationship(string $relationshipKey, ?string $stageKey = null): string
    {
        $key = 'relationships:relationship:'.self::segment($relationshipKey);

        return $stageKey === null || $stageKey === '' || $stageKey === '*'
            ? $key
            : $key.':stage:'.self::segment($stageKey);
    }

    public static function webinarOutcome(string $seriesKey, string $outcome): string
    {
        return 'webinars:series:'.self::segment($seriesKey).':outcome:'.self::segment($outcome);
    }

    public static function automationEvent(string $eventKey): string
    {
        return 'automation:event:'.self::segment($eventKey);
    }

    public static function replyProfile(string $replyProfileKey): string
    {
        return 'inbound_messaging:reply_profile:'.self::segment($replyProfileKey);
    }

    public static function criterion(string $criterionKey, string $value): string
    {
        return match ($criterionKey) {
            'status' => self::status($value),
            'tag' => self::tag($value),
            'source', 'subsource' => self::source($value, $criterionKey),
            'relationship' => self::relationshipValue($value),
            'webinar_outcome' => self::webinarOutcomeValue($value),
            default => 'contacts:criterion:'.self::segment($criterionKey).':'.self::segment($value),
        };
    }

    private static function relationshipValue(string $value): string
    {
        [$relationshipKey, $stageKey] = array_pad(explode(':', $value, 2), 2, null);

        return self::relationship($relationshipKey, $stageKey);
    }

    private static function webinarOutcomeValue(string $value): string
    {
        $separator = strrpos($value, ':');

        if ($separator === false) {
            return 'webinars:outcome:'.self::segment($value);
        }

        return self::webinarOutcome(
            substr($value, 0, $separator),
            substr($value, $separator + 1),
        );
    }

    private static function segment(string $value): string
    {
        return rawurlencode(trim($value));
    }
}