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