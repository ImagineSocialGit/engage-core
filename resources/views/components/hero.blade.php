<section class="py-20 text-center">
    <div class="max-w-4xl mx-auto px-4">
        <h1 class="text-4xl font-bold mb-4">
            {{ $title }}
        </h1>

        @isset($subtitle)
            <p class="text-lg text-gray-600">
                {{ $subtitle }}
            </p>
        @endisset

        {{ $slot }}
    </div>
</section>