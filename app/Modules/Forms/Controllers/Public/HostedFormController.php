<?php

namespace App\Modules\Forms\Controllers\Public;

use App\Modules\Forms\Actions\CreateFormSubmissionAction;
use App\Modules\Forms\Data\FormSubmissionInput;
use App\Modules\Forms\Data\FormSubmissionVerification;
use App\Modules\Forms\Data\PublishedForm;
use App\Modules\Forms\Exceptions\FormSubmissionValidationException;
use App\Modules\Forms\Services\HostedFormPresenter;
use App\Modules\Forms\Services\HostedFormRuntimeValidator;
use App\Modules\Forms\Services\PublishedFormResolver;
use App\Support\HumanVerification\HumanVerificationManager;
use DomainException;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class HostedFormController
{
    private const HUMAN_VERIFICATION_SURFACE = 'forms';

    private const PUBLIC_SURFACE_TRACKING_EVENT = 'form_submission_completed';

    public function __construct(
        private readonly PublishedFormResolver $forms,
        private readonly HostedFormPresenter $presenter,
        private readonly HostedFormRuntimeValidator $runtime,
        private readonly CreateFormSubmissionAction $submissions,
        private readonly HumanVerificationManager $humanVerification,
    ) {}

    public function show(Request $request, string $formSlug): View
    {
        $form = $this->resolveHostedForm($formSlug);
        $presentation = $this->presenter->present($form);

        return view('forms.show', [
            'title' => $form->name,
            'form' => $presentation,
            'submitted' => $request->session()->get('forms.hosted.success') === $form->key,
        ]);
    }

    public function store(Request $request, string $formSlug): RedirectResponse
    {
        $form = $this->resolveHostedForm($formSlug);
        $presentation = $this->presenter->present($form);
        $redirect = route('forms.public.show', [
            'formSlug' => $presentation['slug'],
        ]);
        $values = [];

        foreach ($form->fieldKeys() as $fieldKey) {
            if ($request->exists($fieldKey)) {
                $values[$fieldKey] = $request->input($fieldKey);
            }
        }

        try {
            $this->submissions->handle(new FormSubmissionInput(
                formKey: $form->key,
                values: $values,
                source: 'core_hosted_forms',
                rawPayload: $values,
                meta: [
                    'hosted_form' => [
                        'host' => $request->getHost(),
                        'path' => '/'.$formSlug,
                    ],
                ],
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
                verification: $this->verificationFromRequest($request),
                publicOnly: true,
            ));
        } catch (FormSubmissionValidationException $exception) {
            return back()
                ->withInput($request->except([
                    '_token',
                    $this->humanVerification->responseField(),
                ]))
                ->withErrors($exception->errors());
        }

        return redirect($redirect)
            ->with('forms.hosted.success', $form->key)
            ->with(
                'public_surfaces.tracking.event',
                self::PUBLIC_SURFACE_TRACKING_EVENT,
            );
    }

    private function resolveHostedForm(string $formSlug): PublishedForm
    {
        $formSlug = strtolower(trim($formSlug));

        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $formSlug) !== 1) {
            abort(404);
        }

        $formKey = str_replace('-', '_', $formSlug);

        try {
            $form = $this->forms->require(
                key: $formKey,
                publicOnly: true,
            );
            $this->runtime->validate($form);
            $presentation = $this->presenter->present($form);
        } catch (DomainException) {
            abort(404);
        }

        if (! ($presentation['enabled'] ?? false)
            || ($presentation['slug'] ?? null) !== $formSlug
        ) {
            abort(404);
        }

        return $form;
    }

    private function verificationFromRequest(
        Request $request,
    ): ?FormSubmissionVerification {
        $evidence = $request->attributes->get('public_human_verification');

        if (! is_array($evidence)) {
            return null;
        }

        $provider = $evidence['provider'] ?? null;
        $verifiedAt = $evidence['verified_at'] ?? null;

        if (! is_string($provider) || ! is_string($verifiedAt)) {
            return null;
        }

        return new FormSubmissionVerification(
            provider: $provider,
            outcome: FormSubmissionVerification::OUTCOME_PASSED,
            verifiedAt: $verifiedAt,
            hostname: $request->getHost(),
            action: (string) config(
                'human_verification.surfaces.'.self::HUMAN_VERIFICATION_SURFACE.'.action',
                self::HUMAN_VERIFICATION_SURFACE,
            ),
            authenticatedClientId: 'core_hosted_forms',
        );
    }
}