<?php

namespace App\Modules\Messaging\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Messaging\Actions\CreateReusableMessageTemplateAction;
use App\Modules\Messaging\Data\ReusableMessageTemplateAuthoringContext;
use App\Modules\Messaging\Requests\CreateFlowRouteMessageTemplateRequest;
use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Modules\Messaging\Services\RouteAuthoringMessageTemplateEligibilityResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CreateFlowRouteMessageTemplateController extends Controller
{
    public function __invoke(
        CreateFlowRouteMessageTemplateRequest $request,
        CreateReusableMessageTemplateAction $createReusableMessageTemplate,
        MessageChannelAvailability $channelAvailability,
    ): JsonResponse {
        $channel = $request->channel();
        $purpose = $request->purpose();

        if (! $channelAvailability->isVisibleForSurface(
            channel: $channel,
            surface: 'route_send_message_points',
            purpose: $purpose,
            scope: 'general',
        )) {
            throw ValidationException::withMessages([
                'channel' => 'That channel is not available for direct Route messages.',
            ]);
        }

        try {
            $preset = $createReusableMessageTemplate->handle(
                name: $request->templateName(),
                channel: $channel,
                payload: $request->payload(),
                context: new ReusableMessageTemplateAuthoringContext(
                    contextKey: RouteAuthoringMessageTemplateEligibilityResolver::SELECTION_CONTEXT,
                    purpose: $purpose,
                    scope: 'general',
                    dispatchKey: 'flow_route_send_message',
                    messageType: 'flow_route_message',
                    payloadClass: $request->payloadClass(),
                    queue: $purpose === 'marketing' ? 'marketing' : 'notifications',
                    moduleKey: 'flow_routes',
                    moduleLabel: 'Flow Routes',
                    surface: 'route_send_message_points',
                    groupKey: 'flow_routes:direct:'.$purpose.':'.$channel,
                    groupLabel: 'Flow Route Messages',
                    usageType: 'flow_route_direct',
                    selectionContexts: [RouteAuthoringMessageTemplateEligibilityResolver::SELECTION_CONTEXT],
                    description: 'Reusable message created for direct Flow Route sends.',
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
            'template_key' => (string) $preset->key,
            'name' => (string) $preset->name,
            'channel' => (string) $preset->channel,
            'purpose' => (string) $preset->purpose,
            'description' => ucfirst((string) $preset->channel).' · '.ucfirst((string) $preset->purpose),
        ], 201);
    }
}