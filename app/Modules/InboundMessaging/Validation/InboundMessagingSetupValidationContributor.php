<?php

namespace App\Modules\InboundMessaging\Validation;

use App\Modules\InboundMessaging\Data\InboundEmailRouteIdentity;
use App\Modules\InboundMessaging\Models\InboundEmailRoute;
use App\Modules\InboundMessaging\Models\InboundReplyProfile;
use App\Modules\InboundMessaging\Services\Email\InboundEmailContactExtractor;
use App\Modules\InboundMessaging\Services\Email\InboundEmailRouteResolver;
use App\Modules\InboundMessaging\Services\Email\RoutedInboundMessageConsumerRegistry;
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
        private readonly InboundEmailRouteResolver $emailRouteResolver,
        private readonly RoutedInboundMessageConsumerRegistry $routedMessageConsumers,
        private readonly InboundEmailContactExtractor $contactExtractor,
    ) {}

    public function findings(): iterable
    {
        yield from $this->emailRouteFindings();
        yield from $this->routedMessageConsumerFindings();

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

    private function emailRouteFindings(): iterable
    {
        if (! Schema::hasTable('inbound_email_routes')) {
            return;
        }

        $routes = InboundEmailRoute::query()
            ->active()
            ->orderBy('key')
            ->get();

        if ($routes->isEmpty()) {
            return;
        }

        if ($this->emailRouteResolver->configuredDomain() === null) {
            yield $this->error(
                code: 'inbound_messaging.email_routes.inbound_domain_missing',
                message: 'Active inbound email routes require a valid INBOUND_EMAIL_DOMAIN.',
                source: 'messaging.email.inbound_domain',
                path: 'messaging.email.inbound_domain',
            );
        }

        foreach ($routes as $route) {
            if ($this->emailRouteResolver->normalizeLocalPart($route->local_part) === null) {
                yield $this->error(
                    code: 'inbound_messaging.email_routes.local_part_invalid',
                    message: "Inbound email route [{$route->key}] has invalid local-part [{$route->local_part}].",
                    source: 'inbound_email_routes',
                    path: "inbound_email_routes.{$route->key}.local_part",
                    context: ['route_key' => $route->key],
                );
            }

            if ($this->emailRouteResolver->isReservedLocalPart($route->local_part)) {
                yield $this->error(
                    code: 'inbound_messaging.email_routes.local_part_reserved',
                    message: "Inbound email route [{$route->key}] uses the reserved signed Reply-To namespace [reply+].",
                    source: 'inbound_email_routes',
                    path: "inbound_email_routes.{$route->key}.local_part",
                    context: ['route_key' => $route->key],
                );
            }

            if (! is_string($route->source) || trim($route->source) === '') {
                yield $this->error(
                    code: 'inbound_messaging.email_routes.source_missing',
                    message: "Inbound email route [{$route->key}] requires a source.",
                    source: 'inbound_email_routes',
                    path: "inbound_email_routes.{$route->key}.source",
                    context: ['route_key' => $route->key],
                );
            }

            if ($route->contact_extraction_enabled) {
                $definition = is_array($route->contact_extraction_definition)
                    ? $route->contact_extraction_definition
                    : [];
                $errors = $this->contactExtractor->validationErrors($definition);

                foreach ($errors as $field => $messages) {
                    foreach ($messages as $message) {
                        yield $this->error(
                            code: 'inbound_messaging.email_routes.contact_extraction_invalid',
                            message: "Inbound email route [{$route->label}] has invalid automatic person extraction: {$message}",
                            source: 'inbound_email_routes',
                            path: "inbound_email_routes.{$route->key}.contact_extraction_definition.{$field}",
                            context: [
                                'route_key' => $route->key,
                                'field' => $field,
                            ],
                        );
                    }
                }
            }
        }
    }

    private function routedMessageConsumerFindings(): iterable
    {
        try {
            foreach ($this->routedMessageConsumers->duplicateKeys() as $key) {
                yield $this->error(
                    code: 'inbound_messaging.email_routes.consumer_key_duplicate',
                    message: "Routed inbound-message consumer key [{$key}] is registered more than once.",
                    source: 'routed_message_consumers',
                    path: $key,
                    context: ['consumer_key' => $key],
                );
            }
        } catch (Throwable $exception) {
            yield $this->error(
                code: 'inbound_messaging.email_routes.consumer_invalid',
                message: $exception->getMessage(),
                source: 'routed_message_consumers',
            );

            return;
        }

        if (! Schema::hasTable('inbound_email_routes')) {
            return;
        }

        $routes = InboundEmailRoute::query()
            ->active()
            ->orderBy('key')
            ->get();

        foreach ($routes as $route) {
            try {
                $matches = $this->routedMessageConsumers->matching(
                    InboundEmailRouteIdentity::fromRoute($route),
                );
            } catch (Throwable $exception) {
                yield $this->error(
                    code: 'inbound_messaging.email_routes.consumer_invalid',
                    message: "Inbound address [{$route->label}] could not evaluate its connected business process: {$exception->getMessage()}",
                    source: 'routed_message_consumers',
                    path: "inbound_email_routes.{$route->key}",
                    context: ['route_key' => $route->key],
                );

                continue;
            }

            if (count($matches) <= 1) {
                continue;
            }

            yield $this->error(
                code: 'inbound_messaging.email_routes.consumer_conflict',
                message: "Inbound address [{$route->label}] is connected to more than one routed-message consumer.",
                source: 'routed_message_consumers',
                path: "inbound_email_routes.{$route->key}",
                context: [
                    'route_key' => $route->key,
                    'consumer_keys' => array_map(
                        fn ($consumer): string =>
                            $this->routedMessageConsumers->consumerKey($consumer),
                        $matches,
                    ),
                ],
            );
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