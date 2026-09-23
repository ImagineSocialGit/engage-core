<?php

namespace App\Modules\Webinars\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Webinars\Actions\ResolvePublicWebinarSeriesVariantAction;
use App\Modules\Webinars\Actions\ResolveWebinarRegistrationPublicStatusAction;
use App\Modules\Webinars\Actions\ResolveWebinarRegistrationReplacementChainAction;
use App\Modules\Webinars\Actions\StoreWebinarRegistrationResponsesAction;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarSeriesVariant;
use App\Modules\Webinars\Requests\StoreWebinarPostRegistrationQuestionsRequest;
use App\Modules\Webinars\Services\WebinarRegistrationQuestionResolver;
use App\Modules\Webinars\Support\WebinarRegisterPageConfig;
use App\Modules\Webinars\Support\WebinarRegistrationPostQuestionLinkGenerator;
use App\Modules\Webinars\Support\WebinarRegistrationThankYouLinkGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class WebinarPostRegistrationQuestionController extends Controller
{
    public function show(
        string $seriesSlug,
        WebinarRegistration $registration,
        ResolvePublicWebinarSeriesVariantAction $resolvePublicVariant,
        ResolveWebinarRegistrationReplacementChainAction $resolveReplacementChain,
        ResolveWebinarRegistrationPublicStatusAction $resolvePublicStatus,
        WebinarRegisterPageConfig $config,
        WebinarRegistrationQuestionResolver $questionResolver,
        WebinarRegistrationPostQuestionLinkGenerator $postQuestionLinks,
        WebinarRegistrationThankYouLinkGenerator $thankYouLinks,
    ): View|RedirectResponse {
        [$series, $chain, $registration, $variant] = $this->resolveRegistration(
            seriesSlug: $seriesSlug,
            registration: $registration,
            resolvePublicVariant: $resolvePublicVariant,
            resolveReplacementChain: $resolveReplacementChain,
        );

        $registrationStatus = $resolvePublicStatus->handleChain($chain);

        if ($registrationStatus === ResolveWebinarRegistrationPublicStatusAction::STATUS_CANCELLED) {
            return redirect()->to($thankYouLinks->forRegistration($registration));
        }

        $content = $config->content(
            page: 'register',
            seriesSlug: (string) $series->slug,
            seriesMeta: is_array($series->meta) ? $series->meta : [],
        );
        $questions = $questionResolver->resolveForPlacement(
            data_get($content, 'registration.questions', []),
            WebinarRegistrationQuestionResolver::PLACEMENT_POST_REGISTRATION,
        );

        if ($questions === []) {
            return redirect()->to($thankYouLinks->forRegistration($registration));
        }

        $page = data_get($content, 'registration.post_registration', []);
        $page = is_array($page) ? $page : [];
        $stateContent = data_get($page, "states.{$registrationStatus}", []);
        unset($page['states']);

        if (is_array($stateContent)) {
            $page = array_replace_recursive($page, $stateContent);
        }

        return view('webinar.post-registration-questions', [
            'series' => $series,
            'variant' => $variant,
            'webinar' => $registration->webinar,
            'registration' => $registration,
            'registrationStatus' => $registrationStatus,
            'page' => $page,
            'eventDetails' => data_get($content, 'landing.event_details', []),
            'questions' => $questions,
            'style' => $config->style('register', (string) $series->slug),
            'formAction' => $postQuestionLinks->formAction($registration),
        ]);
    }

    public function store(
        StoreWebinarPostRegistrationQuestionsRequest $request,
        string $seriesSlug,
        WebinarRegistration $registration,
        ResolvePublicWebinarSeriesVariantAction $resolvePublicVariant,
        ResolveWebinarRegistrationReplacementChainAction $resolveReplacementChain,
        ResolveWebinarRegistrationPublicStatusAction $resolvePublicStatus,
        StoreWebinarRegistrationResponsesAction $storeResponses,
        WebinarRegistrationThankYouLinkGenerator $thankYouLinks,
    ): RedirectResponse {
        [, $chain, $registration] = $this->resolveRegistration(
            seriesSlug: $seriesSlug,
            registration: $registration,
            resolvePublicVariant: $resolvePublicVariant,
            resolveReplacementChain: $resolveReplacementChain,
        );

        if (
            $resolvePublicStatus->handleChain($chain)
            === ResolveWebinarRegistrationPublicStatusAction::STATUS_CANCELLED
        ) {
            return redirect()->to($thankYouLinks->forRegistration($registration));
        }

        $validated = $request->validated();
        $answers = $validated['registration_questions'] ?? [];

        $storeResponses->handle(
            registration: $registration,
            submittedAnswers: is_array($answers) ? $answers : [],
            placement: WebinarRegistrationQuestionResolver::PLACEMENT_POST_REGISTRATION,
            replacePlacement: true,
        );

        return redirect()->to($thankYouLinks->forRegistration($registration));
    }

    /**
     * @return array{0: mixed, 1: mixed, 2: WebinarRegistration, 3: WebinarSeriesVariant}
     */
    private function resolveRegistration(
        string $seriesSlug,
        WebinarRegistration $registration,
        ResolvePublicWebinarSeriesVariantAction $resolvePublicVariant,
        ResolveWebinarRegistrationReplacementChainAction $resolveReplacementChain,
    ): array {
        $variant = $resolvePublicVariant->findByPublicSlug($seriesSlug);
        $series = $variant?->webinarSeries;

        abort_unless($variant && $series, 404);

        $chain = $resolveReplacementChain->handle($registration);
        $originalWebinar = $chain->original->webinar;

        abort_unless(
            $originalWebinar
            && (int) $originalWebinar->webinar_series_id === (int) $series->getKey()
            && (! $variant->exists
                || (int) $originalWebinar->webinar_series_variant_id === (int) $variant->getKey()),
            404,
        );
        abort_unless($chain->safeForPublicLifecycle(), 404);

        $registration = $chain->canonical;
        $registration->loadMissing([
            'webinar.webinarSeries',
            'webinar.webinarSeriesVariant',
        ]);
        $webinar = $registration->webinar;

        abort_unless(
            $webinar
            && (int) $webinar->webinar_series_id === (int) $series->getKey()
            && (! $variant->exists
                || (int) $webinar->webinar_series_variant_id === (int) $variant->getKey()),
            404,
        );

        return [$series, $chain, $registration, $variant];
    }
}