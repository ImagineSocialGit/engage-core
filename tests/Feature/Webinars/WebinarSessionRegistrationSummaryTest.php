<?php

namespace Tests\Feature\Webinars;

use App\Modules\Core\Models\Contact;
use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarSeries;
use App\Modules\Webinars\Services\WebinarSessionRegistrationSummary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebinarSessionRegistrationSummaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_aggregates_shared_answers_and_keeps_custom_responses_separate(): void
    {
        $series = WebinarSeries::factory()->create();
        $webinar = Webinar::factory()->for($series)->create();

        $first = $this->registration($webinar, 'Avery One', 'avery@example.test');
        $second = $this->registration($webinar, 'Blake Two', 'blake@example.test');
        $third = $this->registration($webinar, 'Casey Three', 'casey@example.test');
        $fourth = $this->registration($webinar, 'Drew Four', 'drew@example.test');

        $this->answer($first, 'qualification', 'How do I know whether I qualify?', null);
        $this->answer($second, 'qualification', 'How do I know whether I qualify?', null);
        $this->answer($third, 'funding_fee', 'Will I have to pay the VA funding fee?', null);
        $this->answer(
            $fourth,
            'other',
            'Other',
            'Can I use my VA loan while keeping my current home?',
        );

        $summary = app(WebinarSessionRegistrationSummary::class)
            ->forWebinar($webinar);

        $this->assertSame(4, $summary['registrant_count']);
        $this->assertCount(4, $summary['registrants']);
        $this->assertCount(1, $summary['questions']);

        $question = $summary['questions'][0];

        $this->assertSame(
            'What is your biggest question or concern about using a VA home loan?',
            $question['label'],
        );
        $this->assertSame(4, $question['response_count']);
        $this->assertSame([
            [
                'label' => 'How do I know whether I qualify?',
                'count' => 2,
            ],
            [
                'label' => 'Will I have to pay the VA funding fee?',
                'count' => 1,
            ],
        ], $question['answer_counts']);
        $this->assertSame([
            [
                'text' => 'Can I use my VA loan while keeping my current home?',
                'respondent' => 'Drew Four',
            ],
        ], $question['custom_responses']);
    }

    private function registration(
        Webinar $webinar,
        string $name,
        string $email,
    ): WebinarRegistration {
        $parts = explode(' ', $name, 2);
        $contact = Contact::factory()->create([
            'name' => $name,
            'first_name' => $parts[0],
            'last_name' => $parts[1] ?? null,
            'email' => $email,
        ]);

        return WebinarRegistration::factory()
            ->for($contact)
            ->for($webinar)
            ->create();
    }

    private function answer(
        WebinarRegistration $registration,
        string $answerKey,
        string $answerLabel,
        ?string $answerText,
    ): void {
        $registration->responses()->create([
            'question_key' => 'primary_homebuying_question',
            'question_label' => 'What is your biggest question or concern about using a VA home loan?',
            'question_type' => 'select',
            'answer_key' => $answerKey,
            'answer_label' => $answerLabel,
            'answer_text' => $answerText,
            'definition_version' => '2026_09',
            'sort_order' => 10,
        ]);
    }
}