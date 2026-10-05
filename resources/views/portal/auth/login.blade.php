<x-layouts.portal :presentation="$presentation">
    <div class="mx-auto max-w-md rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
        <h1 class="text-2xl font-bold tracking-tight text-slate-950">Sign in</h1>

        @if(session('status'))
            <div class="mt-5 rounded-xl bg-emerald-50 px-4 py-3 text-sm text-emerald-800">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('portal.login.store') }}" class="mt-6 space-y-5">
            @csrf

            <label class="block">
                <span class="text-sm font-semibold text-slate-700">Email</span>
                <input type="email" name="email" value="{{ old('email') }}" autocomplete="email" required class="mt-2 w-full rounded-xl border border-slate-300 px-3 py-2.5">
                @error('email')<span class="mt-1 block text-sm text-red-700">{{ $message }}</span>@enderror
            </label>

            <label class="block">
                <span class="text-sm font-semibold text-slate-700">Password</span>
                <input type="password" name="password" autocomplete="current-password" required class="mt-2 w-full rounded-xl border border-slate-300 px-3 py-2.5">
                @error('password')<span class="mt-1 block text-sm text-red-700">{{ $message }}</span>@enderror
            </label>

            <label class="flex items-center gap-2 text-sm text-slate-600">
                <input type="checkbox" name="remember" value="1" class="rounded border-slate-300">
                <span>Keep me signed in</span>
            </label>

            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-full bg-[var(--public-primary)] px-5 py-2.5 text-sm font-extrabold text-white hover:brightness-95">
                Sign in
            </button>
        </form>

        @if($passwordResetAvailable)
            <a href="{{ route('portal.password.request.form') }}" class="mt-5 inline-flex text-sm font-semibold text-slate-600 hover:text-slate-950">
                Forgot your password?
            </a>
        @endif
    </div>
</x-layouts.portal>