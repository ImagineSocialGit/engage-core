<x-layouts.portal :presentation="$presentation">
    <div class="mx-auto max-w-lg rounded-3xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8">
        <h1 class="text-2xl font-bold tracking-tight text-slate-950">Activate your account</h1>

        @error('invitation')
            <div class="mt-4 rounded-xl border border-red-200 bg-red-50 p-3 text-sm text-red-800">{{ $message }}</div>
        @enderror

        <form method="POST" action="{{ route('portal.invitations.accept.store', ['invitation' => $invitation, 'token' => $token]) }}" class="mt-6 space-y-5">
            @csrf

            <label class="block">
                <span class="text-sm font-semibold text-slate-700">Name</span>
                <input type="text" name="name" value="{{ old('name') }}" autocomplete="name" required class="mt-2 w-full rounded-xl border border-slate-300 px-3 py-2.5">
                @error('name')<span class="mt-1 block text-sm text-red-700">{{ $message }}</span>@enderror
            </label>

            @if($emailLocked)
                <div>
                    <span class="text-sm font-semibold text-slate-700">Email</span>
                    <p class="mt-2 text-sm text-slate-600">{{ $invitation->email }}</p>
                </div>
            @else
                <label class="block">
                    <span class="text-sm font-semibold text-slate-700">Email</span>
                    <input type="email" name="email" value="{{ old('email') }}" autocomplete="email" required class="mt-2 w-full rounded-xl border border-slate-300 px-3 py-2.5">
                    @error('email')<span class="mt-1 block text-sm text-red-700">{{ $message }}</span>@enderror
                </label>
            @endif

            <label class="block">
                <span class="text-sm font-semibold text-slate-700">Phone</span>
                <input type="tel" name="phone" value="{{ old('phone', $invitation->phone) }}" autocomplete="tel" class="mt-2 w-full rounded-xl border border-slate-300 px-3 py-2.5">
                @error('phone')<span class="mt-1 block text-sm text-red-700">{{ $message }}</span>@enderror
            </label>

            <label class="block">
                <span class="text-sm font-semibold text-slate-700">Password</span>
                <input type="password" name="password" autocomplete="new-password" required class="mt-2 w-full rounded-xl border border-slate-300 px-3 py-2.5">
                @error('password')<span class="mt-1 block text-sm text-red-700">{{ $message }}</span>@enderror
            </label>

            <label class="block">
                <span class="text-sm font-semibold text-slate-700">Confirm password</span>
                <input type="password" name="password_confirmation" autocomplete="new-password" required class="mt-2 w-full rounded-xl border border-slate-300 px-3 py-2.5">
            </label>

            <button type="submit" class="inline-flex min-h-11 w-full items-center justify-center rounded-full bg-[var(--public-primary)] px-5 py-2.5 text-sm font-extrabold text-white hover:brightness-95">
                Activate account
            </button>
        </form>
    </div>
</x-layouts.portal>