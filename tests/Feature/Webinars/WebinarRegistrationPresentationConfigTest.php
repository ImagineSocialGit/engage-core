<?php

namespace Tests\Feature\Webinars;

use App\Modules\Webinars\Services\WebinarRegisterPageDefinitionValidator;
use Tests\TestCase;

class WebinarRegistrationPresentationConfigTest extends TestCase
{
    public function test_resolved_registration_definition_accepts_modal_and_inline_presentations(): void
    {
        foreach (['modal', 'inline'] as $presentation) {
            $violations = app(WebinarRegisterPageDefinitionValidator::class)
                ->validateResolvedDefinition([
                    'landing' => [],
                    'registration' => [
                        'presentation' => $presentation,
                        'page_revision' => 'test-v1',
                        'questions' => [],
                        'fields' => [
                            'last_name' => [
                                'enabled' => true,
                            ],
                        ],
                    ],
                ], 'webinars.register.test');

            $this->assertSame([], array_values(array_filter(
                $violations,
                fn (array $violation): bool => in_array($violation['code'], [
                    'webinars.register_page.presentation_invalid',
                    'webinars.register_page.page_revision_invalid',
                    'webinars.register_page.field_configuration_invalid',
                ], true),
            )), $presentation);
        }
    }

    public function test_resolved_registration_definition_rejects_unknown_presentation_and_non_boolean_last_name_enablement(): void
    {
        $violations = app(WebinarRegisterPageDefinitionValidator::class)
            ->validateResolvedDefinition([
                'landing' => [],
                'registration' => [
                    'presentation' => 'drawer',
                    'page_revision' => '',
                    'questions' => [],
                    'fields' => [
                        'last_name' => [
                            'enabled' => 'no',
                        ],
                    ],
                ],
            ], 'webinars.register.test');

        $this->assertEqualsCanonicalizing([
            'webinars.register_page.presentation_invalid',
            'webinars.register_page.page_revision_invalid',
            'webinars.register_page.field_configuration_invalid',
        ], array_values(array_filter(
            array_column($violations, 'code'),
            fn (string $code): bool => in_array($code, [
                'webinars.register_page.presentation_invalid',
                'webinars.register_page.page_revision_invalid',
                'webinars.register_page.field_configuration_invalid',
            ], true),
        )));
    }
}