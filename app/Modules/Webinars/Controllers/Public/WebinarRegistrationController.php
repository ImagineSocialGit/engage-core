<?php

namespace App\Modules\Webinars\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Modules\Messaging\Services\MessageChannelAvailability;
use App\Modules\Webinars\Actions\CreateWebinarRegistrationAction;
use App\Modules\Webinars\Actions\GetActiveWebinarSeriesAction;
use App\Modules\Webinars\Actions\GetNextUpcomingWebinarAction;
use App\Modules\Webinars\Actions\ResolvePublicWebinarSeriesVariantAction;
use App\Modules\Webinars\Actions\ResolveWebinarRegistrationPublicStatusAction;
use App\Modules\Webinars\Actions\ResolveWebinarRegistrationReplacementChainAction;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarWaitlistSignup;
use App\Modules\Webinars\Requests\StoreWebinarRegistrationRequest;
use App\Modules\Webinars\Services\WebinarRegistrationQuestionResolver;
use App\Modules\Webinars\Support\WebinarRegisterPageConfig;
use App\Modules\Webinars\Support\WebinarRegistrationPostQuestionLinkGenerator;
use App\Modules\Webinars\Support\WebinarRegistrationThankYouLinkGenerator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\View\View;

class WebinarRegistrationController extends Controller
{
    public function index(GetActiveWebinarSeriesAction $getActiveWebinarSeriesAction)
    {
        return view('webinar.index', [
            'series' => $getActiveWebinarSeriesAction->handle(),
        ]);
    }

    public function show(
        string $seriesSlug,
        ResolvePublicWebinarSeriesVariantAction $resolvePublicVariant,
        GetNextUpcomingWebinarAction $getNextUpcomingWebinarAction
    ): Response {
        return response($this->renderShowPage(
            $seriesSlug,
            $resolvePublicVariant,
            $getNextUpcomingWebinarAction,
        ));
    }

    public function showFromWaitlist(
        string $seriesSlug,
        int $signup,
        ResolvePublicWebinarSeriesVariantAction $resolvePublicVariant,
        GetNextUpcomingWebinarAction $getNextUpcomingWebinarAction,
    ) {
        $variant = $resolvePublicVariant->findByPublicSlug($seriesSlug);
        $series = $variant?->webinarSeries;

        abort_unless($variant && $series, 404);

        $webinar = $variant->exists
            ? $getNextUpcomingWebinarAction->getForVariant($variant)
            : $getNextUpcomingWebinarAction->getForSeries($series);

        abort_unless($webinar, 404);

        $waitlistSignup = WebinarWaitlistSignup::query()
            ->with('contact')
            ->whereKey($signup)
            ->where('webinar_series_id', $series->getKey())
            ->when(
                $variant->exists,
                fn ($query) => $query->where('webinar_series_variant_id', $variant->getKey()),
                fn ($query) => $query->whereNull('webinar_series_variant_id'),
            )
            ->firstOrFail();

        $contact = $waitlistSignup->contact;

        session()->flashInput([
            'first_name' => $contact?->first_name,
            'last_name' => $contact?->last_name,
            'email' => $contact?->email,
            'phone' => $contact?->phone,
        ]);

        return response($this->renderShowPage(
            $seriesSlug,
            $resolvePublicVariant,
            $getNextUpcomingWebinarAction,
            [
                'first_name' => $contact?->first_name,
                'last_name' => $contact?->last_name,
                'email' => $contact?->email,
                'phone' => $contact?->phone,
            ],
        ));
    }

