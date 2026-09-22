<?php

namespace App\Modules\Webinars\Services;

use App\Modules\Webinars\Models\Webinar;
use App\Modules\Webinars\Models\WebinarRegistration;
use App\Modules\Webinars\Models\WebinarRegistrationResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class WebinarSessionRegistrationSummary
{
    /**
     * @return array{
     *     registrant_count: int,
     *     registrants: array<int, array<string, mixed>>,
     *     questions: array<int, array<string, mixed>>
     * }
     */
    public function forWebinar(Webinar $webinar): array
    {
        $registrations = $webinar->registrations()
            ->with('contact')
            ->latest('registered_at')
            ->latest('id')
            ->get();

        $responses = $registrations->isEmpty()
            ? collect()
            : WebinarRegistrationResponse::query()
                ->whereIn(
                    'webinar_registration_id',
                    $registrations->pluck('id')->all(),
                )
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get();

        $registrationsById = $registrations->keyBy(
            fn (WebinarRegistration $registration): int =>
                (int) $registration->getKey(),
        );

        return [
            'registrant_count' => $registrations->count(),
            'registrants' => $registrations
                ->map(fn (WebinarRegistration $registration): array =>
                    $this->registrant($registration)
                )
                ->values()
                ->all(),
            'questions' => $this->questions(
                responses: $responses,
                registrationsById: $registrationsById,
            ),
        ];
    }

    /**
     * @param Collection<int, WebinarRegistrationResponse> $responses
     * @param Collection<int, WebinarRegistration> $registrationsById
     * @return array<int, array<string, mixed>>
     */
    private function questions(
        Collection $responses,
        Collection $registrationsById,
    ): array {
        return $responses
            ->groupBy(fn (WebinarRegistrationResponse $response): string =>
                $this->questionIdentity($response)
            )
            ->map(function (Collection $questionResponses) use (
                $registrationsById,
            ): array {
                /** @var WebinarRegistrationResponse $first */
                $first = $questionResponses
                    ->sortBy([
                        ['sort_order', 'asc'],
                        ['id', 'asc'],
                    ])
                    ->first();

                $counts = [];
                $customResponses = [];

                foreach ($questionResponses as $response) {
                    if ($this->isCustomResponse($response)) {
                        $text = $this->text($response->answer_text);

                        if ($text === null) {
                            continue;
                        }

                        $registration = $registrationsById->get(
                            (int) $response->webinar_registration_id,
                        );

                        $customResponses[] = [
                            'text' => $text,
                            'respondent' => $registration instanceof WebinarRegistration
                                ? $this->registrationName($registration)
                                : null,
                        ];

                        continue;
                    }

                    $label = $this->answerLabel($response);

                    if ($label === null) {
                        continue;
                    }

                    $counts[$label] = ($counts[$label] ?? 0) + 1;
                }

                $answerCounts = collect($counts)
                    ->map(fn (int $count, string $label): array => [
                        'label' => $label,
                        'count' => $count,
                    ])
                    ->sort(function (array $left, array $right): int {
                        $count = $right['count'] <=> $left['count'];

                        return $count !== 0
                            ? $count
                            : strnatcasecmp($left['label'], $right['label']);
                    })
                    ->values()
                    ->all();

                return [
                    'key' => $this->text($first->question_key)
                        ?? 'question_'.$first->getKey(),
                    'label' => $this->text($first->question_label)
                        ?? Str::headline((string) $first->question_key),
                    'response_count' => $questionResponses->count(),
                    'answer_counts' => $answerCounts,
                    'custom_responses' => $customResponses,
                    'sort_order' => (int) $first->sort_order,
                ];
            })
            ->sortBy([
                ['sort_order', 'asc'],
                ['label', 'asc'],
            ])
            ->values()
            ->map(function (array $question): array {
                unset($question['sort_order']);

                return $question;
            })
            ->all();
    }

    private function registrant(WebinarRegistration $registration): array
    {
        $contact = $registration->contact;
        $name = $this->registrationName($registration);

        $status = match (true) {
            $registration->cancelled_at !== null => 'Cancelled',
            $registration->attended_at !== null
                || $registration->status === 'attended' => 'Attended',
            $registration->status === 'missed' => 'Missed',
            filled($registration->status) => Str::headline(
                (string) $registration->status,
            ),
            default => 'Registered',
        };

        return [
            'registration_id' => (int) $registration->getKey(),
            'contact_id' => $contact?->getKey() !== null
                ? (int) $contact->getKey()
                : null,
            'name' => $name ?? 'Registrant #'.$registration->getKey(),
            'email' => $this->text($contact?->email),
            'status' => $status,
            'contact_url' => $contact !== null
                ? route('crm.contacts.show', $contact)
                : null,
        ];
    }

    private function registrationName(
        WebinarRegistration $registration,
    ): ?string {
        $contact = $registration->contact;

        if ($contact === null) {
            return null;
        }

        foreach ([
            $contact->name,
            trim((string) $contact->first_name.' '.(string) $contact->last_name),
            $contact->email,
        ] as $candidate) {
            $value = $this->text($candidate);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function questionIdentity(
        WebinarRegistrationResponse $response,
    ): string {
        return $this->text($response->question_key)
            ?? $this->text($response->question_label)
            ?? 'question_'.$response->sort_order;
    }

    private function isCustomResponse(
        WebinarRegistrationResponse $response,
    ): bool {
        if ($this->text($response->answer_text) === null) {
            return false;
        }

        $type = strtolower((string) $response->question_type);
        $answerKey = strtolower((string) $response->answer_key);
        $answerLabel = strtolower((string) $response->answer_label);

        return in_array($type, ['text', 'textarea', 'free_text'], true)
            || $answerKey === 'other'
            || $answerLabel === 'other';
    }

    private function answerLabel(
        WebinarRegistrationResponse $response,
    ): ?string {
        return $this->text($response->answer_label)
            ?? $this->text($response->answer_text)
            ?? ($this->text($response->answer_key) !== null
                ? Str::headline((string) $response->answer_key)
                : null);
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}