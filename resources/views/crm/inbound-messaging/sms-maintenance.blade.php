<x-layouts.crm
    title="Inbound Message Repair"
    heading="Inbound Message Repair"
    subheading="Reconnect older text messages after contact identity data has been cleaned up."
    module="inbound_messaging"
>
    <div class="space-y-6">
        @if(session('status'))
            <div class="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-900">
                {{ session('status') }}
            </div>
        @endif

        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
            <div class="flex flex-col gap-5 lg:flex-row lg:items-start lg:justify-between">
                <div class="max-w-3xl">
                    <p class="text-xs font-bold uppercase tracking-[0.14em] text-blue-700">Historical text messages</p>
                    <h2 class="mt-2 text-xl font-semibold tracking-tight text-slate-950">
                        Reconcile contact identity and reply history
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-slate-600">
                        This repairs stored sender links and recent outbound-message correlation. Historical ordinary replies do not trigger Routes, notifications, or automatic responses again. Historical STOP messages restore their original revocation timestamp so a later re-opt-in remains authoritative.
                    </p>
                </div>

                <form
                    method="POST"
                    action="{{ route('crm.inbound-messaging.sms-maintenance.reconcile') }}"
                    x-data
                    x-on:submit="if (!window.confirm('Reconcile older inbound text messages now?')) $event.preventDefault()"
                >
                    @csrf
                    <x-ui.button type="submit">
                        Reconcile inbound text messages
                    </x-ui.button>
                </form>
            </div>

            <dl class="mt-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div class="rounded-2xl bg-slate-50 px-4 py-3">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Unmatched text messages</dt>
                    <dd class="mt-1 text-2xl font-semibold text-slate-950">{{ number_format($preview['unmatched_sms']) }}</dd>
                </div>
                <div class="rounded-2xl bg-emerald-50 px-4 py-3">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-emerald-700">Resolvable now</dt>
                    <dd class="mt-1 text-2xl font-semibold text-emerald-950">{{ number_format($preview['resolvable']) }}</dd>
                </div>
                <div class="rounded-2xl bg-slate-50 px-4 py-3">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Still unresolved</dt>
                    <dd class="mt-1 text-2xl font-semibold text-slate-950">{{ number_format($preview['unresolved']) }}</dd>
                </div>
                <div class="rounded-2xl bg-slate-50 px-4 py-3">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Replies that can be correlated</dt>
                    <dd class="mt-1 text-2xl font-semibold text-slate-950">{{ number_format($preview['correlatable_normal_replies']) }}</dd>
                </div>
                <div class="rounded-2xl bg-slate-50 px-4 py-3">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">STOP messages to repair</dt>
                    <dd class="mt-1 text-2xl font-semibold text-slate-950">{{ number_format($preview['repairable_stop_messages']) }}</dd>
                </div>
                <div class="rounded-2xl bg-amber-50 px-4 py-3">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-amber-700">Manual-link conflicts</dt>
                    <dd class="mt-1 text-2xl font-semibold text-amber-950">{{ number_format($preview['manual_link_conflicts']) }}</dd>
                </div>
            </dl>
        </section>

        <div class="flex flex-wrap justify-between gap-3 text-sm">
            <a
                href="{{ route('crm.settings.contact-maintenance.index') }}"
                class="font-semibold text-slate-700 underline decoration-slate-300 underline-offset-4 hover:text-slate-950"
            >
                Contact maintenance
            </a>
            <a
                href="{{ route('crm.inbound-messaging.inbox.index') }}"
                class="font-semibold text-slate-700 underline decoration-slate-300 underline-offset-4 hover:text-slate-950"
            >
                Back to Inbox
            </a>
        </div>
    </div>
</x-layouts.crm>