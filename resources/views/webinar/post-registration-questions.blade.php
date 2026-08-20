@php
    $landingStyle = is_array($style['landing'] ?? null)
        ? $style['landing']
        : [];
    $registrationStyle = is_array($style['registration'] ?? null)
        ? $style['registration']
        : [];
    $tokens = $registrationStyle['tokens'] ?? $landingStyle['tokens'] ?? [];
    $questionInputClass = data_get(
        $registrationStyle,
        'components.input.base',
        'block w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-ink shadow-sm outline-none transition focus:border-primary focus:ring-2 focus:ring-primary/20',
    );
    $eventDetails = is_array($eventDetails ?? null) ? $eventDetails : [];
    $eventDetailItems = collect($eventDetails['items'] ?? [])->map(function (array $item) use ($webinar) {
        $key = $item['key'] ?? null;

        $resolvedValue = match ($key) {
            'date' => $webinar?->starts_at?->timezone($webinar->timezone ?? config('app.timezone'))->format('F j, Y'),
            'time' => $webinar?->starts_at?->timezone($webinar->timezone ?? config('app.timezone'))->format('g:i A T'),
            default => $item['value'] ?? null,
        };

        return [
            ...$item,
            'resolved_value' => $resolvedValue,
        ];
    })->filter(fn (array $item) => filled($item['resolved_value'] ?? null))->values();
@endphp

<x-layouts.public
    :title="$page['meta_title'] ?? 'Webinar Registration Questions'"
    :meta-description="$page['meta_description'] ?? null"
