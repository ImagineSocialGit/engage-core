@php
    $domain = $workspace['domain'];
    $routes = $workspace['routes'];
@endphp

<x-layouts.crm
    title="Inbound Addresses"
    heading="Inbound Addresses"
    subheading="Create separate email addresses so the Inbox can show why a message arrived."
    module="inbound_messaging"
>
    <div
        class="space-y-6"
        data-inbound-email-routes-workspace
        x-data="{ createOpen: @js($errors->any() && old('form_mode') === 'create') }"
        x-on:keydown.escape.window="createOpen = false"
    >
        @if(session('status'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900">
                {{ session('status') }}
            </div>
        @endif

        @if($errors->any())
            <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-4 text-sm text-red-900">
                <p class="font-semibold">Inbound Addresses was not changed.</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="flex flex-wrap items-center gap-2">
            <a
                href="{{ route('crm.inbound-messaging.inbox.index') }}"
                class="rounded-full border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:border-slate-300 hover:text-slate-950"
            >
                Inbox
            </a>
            <span class="rounded-full bg-slate-950 px-3 py-2 text-sm font-semibold text-white">
                Inbound addresses
            </span>
            <a
                href="{{ route('crm.inbound-messaging.reply-profiles.index') }}"
                class="rounded-full border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:border-slate-300 hover:text-slate-950"
            >
                Reply Handling
            </a>
        </div>

        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div class="max-w-3xl">
                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-blue-700">
                        Organize incoming email
                    </p>
                    <h2 class="mt-2 text-xl font-semibold tracking-tight text-slate-950">
                        Give different kinds of messages their own address
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        Name an address for the business reason it exists, such as Website Forms, Event Registrations, or Vendor Updates. Messages received there will carry that name into the Inbox.
                    </p>
                </div>

                <div class="flex shrink-0 items-center gap-3">
                    <div class="rounded-2xl bg-slate-100 px-4 py-3 text-center">
                        <p class="text-2xl font-semibold text-slate-950">
                            {{ $workspace['active_count'] }}
                        </p>
                        <p class="text-xs font-semibold text-slate-600">
                            Active addresses
                        </p>
                    </div>

                    <button
                        type="button"
                        class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800"
                        x-on:click="createOpen = !createOpen"
                    >
                        Add address
                    </button>
                </div>
            </div>

            <div class="mt-5 rounded-2xl border border-slate-200 bg-slate-50 p-4">
                <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">
                    Your receiving domain
                </p>

                @if($workspace['domain_ready'])
                    <p class="mt-1 break-all text-sm font-semibold text-slate-900">
                        {{ $domain }}
                    </p>
                    <p class="mt-2 text-xs leading-5 text-slate-500">
                        This is managed with the site's email/DNS setup and cannot be changed here.
                    </p>
                @else
                    <p class="mt-1 text-sm font-semibold text-amber-800">
                        A receiving domain has not been configured yet.
                    </p>
                    <p class="mt-2 text-xs leading-5 text-slate-500">
                        You can prepare addresses now, but they cannot receive mail until the receiving domain is configured.
                    </p>
                @endif
            </div>
        </section>

        <section
            x-cloak
            x-show="createOpen"
            class="rounded-3xl border border-blue-200 bg-blue-50/40 p-5 shadow-sm sm:p-7"
            data-inbound-email-route-create
        >
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold text-slate-950">
                        Add inbound address
                    </h2>
                    <p class="mt-1 text-sm text-slate-600">
                        Give it a plain-language name and choose the part before the @ sign.
                    </p>
                </div>

                <button
                    type="button"
                    class="text-sm font-semibold text-slate-600 hover:text-slate-950"
                    x-on:click="createOpen = false"
                >
                    Close
                </button>
            </div>

            <form
                method="POST"
                action="{{ route('crm.inbound-messaging.email-routes.store') }}"
                class="mt-5 grid gap-5 lg:grid-cols-2"
            >
                @csrf
                <input type="hidden" name="form_mode" value="create">

                <div>
                    <label for="route-label" class="block text-sm font-semibold text-slate-800">
                        Name
                    </label>
                    <input
                        id="route-label"
                        name="label"
                        type="text"
                        value="{{ old('label') }}"
                        placeholder="Website Forms"
                        class="mt-2 block w-full rounded-xl border-slate-300 text-sm shadow-sm"
                    >
                    <p class="mt-1 text-xs text-slate-500">
                        This is what people will see under “Received through” in the Inbox.
                    </p>
                </div>

                <div>
                    <label for="route-local-part" class="block text-sm font-semibold text-slate-800">
                        Email address
                    </label>
                    <div class="mt-2 flex rounded-xl shadow-sm">
                        <input
                            id="route-local-part"
                            name="local_part"
                            type="text"
                            value="{{ old('local_part') }}"
                            placeholder="website-forms"
                            autocomplete="off"
                            class="min-w-0 flex-1 rounded-l-xl border-slate-300 text-sm"
                        >
                        <span class="inline-flex items-center rounded-r-xl border border-l-0 border-slate-300 bg-slate-50 px-3 text-sm text-slate-500">
                            {{ $domain ? '@'.$domain : '@your-inbound-domain' }}
                        </span>
                    </div>
                </div>

                <div class="lg:col-span-2 lg:flex lg:justify-end">
                    <button
                        type="submit"
                        class="w-full rounded-xl bg-blue-700 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-600 lg:w-auto"
                    >
                        Create inbound address
                    </button>
                </div>
            </form>
        </section>

        <section class="space-y-4">
            @forelse($routes as $row)
                @php($route = $row['route'])

                <article
                    class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7"
                    data-inbound-email-route-editor
                >
                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2 class="text-lg font-semibold text-slate-950">
                                    {{ $route->label }}
                                </h2>
                                <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $route->is_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600' }}">
                                    {{ $route->is_active ? 'Active' : 'Disabled' }}
                                </span>
                            </div>

                            <p class="mt-2 break-all text-sm font-semibold text-slate-700">
                                {{ $row['address'] }}
                            </p>

                            <div class="mt-4 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                                <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">
                                    Inbox and business handling
                                </p>
                                <div class="mt-1 flex flex-wrap items-center gap-2">
                                    <p class="text-sm font-semibold {{ $row['handling']['status'] === 'problem' ? 'text-amber-800' : 'text-slate-900' }}">
                                        {{ $row['handling']['label'] }}
                                    </p>
                                </div>
                                <p class="mt-1 text-xs leading-5 text-slate-500">
                                    {{ $row['handling']['description'] }}
                                </p>
                            </div>

                            <div
                                class="mt-4 rounded-2xl border border-slate-200 bg-white px-4 py-4"
                                data-inbound-email-contact-extraction
                                x-data="{ open: @js($row['contact_extraction']['enabled'] || old('form_mode') === 'contact_extraction:'.$route->getKey() || (int) data_get(session('contact_extraction_test'), 'route_id') === (int) $route->getKey()), testOpen: false }"
                            >
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">
                                            Create or update a person
                                        </p>
                                        <p class="mt-1 text-sm font-semibold text-slate-900">
                                            {{ $row['contact_extraction']['status_label'] }}
                                        </p>
                                        <p class="mt-1 max-w-2xl text-xs leading-5 text-slate-500">
                                            {{ $row['contact_extraction']['description'] }}
                                        </p>
                                    </div>

                                    <button
                                        type="button"
                                        class="shrink-0 rounded-xl border border-slate-300 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 hover:border-slate-400 hover:text-slate-950"
                                        x-on:click="open = !open"
                                    >
                                        Configure
                                    </button>
                                </div>

                                <div
                                    x-cloak
                                    x-show="open"
                                    class="mt-4 border-t border-slate-200 pt-4"
                                >
                                    <form
                                        method="POST"
                                        action="{{ route('crm.inbound-messaging.email-routes.contact-extraction.update', $route) }}"
                                        class="space-y-4"
                                    >
                                        @csrf
                                        @method('PATCH')
                                        <input
                                            type="hidden"
                                            name="form_mode"
                                            value="contact_extraction:{{ $route->getKey() }}"
                                        >
                                        <input type="hidden" name="enabled" value="0">

                                        <label class="flex items-start gap-3 rounded-xl bg-slate-50 px-4 py-3">
                                            <input
                                                name="enabled"
                                                type="checkbox"
                                                value="1"
                                                class="mt-0.5 rounded border-slate-300 text-blue-700"
                                                @checked(
                                                    old('form_mode') === 'contact_extraction:'.$route->getKey()
                                                        ? old('enabled')
                                                        : $row['contact_extraction']['enabled']
                                                )
                                            >
                                            <span>
                                                <span class="block text-sm font-semibold text-slate-900">
                                                    Automatically create or update a person
                                                </span>
                                                <span class="mt-1 block text-xs leading-5 text-slate-500">
                                                    Engage extracts deterministic values from this email, resolves the person by email, links the Inbox message, then publishes the same inbound-address automation event with that person attached.
                                                </span>
                                            </span>
                                        </label>

                                        <div class="overflow-x-auto rounded-xl border border-slate-200">
                                            <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                                                <thead class="bg-slate-50">
                                                    <tr>
                                                        <th class="px-3 py-2.5 font-semibold text-slate-700">Person field</th>
                                                        <th class="px-3 py-2.5 font-semibold text-slate-700">Get value from</th>
                                                        <th class="px-3 py-2.5 font-semibold text-slate-700">Label before value</th>
                                                        <th class="px-3 py-2.5 font-semibold text-slate-700">Required</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="divide-y divide-slate-100 bg-white">
                                                    @foreach($row['contact_extraction']['targets'] as $target)
                                                        <tr>
                                                            <td class="whitespace-nowrap px-3 py-3 font-semibold text-slate-900">
                                                                {{ $target['label'] }}
                                                            </td>
                                                            <td class="min-w-52 px-3 py-3">
                                                                <select
                                                                    name="fields[{{ $target['key'] }}][source]"
                                                                    class="block w-full rounded-lg border-slate-300 text-sm"
                                                                >
                                                                    @foreach($target['source_options'] as $sourceValue => $sourceLabel)
                                                                        <option
                                                                            value="{{ $sourceValue }}"
                                                                            @selected(
                                                                                (
                                                                                    old('form_mode') === 'contact_extraction:'.$route->getKey()
                                                                                        ? old('fields.'.$target['key'].'.source')
                                                                                        : $target['source']
                                                                                ) === $sourceValue
                                                                            )
                                                                        >
                                                                            {{ $sourceLabel }}
                                                                        </option>
                                                                    @endforeach
                                                                </select>
                                                            </td>
                                                            <td class="min-w-52 px-3 py-3">
                                                                <input
                                                                    name="fields[{{ $target['key'] }}][label]"
                                                                    type="text"
                                                                    value="{{ old('form_mode') === 'contact_extraction:'.$route->getKey() ? old('fields.'.$target['key'].'.label') : $target['marker_label'] }}"
                                                                    placeholder="{{ $target['label'] }}"
                                                                    class="block w-full rounded-lg border-slate-300 text-sm"
                                                                >
                                                            </td>
                                                            <td class="px-3 py-3">
                                                                @if($target['key'] === 'email')
                                                                    <input type="hidden" name="required_fields[]" value="email">
                                                                    <span class="text-xs font-semibold text-slate-500">Always</span>
                                                                @else
                                                                    <input
                                                                        name="required_fields[]"
                                                                        type="checkbox"
                                                                        value="{{ $target['key'] }}"
                                                                        class="rounded border-slate-300 text-blue-700"
                                                                        @checked(
                                                                            old('form_mode') === 'contact_extraction:'.$route->getKey()
                                                                                ? in_array($target['key'], old('required_fields', []), true)
                                                                                : $target['required']
                                                                        )
                                                                    >
                                                                @endif
                                                            </td>
                                                        </tr>
                                                    @endforeach
                                                </tbody>
                                            </table>
                                        </div>

                                        <p class="text-xs leading-5 text-slate-500">
                                            “Body after a label” accepts either <strong>Label: value</strong> on one line or a label on one line followed by the value on the next non-empty line. Body text uses the provider's plain text when available and normalized HTML text otherwise.
                                        </p>

                                        <div class="flex flex-wrap items-center justify-between gap-3">
                                            <button
                                                type="button"
                                                class="text-sm font-semibold text-blue-700 hover:text-blue-900"
                                                x-on:click="testOpen = !testOpen"
                                            >
                                                Test with an example email
                                            </button>

                                            <button
                                                type="submit"
                                                class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800"
                                            >
                                                Save person extraction
                                            </button>
                                        </div>
                                    </form>

                                    <form
                                        x-cloak
                                        x-show="testOpen"
                                        method="POST"
                                        action="{{ route('crm.inbound-messaging.email-routes.contact-extraction.test', $route) }}"
                                        class="mt-4 grid gap-3 rounded-xl bg-slate-50 p-4 sm:grid-cols-2"
                                    >
                                        @csrf

                                        <div>
                                            <label class="block text-xs font-semibold text-slate-600">From</label>
                                            <input
                                                name="from"
                                                type="text"
                                                placeholder="Vendor &lt;notifications@example.com&gt;"
                                                class="mt-1 block w-full rounded-lg border-slate-300 text-sm"
                                            >
                                        </div>

                                        <div>
                                            <label class="block text-xs font-semibold text-slate-600">Reply-To</label>
                                            <input
                                                name="reply_to"
                                                type="text"
                                                placeholder="lead@example.com"
                                                class="mt-1 block w-full rounded-lg border-slate-300 text-sm"
                                            >
                                        </div>

                                        <div class="sm:col-span-2">
                                            <label class="block text-xs font-semibold text-slate-600">Subject</label>
                                            <input
                                                name="subject"
                                                type="text"
                                                class="mt-1 block w-full rounded-lg border-slate-300 text-sm"
                                            >
                                        </div>

                                        <div class="sm:col-span-2">
                                            <label class="block text-xs font-semibold text-slate-600">Body</label>
                                            <textarea
                                                name="body"
                                                rows="8"
                                                class="mt-1 block w-full rounded-lg border-slate-300 text-sm"
                                                placeholder="First Name: Jane&#10;Last Name: Doe&#10;Email: jane@example.com&#10;Phone: 555-555-1212"
                                            ></textarea>
                                        </div>

                                        <div class="sm:col-span-2">
                                            <button
                                                type="submit"
                                                class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:border-slate-400"
                                            >
                                                Test extraction
                                            </button>
                                        </div>
                                    </form>

                                    @if((int) data_get(session('contact_extraction_test'), 'route_id') === (int) $route->getKey())
                                        <div @class([
                                            'mt-4 rounded-xl border px-4 py-3 text-sm',
                                            'border-emerald-200 bg-emerald-50 text-emerald-900' => data_get(session('contact_extraction_test'), 'ok'),
                                            'border-amber-200 bg-amber-50 text-amber-900' => ! data_get(session('contact_extraction_test'), 'ok'),
                                        ])>
                                            <p class="font-semibold">
                                                {{ data_get(session('contact_extraction_test'), 'ok') ? 'Example matched.' : 'Example needs attention.' }}
                                            </p>

                                            @if(data_get(session('contact_extraction_test'), 'values', []) !== [])
                                                <dl class="mt-2 grid gap-2 sm:grid-cols-2">
                                                    @foreach(data_get(session('contact_extraction_test'), 'values', []) as $field => $value)
                                                        <div>
                                                            <dt class="text-xs font-bold uppercase tracking-wide opacity-70">
                                                                {{ \Illuminate\Support\Str::headline($field) }}
                                                            </dt>
                                                            <dd class="mt-0.5 break-words font-semibold">{{ $value }}</dd>
                                                        </div>
                                                    @endforeach
                                                </dl>
                                            @endif

                                            @if(data_get(session('contact_extraction_test'), 'errors', []) !== [])
                                                <ul class="mt-2 list-disc space-y-1 pl-5">
                                                    @foreach(data_get(session('contact_extraction_test'), 'errors', []) as $error)
                                                        <li>{{ $error }}</li>
                                                    @endforeach
                                                </ul>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </div>

                            <div
                                class="mt-4 rounded-2xl border border-slate-200 bg-white px-4 py-4"
                                data-inbound-email-route-automation
                            >
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div>
                                        <p class="text-xs font-bold uppercase tracking-[0.12em] text-slate-500">
                                            Automation after receipt
                                        </p>
                                        <p class="mt-1 text-xs leading-5 text-slate-500">
                                            The Inbox remains the human record. Flow Routes can continue work automatically when the inbound message is associated with a Contact.
                                        </p>
                                    </div>

                                    @if($row['automation']['create_url'])
                                        <a
                                            href="{{ $row['automation']['create_url'] }}"
                                            class="shrink-0 rounded-xl bg-blue-700 px-3.5 py-2 text-sm font-semibold text-white hover:bg-blue-600"
                                        >
                                            Automate after receipt
                                        </a>
                                    @endif
                                </div>

                                @if(! $row['automation']['available'])
                                    <p class="mt-3 text-sm text-slate-600">
                                        Enable Flow Routes to continue work automatically after email arrives here.
                                    </p>
                                @elseif($row['automation']['automations'] !== [])
                                    <div class="mt-3 space-y-2">
                                        @foreach($row['automation']['automations'] as $automation)
                                            <a
                                                href="{{ $automation['url'] }}"
                                                class="flex flex-col gap-1 rounded-xl border border-slate-200 bg-slate-50 px-3 py-3 hover:border-slate-300 sm:flex-row sm:items-center sm:justify-between"
                                            >
                                                <span class="min-w-0">
                                                    <span class="block truncate text-sm font-semibold text-slate-900">
                                                        {{ $automation['name'] }}
                                                    </span>
                                                    <span class="mt-0.5 block text-xs text-slate-500">
                                                        {{ $automation['scope'] === 'all_addresses' ? 'All inbound addresses' : 'This address' }}
                                                        · {{ $automation['step_count'] }} {{ $automation['step_label'] }}
                                                    </span>
                                                </span>
                                                <span class="text-xs font-semibold {{ $automation['is_enabled'] ? 'text-emerald-700' : 'text-slate-500' }}">
                                                    {{ $automation['is_enabled'] ? 'On' : 'Off' }}
                                                </span>
                                            </a>
                                        @endforeach
                                    </div>
                                @elseif(! $route->is_active)
                                    <p class="mt-3 text-sm text-slate-600">
                                        Enable this inbound address before creating a new automation for it.
                                    </p>
                                @else
                                    <p class="mt-3 text-sm text-slate-600">
                                        No Flow Route is connected to this address yet.
                                    </p>
                                @endif
                            </div>
                        </div>

                        <form
                            method="POST"
                            action="{{ route('crm.inbound-messaging.email-routes.state', $route) }}"
                            class="shrink-0"
                        >
                            @csrf
                            @method('PATCH')
                            <input
                                type="hidden"
                                name="is_active"
                                value="{{ $route->is_active ? '0' : '1' }}"
                            >

                            <button
                                type="submit"
                                class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:border-slate-400 hover:text-slate-950"
                            >
                                {{ $route->is_active ? 'Disable' : 'Enable' }}
                            </button>
                        </form>
                    </div>

                    <form
                        method="POST"
                        action="{{ route('crm.inbound-messaging.email-routes.update', $route) }}"
                        class="mt-6 grid gap-5 border-t border-slate-200 pt-5 lg:grid-cols-2"
                    >
                        @csrf
                        @method('PATCH')

                        <div>
                            <label
                                for="route-label-{{ $route->id }}"
                                class="block text-sm font-semibold text-slate-800"
                            >
                                Name
                            </label>
                            <input
                                id="route-label-{{ $route->id }}"
                                name="label"
                                type="text"
                                value="{{ $route->label }}"
                                class="mt-2 block w-full rounded-xl border-slate-300 text-sm shadow-sm"
                            >
                        </div>

                        <div>
                            <label
                                for="route-local-part-{{ $route->id }}"
                                class="block text-sm font-semibold text-slate-800"
                            >
                                Email address
                            </label>
                            <div class="mt-2 flex rounded-xl shadow-sm">
                                <input
                                    id="route-local-part-{{ $route->id }}"
                                    name="local_part"
                                    type="text"
                                    value="{{ $route->local_part }}"
                                    autocomplete="off"
                                    class="min-w-0 flex-1 rounded-l-xl border-slate-300 text-sm"
                                >
                                <span class="inline-flex items-center rounded-r-xl border border-l-0 border-slate-300 bg-slate-50 px-3 text-sm text-slate-500">
                                    {{ $domain ? '@'.$domain : '@your-inbound-domain' }}
                                </span>
                            </div>
                        </div>

                        <div class="lg:col-span-2 lg:flex lg:justify-end">
                            <button
                                type="submit"
                                class="w-full rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800 lg:w-auto"
                            >
                                Save changes
                            </button>
                        </div>
                    </form>
                </article>
            @empty
                <div class="rounded-3xl border border-dashed border-slate-300 bg-white p-8 text-center">
                    <h2 class="text-base font-semibold text-slate-900">
                        No inbound addresses yet
                    </h2>
                    <p class="mt-2 text-sm text-slate-500">
                        Add one when a website, vendor, or other external system needs its own recognizable place in the Inbox.
                    </p>
                </div>
            @endforelse
        </section>
    </div>
</x-layouts.crm>