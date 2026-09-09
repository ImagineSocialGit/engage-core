<div @class([
    'mt-4 rounded-xl border px-4 py-3 text-sm',
    'border-emerald-200 bg-emerald-50 text-emerald-900' => data_get($result, 'ok'),
    'border-amber-200 bg-amber-50 text-amber-900' => ! data_get($result, 'ok'),
])>
    <p class="font-semibold">
        {{ data_get($result, 'ok') ? $matchedLabel : $attentionLabel }}
    </p>

    @if(data_get($result, 'values', []) !== [])
        <dl class="mt-2 grid gap-2 sm:grid-cols-2">
            @foreach(data_get($result, 'values', []) as $field => $value)
                <div>
                    <dt class="text-xs font-bold uppercase tracking-wide opacity-70">
                        {{ \Illuminate\Support\Str::headline($field) }}
                    </dt>
                    <dd class="mt-0.5 break-words font-semibold">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    @endif

    @if(data_get($result, 'errors', []) !== [])
        <ul class="mt-2 list-disc space-y-1 pl-5">
            @foreach(data_get($result, 'errors', []) as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif
</div>