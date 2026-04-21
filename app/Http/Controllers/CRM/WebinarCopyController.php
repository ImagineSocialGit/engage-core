<?php

namespace App\Http\Controllers\CRM;

use App\Actions\Webinars\CreateWebinarCopiesAction;
use App\Http\Controllers\Controller;
use App\Models\Webinar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WebinarCopyController extends Controller
{
    public function __construct(
        protected CreateWebinarCopiesAction $createWebinarCopiesAction,
    ) {}

    public function create(Webinar $webinar): View
    {
        return view('crm.webinars.copies.create', [
            'title' => 'Create Webinar Copies',
            'heading' => 'Create Webinar Copies',
            'subheading' => 'Create one or more scheduled copies from an existing webinar.',
            'webinar' => $webinar,
        ]);
    }

    public function store(Request $request, Webinar $webinar): RedirectResponse
    {
        $validated = $request->validate([
            'copies' => ['required', 'array', 'min:1'],
            'copies.*.starts_at' => ['required', 'date'],
            'copies.*.external_id' => ['required', 'string', 'max:255'],
            'copies.*.ends_at' => ['nullable', 'date'],
            'copies.*.title' => ['nullable', 'string', 'max:255'],
            'copies.*.slug' => ['nullable', 'string', 'max:255'],
        ]);

        $copies = collect($validated['copies'])
            ->map(function (array $copy) {
                $copy['external_id'] = preg_replace('/\D/', '', (string) $copy['external_id']);
                $copy['slug'] = filled($copy['slug'] ?? null) ? \Illuminate\Support\Str::slug($copy['slug']) : null;

                return $copy;
            })
            ->values()
            ->all();

        $errors = array_merge(
            $this->validateUniquenessWithinRequest($copies),
            $this->validateDatabaseUniqueness($copies),
        );

        if (! empty($errors)) {
            return back()->withErrors($errors)->withInput();
        }

        $created = $this->createWebinarCopiesAction->execute($webinar, $copies);

        return redirect()
            ->route('crm.webinar.copies.create', $webinar)
            ->with('status', "{$created->count()} webinar copie(s) created successfully.");
    }

    protected function validateUniquenessWithinRequest(array $copies): array
    {
        $errors = [];

        $externalIds = collect($copies)->pluck('external_id')->filter();
        $slugs = collect($copies)->pluck('slug')->filter();

        if ($externalIds->count() !== $externalIds->unique()->count()) {
            $errors['copies'] = 'Duplicate external IDs were submitted.';
        }

        if ($slugs->count() !== $slugs->unique()->count()) {
            $errors['copies'] = ($errors['copies'] ?? '') . ' Duplicate slugs were submitted.';
        }

        return $errors;
    }

    protected function validateDatabaseUniqueness(array $copies): array
    {
        $errors = [];

        $externalIds = collect($copies)->pluck('external_id')->filter()->all();
        $slugs = collect($copies)->pluck('slug')->filter()->all();

        if (! empty($externalIds) && Webinar::query()->whereIn('external_id', $externalIds)->exists()) {
            $errors['copies'] = 'One or more external IDs already exist.';
        }

        if (! empty($slugs) && Webinar::query()->whereIn('slug', $slugs)->exists()) {
            $errors['copies'] = ($errors['copies'] ?? '') . ' One or more slugs already exist.';
        }

        return $errors;
    }
}