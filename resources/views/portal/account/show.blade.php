<x-layouts.portal :presentation="$presentation" :navigation="$navigation" :portal-user="$portalUser">
    <div class="mx-auto max-w-2xl rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
        <h1 class="text-2xl font-bold tracking-tight text-slate-950">Account settings</h1>

        <div class="mt-6 rounded-2xl bg-slate-50 p-4">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Login email</p>
            <p class="mt-1 text-sm font-medium text-slate-900">{{ $portalUser->email }}</p>
        </div>

        <form method="POST" action="{{ route('portal.account.update') }}" class="mt-6 space-y-5">
            @csrf
            @method('PATCH')

            <label class="block">
                <span class="text-sm font-semibold text-slate-700">Name</span>
                <input type="text" name="name" value="{{ old('name', $portalUser->name) }}" autocomplete="name" required class="mt-2 w-full rounded-xl border border-slate-300 px-3 py-2.5">
                @error('name')<span class="mt-1 block text-sm text-red-700">{{ $message }}</span>@enderror
            </label>

            <label class="block">
                <span class="text-sm font-semibold text-slate-700">Phone</span>
                <input type="tel" name="phone" value="{{ old('phone', $portalUser->phone) }}" autocomplete="tel" class="mt-2 w-full rounded-xl border border-slate-300 px-3 py-2.5">
                @error('phone')<span class="mt-1 block text-sm text-red-700">{{ $message }}</span>@enderror
            </label>

            <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-full bg-[var(--public-primary)] px-5 py-2.5 text-sm font-extrabold text-white hover:brightness-95">
                Save changes
            </button>
        </form>
    </div>
</x-layouts.portal>