    private function renderShowPage(
        string $seriesSlug,
        ResolvePublicWebinarSeriesVariantAction $resolvePublicVariant,
        GetNextUpcomingWebinarAction $getNextUpcomingWebinarAction,
        array $registrationPrefill = [],
    ): string {
        $variant = $resolvePublicVariant->findByPublicSlug($seriesSlug);
        $series = $variant?->webinarSeries;

        abort_unless($variant && $series, 404);

        $webinar = $variant->exists
            ? $getNextUpcomingWebinarAction->getForVariant($variant)
            : $getNextUpcomingWebinarAction->getForSeries($series);

        $config = app(WebinarRegisterPageConfig::class);
        $channelAvailability = app(MessageChannelAvailability::class);

        if (! $webinar) {
            return view('webinar.notify-me', [
                'series' => $series,
                'variant' => $variant,
                'page' => $config->content('notify-me', (string) $series->slug, $series->meta ?? []),
                'style' => $config->style('notify-me', (string) $series->slug),
                'webinarWaitlistChannels' => [
                    'marketing' => $channelAvailability->visibleChannelsForSurface(
                        surface: 'webinar_waitlists',
                        purpose: 'marketing',
                        scope: 'webinar_waitlist',
                    ),
                ],
            ])->render();
        }

        return view('webinar.register', [
            'webinar' => $webinar,
            'series' => $series,
            'variant' => $variant,
            'page' => $config->content('register', (string) $series->slug, $series->meta ?? []),
            'style' => $config->style('register', (string) $series->slug),
            'registrationPrefill' => $registrationPrefill,
            'webinarRegistrationChannels' => [
                'transactional' => $channelAvailability->visibleChannelsForSurface(
                    surface: 'webinar_registrations',
                    purpose: 'transactional',
                    scope: 'webinar',
                ),
                'marketing' => $channelAvailability->visibleChannelsForSurface(
                    surface: 'webinar_registrations',
                    purpose: 'marketing',
                    scope: 'webinar_nurture',
                ),
            ],
        ])->render();
    }

    public function store(
        StoreWebinarRegistrationRequest $request,
        string $seriesSlug,
        CreateWebinarRegistrationAction $createWebinarRegistrationAction,
        ResolvePublicWebinarSeriesVariantAction $resolvePublicVariant,
        WebinarRegisterPageConfig $config,
        WebinarRegistrationQuestionResolver $questionResolver,
        WebinarRegistrationPostQuestionLinkGenerator $postQuestionLinks,
        WebinarRegistrationThankYouLinkGenerator $thankYouLinks,
    ): RedirectResponse {
        $variant = $resolvePublicVariant->findByPublicSlug($seriesSlug);
        $series = $variant?->webinarSeries;

        abort_unless($variant && $series, 404);

        $webinar = $request->registerableWebinar();

        if (! $webinar) {
            return redirect()->route('webinar.show', [
                'seriesSlug' => $seriesSlug,
            ]);
        }

        $result = $createWebinarRegistrationAction->handle(
            $request->validated(),
            $request,
            $webinar,
        );

        if ($result->wasCreated()) {
            $request->session()->flash(
                'public_surfaces.tracking.event',
                'webinar_registration_completed',
            );
        }

        $content = $config->content(
            page: 'register',
            seriesSlug: (string) $series->slug,
            seriesMeta: is_array($series->meta) ? $series->meta : [],
        );
        $postRegistrationQuestions = $questionResolver->resolveForPlacement(
            data_get($content, 'registration.questions', []),
            WebinarRegistrationQuestionResolver::PLACEMENT_POST_REGISTRATION,
        );

        return redirect()->to(
            $postRegistrationQuestions !== []
                ? $postQuestionLinks->forRegistration($result->registration)
                : $thankYouLinks->forRegistration($result->registration),
        );
    }

    public function showThankYou(
        string $seriesSlug,
        WebinarRegistration $registration,
        ResolvePublicWebinarSeriesVariantAction $resolvePublicVariant,
        ResolveWebinarRegistrationReplacementChainAction $resolveReplacementChain,
        ResolveWebinarRegistrationPublicStatusAction $resolvePublicStatus,
        WebinarRegisterPageConfig $config,
    ): View {
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
        $webinar = $registration->webinar;

        abort_unless(
            $webinar
            && (int) $webinar->webinar_series_id === (int) $series->getKey()
            && (! $variant->exists
                || (int) $webinar->webinar_series_variant_id === (int) $variant->getKey()),
            404,
        );

        $registrationStatus = $resolvePublicStatus->handleChain($chain);
        $page = $config->content(
            'thank-you',
            (string) $series->slug,
            $series->meta ?? [],
        );
        $stateContent = data_get($page, "states.{$registrationStatus}", []);
        unset($page['states']);

        if (is_array($stateContent)) {
            $page = array_replace_recursive($page, $stateContent);
        }

        $refreshSeconds = $registrationStatus
            === ResolveWebinarRegistrationPublicStatusAction::STATUS_PROCESSING
                ? max(
                    3,
                    (int) config(
                        'webinars.registration.thank_you.refresh_seconds',
                        5,
                    ),
                )
                : null;

        return view('webinar.thank-you', [
            'series' => $series,
            'variant' => $variant,
            'webinar' => $webinar,
            'registration' => $registration,
            'registrationStatus' => $registrationStatus,
            'refreshSeconds' => $refreshSeconds,
            'page' => $page,
            'style' => $config->style('thank-you', (string) $series->slug),
        ]);
    }
}