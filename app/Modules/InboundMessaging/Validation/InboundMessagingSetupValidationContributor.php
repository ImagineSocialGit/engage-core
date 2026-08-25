<?php

namespace App\Modules\InboundMessaging\Validation;

use App\Modules\InboundMessaging\Models\InboundReplyProfile;
use App\Modules\InboundMessaging\Services\ReplyProfiles\ReplyProfileDefinitionNormalizer;
use App\Support\ReplyHandling\Data\ReplyProfileDependency;
use App\Support\ReplyHandling\ReplyProfileDependencyRegistry;
use App\Support\SetupValidation\Contracts\SetupValidationContributor;
use App\Support\SetupValidation\Data\SetupValidationFinding;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class InboundMessagingSetupValidationContributor implements SetupValidationContributor
{
    public function __construct(
        private readonly ReplyProfileDefinitionNormalizer $normalizer,
        private readonly ReplyProfileDependencyRegistry $dependencies,
    ) {}

    public function findings(): iterable
    {
        try {
            $configured = $this->normalizer->configured();
        } catch (Throwable $exception) {
            yield $this->error(
                code: 'inbound_messaging.reply_profiles.config_invalid',
                message: $exception->getMessage(),
                source: 'reply_profiles.config',
            );

            return;
        }

        if (! Schema::hasTable('inbound_reply_profiles')
            || ! Schema::hasTable('inbound_reply_intents')
            || ! Schema::hasTable('inbound_reply_rules')
        ) {
            return;
        }

        $profiles = InboundReplyProfile::query()
            ->with('intents.rules')
            ->get()
            ->keyBy('key');

        foreach ($configured['profiles'] as $profileKey => $definition) {
            if (! $profiles->has($profileKey)) {
                yield $this->error(
                    code: 'inbound_messaging.reply_profiles.not_synced',
                    message: "Configured reply profile [{$profileKey}] is not synchronized. Run [php artisan inbound-messaging:sync-reply-profiles].",
                    source: $configured['source'],
                    path: $configured['source'].'.'.$profileKey,
                    context: ['profile_key' => $profileKey],
                );
            }
        }

        foreach ($profiles as $profile) {
            if (! $profile->is_active) {
                continue;
            }

            $activeIntents = $profile->intents->where('is_active', true);

            if ($activeIntents->isEmpty()
                || $activeIntents->every(fn ($intent): bool =>
                    $intent->rules->where('is_active', true)->isEmpty())
            ) {
                yield $this->error(
                    code: 'inbound_messaging.reply_profiles.no_active_rules',
                    message: "Active reply profile [{$profile->key}] requires at least one active intent with an active rule.",
                    source: 'inbound_reply_profiles',
                    path: "inbound_reply_profiles.{$profile->key}",
                    context: ['profile_key' => $profile->key],
                );
            }
        }

        foreach ($this->dependencies->all() as $dependency) {
            $profile = $profiles->get($dependency->profileKey);

            if (! $profile instanceof InboundReplyProfile || ! $profile->is_active) {
                yield $this->dependencyError(
                    $dependency,
                    "references unavailable reply profile [{$dependency->profileKey}]",
                );

                continue;
            }

            if ($dependency->intentKey === null) {
                continue;
            }

            $intent = $profile->intents->firstWhere('key', $dependency->intentKey);

            if ($intent === null || ! $intent->is_active) {
                yield $this->dependencyError(
                    $dependency,
                    "references unavailable intent [{$dependency->intentKey}] on reply profile [{$dependency->profileKey}]",
                );
            }
        }
    }

    private function dependencyError(
        ReplyProfileDependency $dependency,
        string $problem,
    ): SetupValidationFinding {
        return $this->error(
            code: 'inbound_messaging.reply_profiles.dependency_invalid',
            message: "{$dependency->label} {$problem}.",
            source: 'reply_profile_dependencies',
            path: $dependency->key,
            context: [
                'dependency_key' => $dependency->key,
                'profile_key' => $dependency->profileKey,
                'intent_key' => $dependency->intentKey,
                'module_key' => $dependency->moduleKey,
            ],
        );
    }

    /** @param array<string, mixed> $context */
    private function error(
        string $code,
        string $message,
        string $source,
        ?string $path = null,
        array $context = [],
    ): SetupValidationFinding {
        return new SetupValidationFinding(
            severity: SetupValidationFinding::SEVERITY_ERROR,
            code: $code,
            message: $message,
            source: $source,
            path: $path,
            module: 'inbound_messaging',
            context: $context,
        );
    }
}