>
    <section
        class="bg-white text-ink"
        data-registration-status="{{ $registrationStatus }}"
    >
        <div class="mx-auto grid w-full max-w-6xl gap-10 px-6 py-12 sm:py-16 lg:grid-cols-[0.95fr_1.05fr] lg:items-start">
            <div class="space-y-6 lg:sticky lg:top-8">
                @if(filled($page['eyebrow'] ?? null))
                    <p class="{{ $tokens['eyebrow'] ?? 'text-sm font-semibold uppercase tracking-[0.2em] text-primary' }}">
                        {{ $page['eyebrow'] }}
                    </p>
                @endif

                <div class="space-y-3">
                    <h1 class="text-4xl font-bold tracking-tight text-slate-900 sm:text-5xl">
                        {{ $page['title'] ?? 'Your registration details are saved.' }}
                    </h1>

                    @if(filled($page['body'] ?? null))
                        <p class="text-lg leading-8 text-slate-600">
                            {{ $page['body'] }}
                        </p>
                    @endif
                </div>

                <div class="rounded-3xl border border-slate-200 bg-slate-50 p-6 shadow-sm">
                    <p class="text-sm font-semibold uppercase tracking-[0.14em] text-slate-500">
                        {{ $page['class_details_title'] ?? 'Class Details' }}
                    </p>

                    <h2 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">
                        {{ $series->title }}
                    </h2>

                    @if($eventDetailItems->isNotEmpty())
                        <dl class="mt-5 grid gap-4 sm:grid-cols-3 lg:grid-cols-1">
                            @foreach($eventDetailItems as $item)
                                <div>
                                    <dt class="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                        {{ $item['label'] ?? '' }}
                                    </dt>
                                    <dd class="mt-1 font-semibold text-slate-900">
                                        {{ $item['resolved_value'] }}
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    @endif
                </div>
            </div>

            <div class="rounded-3xl border border-slate-200 bg-white p-6 shadow-xl shadow-slate-200/60 sm:p-8">
                @if(filled($page['questions_eyebrow'] ?? null))
                    <p class="text-sm font-semibold uppercase tracking-[0.14em] text-primary">
                        {{ $page['questions_eyebrow'] }}
                    </p>
                @endif

                @if(filled($page['questions_title'] ?? null))
                    <h2 class="mt-2 text-2xl font-bold tracking-tight text-slate-900">
                        {{ $page['questions_title'] }}
                    </h2>
                @endif

                @if(filled($page['questions_body'] ?? null))
                    <p class="mt-2 text-sm font-medium leading-6 text-slate-600">
                        {{ $page['questions_body'] }}
                    </p>
                @endif

                <form
                    method="POST"
                    action="{{ $formAction }}"
                    class="mt-6 space-y-6"
                >
                    @csrf

                    @foreach($questions as $question)
                        @php
                            $questionKey = $question['key'];
                            $answerPath = "registration_questions.{$questionKey}.answer";
                            $otherPath = "registration_questions.{$questionKey}.other";
                            $selectedAnswer = old($answerPath);
                            $other = is_array($question['other'] ?? null)
                                ? $question['other']
                                : null;
                        @endphp

                        <div
                            @if($question['type'] === \App\Modules\Webinars\Services\WebinarRegistrationQuestionResolver::TYPE_SELECT)
                                x-data="{ selectedAnswer: @js($selectedAnswer) }"
                            @endif
                            class="space-y-2"
                        >
                            <label
                                for="post_registration_question_{{ $questionKey }}"
                                class="block text-sm font-extrabold tracking-tight text-slate-900"
                            >
                                {{ $question['label'] }}
                                @if($question['required'])
                                    <span aria-hidden="true" class="text-red-600">*</span>
                                    <span class="sr-only">Required</span>
                                @endif
                            </label>

                            @if($question['type'] === \App\Modules\Webinars\Services\WebinarRegistrationQuestionResolver::TYPE_TEXTAREA)
                                <textarea
                                    id="post_registration_question_{{ $questionKey }}"
                                    name="registration_questions[{{ $questionKey }}][answer]"
                                    rows="5"
                                    maxlength="{{ $question['max_length'] }}"
                                    class="{{ $questionInputClass }} min-h-32 resize-y"
                                    placeholder="{{ $question['placeholder'] }}"
                                    @if($question['required'])
                                        required
                                        aria-required="true"
                                    @endif
                                    aria-invalid="{{ $errors->has($answerPath) ? 'true' : 'false' }}"
                                >{{ old($answerPath) }}</textarea>
                            @else
                                <select
                                    id="post_registration_question_{{ $questionKey }}"
                                    name="registration_questions[{{ $questionKey }}][answer]"
                                    x-model="selectedAnswer"
                                    class="{{ $questionInputClass }}"
                                    @if($question['required'])
                                        required
                                        aria-required="true"
                                    @endif
                                    aria-invalid="{{ $errors->has($answerPath) ? 'true' : 'false' }}"
                                >
                                    <option value="">{{ $question['placeholder'] }}</option>

                                    @foreach($question['options'] as $option)
                                        <option
                                            value="{{ $option['key'] }}"
                                            @selected($selectedAnswer === $option['key'])
                                        >
                                            {{ $option['label'] }}
                                        </option>
                                    @endforeach
                                </select>

                                @if($other !== null)
                                    <div
                                        x-cloak
                                        x-show="selectedAnswer === @js($other['option_key'])"
                                        x-transition.opacity
                                        class="space-y-2 pt-2"
                                    >
                                        <label
                                            for="post_registration_question_{{ $questionKey }}_other"
                                            class="block text-sm font-bold text-slate-900"
                                        >
                                            {{ $other['label'] }}
                                            @if($other['required'])
                                                <span aria-hidden="true" class="text-red-600">*</span>
                                                <span class="sr-only">Required</span>
                                            @endif
                                        </label>

                                        <textarea
                                            id="post_registration_question_{{ $questionKey }}_other"
                                            name="registration_questions[{{ $questionKey }}][other]"
                                            rows="3"
                                            maxlength="{{ $other['max_length'] }}"
                                            class="{{ $questionInputClass }} min-h-24 resize-y"
                                            placeholder="{{ $other['placeholder'] ?? '' }}"
                                            x-bind:required="selectedAnswer === @js($other['option_key']) && @js($other['required'])"
                                            aria-invalid="{{ $errors->has($otherPath) ? 'true' : 'false' }}"
                                        >{{ old($otherPath) }}</textarea>
                                    </div>
                                @endif
                            @endif

                            @if(filled($question['helper'] ?? null))
                                <p class="text-xs font-medium leading-5 text-slate-500">
                                    {{ $question['helper'] }}
                                </p>
                            @endif

                            @error($answerPath)
                                <p class="{{ $tokens['field_error'] ?? 'mt-1 text-sm text-red-600' }}">
                                    {{ $message }}
                                </p>
                            @enderror

                            @error($otherPath)
                                <p class="{{ $tokens['field_error'] ?? 'mt-1 text-sm text-red-600' }}">
                                    {{ $message }}
                                </p>
                            @enderror
                        </div>
                    @endforeach

                    <x-ui.button
                        type="submit"
                        class="{{ $tokens['primary_button'] ?? 'w-full' }}"
                    >
                        {{ $page['submit_label'] ?? 'Submit My Questions' }}
                    </x-ui.button>

                    @if(filled($page['helper_text'] ?? null))
                        <p class="text-center text-xs font-medium leading-5 text-slate-500">
                            {{ $page['helper_text'] }}
                        </p>
                    @endif
                </form>
            </div>
        </div>
    </section>
</x-layouts.public>