<?php

namespace App\Modules\Webinars\Requests;

use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Services\WebinarRegistrationQuestionResolver;
use App\Modules\Webinars\Support\WebinarRegisterPageConfig;
use Illuminate\Foundation\Http\FormRequest;

class StoreWebinarPostRegistrationQuestionsRequest extends FormRequest
{
    /** @var array<int, array<string, mixed>>|null */
    private ?array $questions = null;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'registration_questions' => app(
                WebinarRegistrationQuestionResolver::class,
            )->normalizeSubmittedAnswers(
                $this->input('registration_questions'),
            ),
        ]);
    }

    public function rules(): array
    {
        return app(WebinarRegistrationQuestionResolver::class)->validationRules(
            questions: $this->questions(),
            submittedAnswers: $this->input('registration_questions'),
        );
    }

    public function messages(): array
    {
        return app(WebinarRegistrationQuestionResolver::class)->validationMessages(
            $this->questions(),
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function questions(): array
    {
        if ($this->questions !== null) {
            return $this->questions;
        }

        $seriesSlug = trim((string) $this->route('seriesSlug'));
        $series = $seriesSlug !== ''
            ? WebinarSeries::query()
                ->where('slug', $seriesSlug)
                ->where('status', 'active')
                ->first()
            : null;
        $content = app(WebinarRegisterPageConfig::class)->content(
            page: 'register',
            seriesSlug: $seriesSlug,
            seriesMeta: is_array($series?->meta) ? $series->meta : [],
        );

        return $this->questions = app(
            WebinarRegistrationQuestionResolver::class,
        )->resolveForPlacement(
            data_get($content, 'registration.questions', []),
            WebinarRegistrationQuestionResolver::PLACEMENT_POST_REGISTRATION,
        );
    }
}