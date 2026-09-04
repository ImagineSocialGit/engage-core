@php
    $message = $workspace['message'];
    $presentation = $workspace['presentation'];
    $person = $workspace['person'];
    $contactSearch = $workspace['contact_search'];
    $defaults = $workspace['create_defaults'];
@endphp

<x-layouts.crm
    title="Inbox Message"
    heading="Inbox"
    subheading="Review the message, connect it to the right person when needed, and keep the Inbox current."
    module="inbound_messaging"
>
    <div class="space-y-6" data-inbound-inbox-detail>
        @if(session('status'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900">
                {{ session('status') }}
            </div>
        @endif

        @if($errors->any())
            <div class="rounded-2xl border border-red-200 bg-red-50 px-4 py-4 text-sm text-red-900">
                <p class="font-semibold">That change could not be saved.</p>
                <ul class="mt-2 list-disc space-y-1 pl-5">
                    @foreach($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="flex flex-wrap items-center justify-between gap-3">
            <a
                href="{{ route('crm.inbound-messaging.inbox.index') }}"
                class="text-sm font-semibold text-slate-600 hover:text-slate-950"
            >
                ← Back to Inbox
            </a>

            <div class="flex flex-wrap items-center gap-2">
                @if($message->inbox_status !== \App\Modules\InboundMessaging\Models\InboundMessage::INBOX_STATUS_NEW)
                    <form method="POST" action="{{ route('crm.inbound-messaging.inbox.state', $message) }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="inbox_status" value="new">
                        <button
                            type="submit"
                            class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:border-slate-400"
                        >
                            Move to needs review
                        </button>
                    </form>
                @endif

                @if($message->inbox_status !== \App\Modules\InboundMessaging\Models\InboundMessage::INBOX_STATUS_REVIEWED)
                    <form method="POST" action="{{ route('crm.inbound-messaging.inbox.state', $message) }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="inbox_status" value="reviewed">
                        <button
                            type="submit"
                            class="rounded-xl border border-blue-200 bg-blue-50 px-4 py-2 text-sm font-semibold text-blue-800 hover:bg-blue-100"
                        >
                            Mark in progress
                        </button>
                    </form>
                @endif

                @if($message->inbox_status !== \App\Modules\InboundMessaging\Models\InboundMessage::INBOX_STATUS_DONE)
                    <form method="POST" action="{{ route('crm.inbound-messaging.inbox.state', $message) }}">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="inbox_status" value="done">
                        <button
                            type="submit"
                            class="rounded-xl bg-slate-950 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800"
                        >
                            Mark done
                        </button>
                    </form>
                @endif
            </div>
        </div>

        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div class="min-w-0 max-w-3xl">
                    <div class="flex flex-wrap items-center gap-2">
                        <span @class([
                            'rounded-full px-2.5 py-1 text-xs font-semibold',
                            'bg-blue-50 text-blue-700' => $message->inbox_status === 'new',
                            'bg-slate-100 text-slate-700' => $message->inbox_status === 'reviewed',
                            'bg-emerald-50 text-emerald-700' => $message->inbox_status === 'done',
                        ])>
                            {{ $presentation['status_label'] }}
                        </span>
                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600">
                            {{ $presentation['channel_label'] }}
                        </span>
                    </div>

                    <h2 class="mt-4 text-xl font-semibold tracking-tight text-slate-950">
                        {{ $presentation['subject'] ?: 'Inbound message' }}
                    </h2>

                    <p class="mt-2 text-sm text-slate-600">
                        From <strong class="font-semibold text-slate-900">{{ $presentation['sender_label'] }}</strong>
                        · {{ $presentation['received_at_label'] }}
                    </p>
                </div>

                <dl class="grid shrink-0 gap-4 rounded-2xl bg-slate-50 p-4 text-sm sm:min-w-72">
                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-400">
                            Received through
                        </dt>
                        <dd class="mt-1 font-semibold text-slate-900">
                            {{ $presentation['received_through'] }}
                        </dd>
                    </div>

                    <div>
                        <dt class="text-xs font-bold uppercase tracking-wide text-slate-400">
                            Person
                        </dt>
                        <dd @class([
                            'mt-1 font-semibold',
                            'text-slate-900' => $person,
                            'text-amber-700' => ! $person,
                        ])>
                            {{ $presentation['person_label'] }}
                        </dd>
                    </div>
                </dl>
            </div>

            <div class="mt-6 border-t border-slate-200 pt-6">
                <p class="whitespace-pre-wrap break-words text-sm leading-7 text-slate-800">{{ $message->body ?: 'No message text was provided.' }}</p>
            </div>
        </section>

        @if($message->contact_extraction_status === \App\Modules\InboundMessaging\Models\InboundMessage::CONTACT_EXTRACTION_FAILED)
            <section class="rounded-3xl border border-amber-200 bg-amber-50 p-5 shadow-sm sm:p-7">
                <p class="text-xs font-bold uppercase tracking-[0.14em] text-amber-700">
                    Automatic person extraction
                </p>
                <h2 class="mt-2 text-lg font-semibold text-amber-950">
                    This email could not be matched to a person automatically
                </h2>
                <p class="mt-2 text-sm leading-6 text-amber-900">
                    {{ $message->contact_extraction_error ?: 'A required value was missing or invalid.' }}
                </p>
                <p class="mt-2 text-xs leading-5 text-amber-800">
                    The message is still safely available in the Inbox. Review the inbound address extraction rules before relying on automation for future messages.
                </p>
            </section>
        @endif

        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div>
                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-blue-700">
                        Related person
                    </p>
                    <h2 class="mt-2 text-lg font-semibold text-slate-950">
                        {{ $person ? $presentation['person_label'] : 'Not matched to a person' }}
                    </h2>
                    <p class="mt-1 max-w-2xl text-sm leading-6 text-slate-600">
                        The sender stays recorded exactly as received. Linking a person here only tells the CRM who this message is about.
                    </p>
                </div>

                @if($person)
                    <a
                        href="{{ route('crm.contacts.show', $person) }}"
                        class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:border-slate-400 hover:text-slate-950"
                    >
                        Open {{ config('contacts.labels.singular') }}
                    </a>
                @endif
            </div>

            @if($workspace['person_is_manual_link'])
                <form
                    method="POST"
                    action="{{ route('crm.inbound-messaging.inbox.person.unlink', $message) }}"
                    class="mt-5"
                >
                    @csrf
                    @method('DELETE')
                    <button
                        type="submit"
                        class="text-sm font-semibold text-red-700 hover:text-red-900"
                    >
                        Remove person link
                    </button>
                </form>
            @endif

            @if(!($message->sender instanceof \App\Modules\Core\Models\Contact))
                <div class="mt-6 grid gap-6 border-t border-slate-200 pt-6 xl:grid-cols-2">
                    <div>
                        <h3 class="text-sm font-semibold text-slate-950">
                            Link an existing {{ config('contacts.labels.singular') }}
                        </h3>

                        <form method="GET" action="{{ route('crm.inbound-messaging.inbox.show', $message) }}" class="mt-3 flex gap-2">
                            <input
                                name="contact_search"
                                type="search"
                                value="{{ request('contact_search') }}"
                                placeholder="Search by name, email, or phone"
                                class="block min-w-0 flex-1 rounded-xl border-slate-300 text-sm shadow-sm"
                            >
                            <button
                                type="submit"
                                class="rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:border-slate-400"
                            >
                                Search
                            </button>
                        </form>

                        @if(request()->filled('contact_search'))
                            <div class="mt-3 space-y-2">
                                @forelse($contactSearch as $result)
                                    <form
                                        method="POST"
                                        action="{{ route('crm.inbound-messaging.inbox.person.link', $message) }}"
                                        class="flex items-center justify-between gap-3 rounded-2xl border border-slate-200 px-4 py-3"
                                    >
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="contact_id" value="{{ $result['contact']->getKey() }}">

                                        <p class="min-w-0 text-sm text-slate-700">
                                            {{ $result['label'] }}
                                        </p>

                                        <button
                                            type="submit"
                                            class="shrink-0 rounded-lg bg-slate-950 px-3 py-2 text-xs font-semibold text-white"
                                        >
                                            Link
                                        </button>
                                    </form>
                                @empty
                                    <p class="rounded-2xl bg-slate-50 px-4 py-3 text-sm text-slate-500">
                                        No matching people found.
                                    </p>
                                @endforelse
                            </div>
                        @endif
                    </div>

                    <div>
                        <h3 class="text-sm font-semibold text-slate-950">
                            Create a new {{ config('contacts.labels.singular') }}
                        </h3>
                        <p class="mt-1 text-xs leading-5 text-slate-500">
                            An email address is required to create a new person.
                        </p>

                        <form
                            method="POST"
                            action="{{ route('crm.inbound-messaging.inbox.person.create', $message) }}"
                            class="mt-3 grid gap-3"
                        >
                            @csrf

                            <div>
                                <label class="block text-xs font-semibold text-slate-600">Name</label>
                                <input
                                    name="name"
                                    type="text"
                                    value="{{ old('name', $defaults['name']) }}"
                                    class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm"
                                >
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-slate-600">Email</label>
                                <input
                                    name="email"
                                    type="email"
                                    required
                                    value="{{ old('email', $defaults['email']) }}"
                                    class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm"
                                >
                            </div>

                            <div>
                                <label class="block text-xs font-semibold text-slate-600">Phone</label>
                                <input
                                    name="phone"
                                    type="text"
                                    value="{{ old('phone', $defaults['phone']) }}"
                                    class="mt-1 block w-full rounded-xl border-slate-300 text-sm shadow-sm"
                                >
                            </div>

                            <div>
                                <button
                                    type="submit"
                                    class="rounded-xl bg-slate-950 px-4 py-2.5 text-sm font-semibold text-white hover:bg-slate-800"
                                >
                                    Create and link person
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            @endif
        </section>
    </div>
</x-layouts.crm>