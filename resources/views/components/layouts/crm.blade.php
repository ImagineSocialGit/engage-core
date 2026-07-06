<x-layouts.app :title="$title ?? config('app.name')" :meta-description="$metaDescription ?? null">
    <div class="min-h-screen bg-slate-50 text-slate-900">
        <div class="flex min-h-screen">
            <aside class="hidden w-64 border-r border-slate-200 bg-white lg:flex lg:flex-col">
                <div class="border-b border-slate-200 px-6 py-5">
                    <div class="text-lg font-semibold tracking-tight">
                        {{ config('app.name') }}
                    </div>
                    <div class="mt-1 text-xs uppercase tracking-wide text-slate-500">
                        CRM
                    </div>
                </div>

                <nav class="flex-1 space-y-1 px-4 py-4 text-sm">
                    <a href="/" class="block rounded-lg px-3 py-2 font-medium text-slate-700 transition hover:bg-slate-100">
                        Dashboard
                    </a>

                    <a
                        href="{{ route('crm.contacts.index') }}"
                        class="block rounded-lg px-3 py-2 font-medium text-slate-700 transition hover:bg-slate-100 capitalize"
                    >
                        {{ config('contacts.labels.plural') }}
                    </a>

                    @if(module_enabled('broadcasts'))
                        <a
                            href="{{ route('crm.broadcasts.index') }}"
                            class="block rounded-lg px-3 py-2 font-medium text-slate-700 transition hover:bg-slate-100"
                        >
                            Broadcasts
                        </a>
                    @endif

                    @if(module_enabled('webinars'))
                        <a href="/webinars" class="block rounded-lg px-3 py-2 font-medium text-slate-700 transition hover:bg-slate-100">
                            Webinars
                        </a>
                    @endif

                    @if(module_enabled('flow_routes'))
                        <a
                            href="{{ route('crm.flow-routes.bindings.index') }}"
                            class="block rounded-lg px-3 py-2 font-medium text-slate-700 transition hover:bg-slate-100"
                        >
                            Automatic Follow-ups
                        </a>
                    @endif

                    <form method="POST" action="/logout" class="">
                        @csrf

                        <div class="block">
                            <button
                                type="submit"
                                class="w-full rounded-lg px-3 py-2 text-left text-red-600 hover:text-red-300 transition hover:bg-slate-100 font-bold cursor-pointer"
                            >
                                Logout
                            </button>
                        </div>
                    </form>
                </nav>
            </aside>

            <div class="flex min-h-screen flex-1 flex-col">
                <header class="border-b border-slate-200 bg-white">
                    <div class="mx-auto flex w-full max-w-7xl items-center justify-between px-6 py-4">
                        <div>
                            <h1 class="text-lg font-semibold tracking-tight capitalize">
                                {{ $heading ?? ($title ?? 'CRM') }}
                            </h1>

                            @if(!empty($subheading ?? null))
                                <p class="mt-1 text-sm text-slate-500">
                                    {{ $subheading }}
                                </p>
                            @endif
                        </div>
                    </div>
                </header>

                <main class="flex-1">
                    <div class="mx-auto w-full max-w-375 px-6 py-8">
                        {{ $slot }}
                    </div>
                </main>
            </div>
        </div>
    </div>
</x-layouts.app>
