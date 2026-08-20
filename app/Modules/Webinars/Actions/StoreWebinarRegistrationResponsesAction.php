<?php

namespace App\Modules\Webinars\Actions;

use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Services\WebinarRegistrationQuestionResolver;
use App\Modules\Webinars\Support\WebinarRegisterPageConfig;
use LogicException;

class StoreWebinarRegistrationResponsesAction
{
    public function __construct(
        private readonly WebinarRegisterPageConfig $registerPageConfig,
        private readonly WebinarRegistrationQuestionResolver $questionResolver,
    ) {}

    /**
     * @param array<string, mixed> $submittedAnswers
     */
    public function handle(
        WebinarRegistration $registration,
        array $submittedAnswers,
        string $placement = WebinarRegistrationQuestionResolver::PLACEMENT_REGISTRATION,
        bool $replacePlacement = false,
    ): void {
        $registration->loadMissing('webinar.webinarSeries');
        $series = $registration->webinar?->webinarSeries;

        if (! $series) {
            throw new LogicException(
                'Webinar registration questions require a Webinar series.',
            );
        }

        $content = $this->registerPageConfig->content(
            page: 'register',
            seriesSlug: $series->slug,
            seriesMeta: is_array($series->meta) ? $series->meta : [],
        );
        $questions = $this->questionResolver->resolveForPlacement(
            data_get($content, 'registration.questions', []),
            $placement,
        );
        $snapshots = $this->questionResolver->responseSnapshots(
            questions: $questions,
            submittedAnswers: $submittedAnswers,
        );

        foreach ($snapshots as $snapshot) {
            $registration->responses()->updateOrCreate(
                [
                    'question_key' => $snapshot['question_key'],
                ],
                $snapshot,
            );
        }

        if (! $replacePlacement) {
            return;
        }

        $configuredKeys = array_column($questions, 'key');
        $storedKeys = array_column($snapshots, 'question_key');
        $keysToRemove = array_values(array_diff($configuredKeys, $storedKeys));

        if ($keysToRemove !== []) {
            $registration->responses()
                ->whereIn('question_key', $keysToRemove)
                ->delete();
        }
    }
}