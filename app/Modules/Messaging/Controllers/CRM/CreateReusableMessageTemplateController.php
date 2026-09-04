<?php

namespace App\Modules\Messaging\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Messaging\Actions\CreateReusableMessageTemplateAction;
use App\Modules\Messaging\Data\ReusableMessageTemplateAuthoringOption;
use App\Modules\Messaging\Requests\CreateReusableMessageTemplateRequest;
use App\Modules\Messaging\Services\MessageTemplateAuthoringFieldPresenter;
use App\Modules\Messaging\Services\ReusableMessageTemplateAuthoringGuide;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

final class CreateReusableMessageTemplateController extends Controller
{
    public function create(
        Request $request,
        ReusableMessageTemplateAuthoringGuide $guide,
        MessageTemplateAuthoringFieldPresenter $authoringFields,
    ): View {
        $options = $guide->options();
        $selected = $guide->find($request->query('use')) ?? ($options[0] ?? null);

        return view('crm.messaging.message-templates.create', [
            'title' => 'Create Message Template',
            'heading' => 'Create Message Template',
            'options' => $options,
            'selectedOption' => $selected,
            'availableFields' => $selected instanceof ReusableMessageTemplateAuthoringOption
                ? $authoringFields->groupsForContext($selected->context->dispatchKey)
                : [],
        ]);
    }

    public function store(
        CreateReusableMessageTemplateRequest $request,
        ReusableMessageTemplateAuthoringGuide $guide,
        CreateReusableMessageTemplateAction $createReusableMessageTemplate,
    ): RedirectResponse {
        $option = $guide->find($request->authoringOptionKey());

        if (! $option instanceof ReusableMessageTemplateAuthoringOption) {
            throw ValidationException::withMessages([
                'authoring_option' => 'Choose a supported message use before creating the template.',
            ]);
        }

        try {
            $preset = $createReusableMessageTemplate->handle(
                name: $request->templateName(),
                channel: $option->channel,
                payload: $request->payloadForChannel($option->channel),
                context: $option->context,
                createdBy: $request->user(),
            );
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages([
                'message_template' => $exception->getMessage(),
            ]);
        }

        return redirect()
            ->route('crm.messaging.message-templates.index', [
                'group' => $option->context->groupKey,
                'preset' => $preset->getKey(),
            ])
            ->with('status', 'Reusable message template created.');
    }
}