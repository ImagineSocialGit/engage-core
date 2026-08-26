<x-layouts.crm
    title="Settings & setup"
    heading="Settings & setup"
    subheading="Find shared choices you may want to change later. Everyday setup still stays inside the part of the CRM where you use it."
    module="core"
>
    <div class="mx-auto max-w-6xl space-y-8">
        <section class="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
            <div class="max-w-3xl">
                <p class="text-xs font-bold uppercase tracking-[0.18em] text-slate-500">New here?</p>
                <h2 class="mt-2 text-xl font-semibold tracking-tight text-slate-950">
                    A few useful places to start
                </h2>
                <p class="mt-2 text-sm leading-6 text-slate-700">
                    You do not need to configure everything before using the CRM. These links help you learn the basic shape of the platform, and each workspace will guide you when it needs something else.
                </p>
            </div>

            <div class="mt-5 grid gap-3 lg:grid-cols-3">
                @foreach($gettingStarted as $item)
                    <a
                        href="{{ $item['href'] }}"
                        class="rounded-2xl p-4 ring-1 transition hover:-translate-y-0.5 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 {{ module_tone($item['module'], 'item') }}"
                    >
                        <span class="block font-semibold text-slate-950">{{ $item['label'] }}</span>
                        <span class="mt-2 block text-sm leading-6 text-slate-700">{{ $item['description'] }}</span>
                    </a>
                @endforeach
            </div>
        </section>

        <div class="space-y-7">
            @foreach($settingsGroups as $group)
                <section id="settings-{{ $group['key'] }}" class="scroll-mt-6">
                    <div class="max-w-3xl">
                        <h2 class="text-lg font-semibold tracking-tight text-slate-950">
                            {{ $group['label'] }}
                        </h2>
                        <p class="mt-1 text-sm leading-6 text-slate-700">
                            {{ $group['description'] }}
                        </p>
                    </div>

                    <div class="mt-4 grid gap-4 md:grid-cols-2">
                        @foreach($group['items'] as $item)
                            <a
                                href="{{ $item['href'] }}"
                                class="group rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-slate-400"
                            >
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <h3 class="font-semibold text-slate-950">{{ $item['label'] }}</h3>
                                        <p class="mt-2 text-sm leading-6 text-slate-700">{{ $item['description'] }}</p>
                                    </div>

                                    <span class="mt-0.5 text-lg text-slate-400 transition group-hover:translate-x-0.5 group-hover:text-slate-700" aria-hidden="true">→</span>
                                </div>
                            </a>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
    </div>
</x-layouts.crm>