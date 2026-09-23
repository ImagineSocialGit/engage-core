<x-layouts.public-surface
    :title="$title"
    robots="index,follow"
    :primary-color="$form['branding']['primary_color']"
    :accent-color="$form['branding']['accent_color']"
>
    <style>
        [data-hosted-form-root] {
            --form-primary: #0f1630;
            --form-accent: #6da0d3;
        }

        [data-form-block][hidden] {
            display: none !important;
        }

        .hosted-form-section {
            background: linear-gradient(90deg, var(--form-primary), var(--form-accent));
        }

        .hosted-form-focus:focus {
            border-color: var(--form-accent);
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--form-accent) 24%, transparent);
            outline: none;
        }
    </style>

    <main
        class="mx-auto w-full max-w-[760px] px-4 py-8 sm:px-6 sm:py-12"
        data-hosted-form-root
        style="--form-primary: {{ $form['branding']['primary_color'] }}; --form-accent: {{ $form['branding']['accent_color'] }};"
    >
        @if(filled($form['branding']['logo_url']))
            <div class="mb-6 flex justify-center">
                <img
                    src="{{ $form['branding']['logo_url'] }}"
                    alt=""
                    class="max-h-28 max-w-48 object-contain"
                >
            </div>
        @endif

        <section class="overflow-hidden rounded-2xl border border-slate-300 bg-white shadow-sm">
            <header class="border-b border-slate-200 px-5 py-6 text-center sm:px-8">
                <h1 class="text-xl font-extrabold tracking-tight text-slate-950 sm:text-2xl">
                    {{ $form['name'] }}
                </h1>

                @if(filled($form['description']))
                    <p class="mx-auto mt-3 max-w-2xl text-sm leading-6 text-slate-600">
                        {{ $form['description'] }}
                    </p>
                @endif
            </header>

            @if($submitted)
                <div class="px-5 py-12 text-center sm:px-8 sm:py-16" data-form-success>
                    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-emerald-100 text-2xl text-emerald-700">
                        ✓
                    </div>
                    <h2 class="mt-5 text-2xl font-bold tracking-tight text-slate-950">
                        {{ $form['success']['heading'] }}
                    </h2>
                    <p class="mx-auto mt-3 max-w-xl text-sm leading-6 text-slate-600">
                        {{ $form['success']['message'] }}
                    </p>
                </div>
            @else
                <form
                    method="POST"
                    action="{{ route('forms.public.store', ['formSlug' => $form['slug']]) }}"
                    class="space-y-6 px-5 py-6 sm:px-8 sm:py-8"
                    data-hosted-form
                    novalidate
                >
                    @csrf

                    @foreach($form['hidden_fields'] as $field)
                        <input
                            type="hidden"
                            name="{{ $field['key'] }}"
                            value="{{ old($field['key'], $field['default'] ?? '') }}"
                        >
                    @endforeach

                    @if($errors->has('_verification'))
                        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                            {{ $errors->first('_verification') }}
                        </div>
                    @endif

                    @if($errors->has('_submission'))
                        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800" role="alert">
                            {{ $errors->first('_submission') }}
                        </div>
                    @endif

                    @foreach($form['blocks'] as $block)
                        @if($block['type'] === 'content')
                            <div data-form-block data-form-block-key="{{ $block['key'] }}">
                                @switch($block['variant'])
                                    @case('section')
                                        <div class="hosted-form-section rounded-md px-4 py-2.5 text-sm font-extrabold uppercase tracking-[0.08em] text-white">
                                            {{ $block['text'] }}
                                        </div>
                                        @break

                                    @case('subsection')
                                        <div class="rounded-md bg-sky-100 px-4 py-2 text-xs font-extrabold uppercase tracking-[0.08em] text-slate-800">
                                            {{ $block['text'] }}
                                        </div>
                                        @break

                                    @case('note')
                                        <div class="rounded-xl border-l-4 bg-slate-50 px-4 py-3 text-sm font-semibold leading-6 text-slate-700" style="border-left-color: var(--form-accent);">
                                            {{ $block['text'] }}
                                        </div>
                                        @break

                                    @case('callout')
                                        <div class="rounded-xl border border-sky-100 bg-sky-50 px-4 py-3 text-sm font-semibold leading-6 text-slate-700">
                                            {{ $block['text'] }}
                                        </div>
                                        @break

                                    @case('example')
                                        <div class="whitespace-pre-line rounded-xl border border-slate-200 bg-white px-4 py-4 text-sm leading-6 text-slate-700 shadow-sm">
                                            {{ $block['text'] }}
                                        </div>
                                        @break

                                    @default
                                        <p class="whitespace-pre-line text-sm leading-6 text-slate-600">
                                            {{ $block['text'] }}
                                        </p>
                                @endswitch
                            </div>
                        @else
                            <div
                                class="space-y-2"
                                data-form-block
                                data-form-block-key="{{ $block['key'] }}"
                                data-form-field-key="{{ $block['field']['key'] }}"
                                data-form-field-type="{{ $block['field']['type'] }}"
                            >
                                @if(in_array($block['field']['type'], ['radio', 'checkboxes'], true))
                                    <fieldset class="space-y-2">
                                        <legend class="text-sm font-semibold leading-6 text-slate-800">
                                            {{ $block['field']['label'] }}
                                            @if($block['field']['required'])
                                                <span class="text-sky-600" aria-hidden="true">*</span>
                                            @endif
                                        </legend>

                                        @if(filled($block['field']['help'] ?? null))
                                            <p class="text-xs leading-5 text-slate-500">{{ $block['field']['help'] }}</p>
                                        @endif

                                        <div class="space-y-2.5">
                                            @foreach($block['field']['options'] as $option)
                                                <label class="flex cursor-pointer items-start gap-2.5 text-sm leading-5 text-slate-700">
                                                    @if($block['field']['type'] === 'radio')
                                                        <input
                                                            type="radio"
                                                            name="{{ $block['field']['key'] }}"
                                                            value="{{ $option['value'] }}"
                                                            class="mt-0.5 h-4 w-4 border-slate-300 text-sky-600 focus:ring-sky-500"
                                                            @checked(old($block['field']['key']) === $option['value'])
                                                        >
                                                    @else
                                                        <input
                                                            type="checkbox"
                                                            name="{{ $block['field']['key'] }}[]"
                                                            value="{{ $option['value'] }}"
                                                            class="mt-0.5 h-4 w-4 rounded border-slate-300 text-sky-600 focus:ring-sky-500"
                                                            data-exclusive-option="{{ in_array($option['value'], $block['field']['exclusive_options'] ?? [], true) ? 'true' : 'false' }}"
                                                            @checked(collect(old($block['field']['key'], []))->contains($option['value']))
                                                        >
                                                    @endif
                                                    <span>{{ $option['label'] }}</span>
                                                </label>
                                            @endforeach
                                        </div>
                                    </fieldset>
                                @elseif($block['field']['type'] === 'select')
                                    <label class="block text-sm font-semibold leading-6 text-slate-800" for="field-{{ $block['field']['key'] }}">
                                        {{ $block['field']['label'] }}
                                        @if($block['field']['required'])
                                            <span class="text-sky-600" aria-hidden="true">*</span>
                                        @endif
                                    </label>
                                    <select
                                        id="field-{{ $block['field']['key'] }}"
                                        name="{{ $block['field']['key'] }}"
                                        class="hosted-form-focus block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm"
                                    >
                                        <option value="">{{ $block['field']['placeholder'] ?? 'Please select' }}</option>
                                        @foreach($block['field']['options'] as $option)
                                            <option
                                                value="{{ $option['value'] }}"
                                                @selected(old($block['field']['key']) === $option['value'])
                                            >
                                                {{ $option['label'] }}
                                            </option>
                                        @endforeach
                                    </select>
                                @elseif(in_array($block['field']['type'], ['checkbox', 'boolean'], true))
                                    <label class="flex cursor-pointer items-start gap-2.5 text-sm leading-5 text-slate-700">
                                        <input
                                            type="checkbox"
                                            name="{{ $block['field']['key'] }}"
                                            value="1"
                                            class="mt-0.5 h-4 w-4 rounded border-slate-300 text-sky-600 focus:ring-sky-500"
                                            @checked((bool) old($block['field']['key']))
                                        >
                                        <span>
                                            {{ $block['field']['label'] }}
                                            @if($block['field']['required'])
                                                <span class="text-sky-600" aria-hidden="true">*</span>
                                            @endif
                                        </span>
                                    </label>
                                @elseif($block['field']['type'] === 'textarea')
                                    <label class="block text-sm font-semibold leading-6 text-slate-800" for="field-{{ $block['field']['key'] }}">
                                        {{ $block['field']['label'] }}
                                        @if($block['field']['required'])
                                            <span class="text-sky-600" aria-hidden="true">*</span>
                                        @endif
                                    </label>
                                    <textarea
                                        id="field-{{ $block['field']['key'] }}"
                                        name="{{ $block['field']['key'] }}"
                                        rows="4"
                                        placeholder="{{ $block['field']['placeholder'] ?? '' }}"
                                        class="hosted-form-focus block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm"
                                    >{{ old($block['field']['key']) }}</textarea>
                                @else
                                    <label class="block text-sm font-semibold leading-6 text-slate-800" for="field-{{ $block['field']['key'] }}">
                                        {{ $block['field']['label'] }}
                                        @if($block['field']['required'])
                                            <span class="text-sky-600" aria-hidden="true">*</span>
                                        @endif
                                    </label>
                                    <input
                                        id="field-{{ $block['field']['key'] }}"
                                        type="{{ $block['field']['type'] === 'datetime' ? 'text' : $block['field']['type'] }}"
                                        name="{{ $block['field']['key'] }}"
                                        value="{{ old($block['field']['key']) }}"
                                        placeholder="{{ $block['field']['placeholder'] ?? '' }}"
                                        class="hosted-form-focus block w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm text-slate-900 shadow-sm"
                                        autocomplete="{{ $block['field']['type'] === 'email' ? 'email' : ($block['field']['type'] === 'tel' ? 'tel' : 'on') }}"
                                    >
                                @endif

                                @error($block['field']['key'])
                                    <p class="text-xs font-semibold text-red-700" role="alert">{{ $message }}</p>
                                @enderror
                            </div>
                        @endif
                    @endforeach

                    <div class="pt-2 text-center">
                        <button
                            type="submit"
                            class="rounded-md px-5 py-2.5 text-sm font-bold text-white shadow-sm transition hover:brightness-95 focus:outline-none focus:ring-2 focus:ring-offset-2"
                            style="background-color: var(--form-primary); --tw-ring-color: var(--form-accent);"
                        >
                            {{ $form['submit_label'] }}
                        </button>
                    </div>
                </form>
            @endif
        </section>
    </main>

    <x-public-surface.human-verification surface="forms" />

    @if(! $submitted)
        <script type="application/json" data-hosted-form-conditions>@json($form['conditions'])</script>
        <script>
            (() => {
                const form = document.querySelector('[data-hosted-form]')
                const conditionsElement = document.querySelector('[data-hosted-form-conditions]')

                if (!(form instanceof HTMLFormElement) || !conditionsElement) {
                    return
                }

                let conditions = []

                try {
                    conditions = JSON.parse(conditionsElement.textContent || '[]')
                } catch {
                    conditions = []
                }

                const block = (key) => form.querySelector(`[data-form-block-key="${CSS.escape(key)}"]`)
                const fieldBlock = (key) => form.querySelector(`[data-form-field-key="${CSS.escape(key)}"]`)

                const fieldValue = (key) => {
                    const root = fieldBlock(key)

                    if (!root || root.hidden) {
                        return null
                    }

                    const type = root.dataset.formFieldType || 'text'
                    const inputs = Array.from(root.querySelectorAll('input, select, textarea'))

                    if (type === 'checkboxes') {
                        return inputs
                            .filter((input) => input instanceof HTMLInputElement && input.checked)
                            .map((input) => input.value)
                    }

                    if (type === 'radio') {
                        const checked = inputs.find((input) => (
                            input instanceof HTMLInputElement && input.checked
                        ))

                        return checked ? checked.value : null
                    }

                    if (type === 'checkbox' || type === 'boolean') {
                        const checkbox = inputs.find((input) => input instanceof HTMLInputElement)

                        return checkbox ? checkbox.checked : false
                    }

                    const input = inputs[0]

                    if (!input) {
                        return null
                    }

                    const value = String(input.value || '').trim()

                    return value === '' ? null : value
                }

                const filled = (value) => {
                    if (value === null || value === '') {
                        return false
                    }

                    return !Array.isArray(value) || value.length > 0
                }

                const matchesPredicate = (predicate) => {
                    const controller = fieldBlock(predicate.field)

                    if (controller?.hidden) {
                        return false
                    }

                    const actual = fieldValue(predicate.field)

                    switch (predicate.operator) {
                        case 'equals':
                            return actual === predicate.value
                        case 'not_equals':
                            return actual !== predicate.value
                        case 'contains':
                            return Array.isArray(actual) && actual.includes(predicate.value)
                        case 'filled':
                            return filled(actual)
                        default:
                            return false
                    }
                }

                const conditionMatches = (condition) => {
                    const results = (condition.when || []).map(matchesPredicate)

                    return condition.match === 'any'
                        ? results.includes(true)
                        : !results.includes(false)
                }

                const clearInputs = (root) => {
                    root.querySelectorAll('input, select, textarea').forEach((input) => {
                        if (input instanceof HTMLInputElement) {
                            if (input.type === 'radio' || input.type === 'checkbox') {
                                input.checked = false
                            } else if (input.type !== 'hidden') {
                                input.value = ''
                            }
                        } else {
                            input.value = ''
                        }
                    })
                }

                const setBlockVisibility = (root, visible) => {
                    if (!root) {
                        return
                    }

                    const wasHidden = root.hidden
                    root.hidden = !visible
                    root.setAttribute('aria-hidden', visible ? 'false' : 'true')

                    root.querySelectorAll('input, select, textarea').forEach((input) => {
                        input.disabled = !visible
                    })

                    if (!visible && !wasHidden) {
                        clearInputs(root)
                    }
                }

                const applyConditions = () => {
                    const passes = Math.max(1, conditions.length + 1)

                    for (let pass = 0; pass < passes; pass += 1) {
                        conditions.forEach((condition) => {
                            const visible = conditionMatches(condition)

                            ;(condition.targets || []).forEach((target) => {
                                setBlockVisibility(block(target), visible)
                            })
                        })
                    }
                }

                const applyExclusiveOption = (changed) => {
                    if (!(changed instanceof HTMLInputElement)
                        || changed.type !== 'checkbox'
                        || !changed.name.endsWith('[]')
                        || !changed.checked
                    ) {
                        return
                    }

                    const root = changed.closest('[data-form-field-type="checkboxes"]')

                    if (!root) {
                        return
                    }

                    const checkboxes = Array.from(root.querySelectorAll('input[type="checkbox"]'))
                    const isExclusive = changed.dataset.exclusiveOption === 'true'

                    checkboxes.forEach((checkbox) => {
                        if (checkbox === changed) {
                            return
                        }

                        if (isExclusive || checkbox.dataset.exclusiveOption === 'true') {
                            checkbox.checked = false
                        }
                    })
                }

                form.addEventListener('change', (event) => {
                    applyExclusiveOption(event.target)
                    applyConditions()
                })

                applyConditions()
            })()
        </script>
    @endif
</x-layouts.public-surface>