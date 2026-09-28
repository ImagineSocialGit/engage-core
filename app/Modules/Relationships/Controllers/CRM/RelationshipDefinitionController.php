<?php

namespace App\Modules\Relationships\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Relationships\Models\RelationshipDefinition;
use App\Modules\Relationships\Models\RelationshipStageDefinition;
use App\Modules\Relationships\Services\RelationshipDefinitionRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RelationshipDefinitionController extends Controller
{
    public function index(RelationshipDefinitionRegistry $definitions): View
    {
        return view('crm.relationships.definitions', [
            'definitions' => $definitions->all(),
        ]);
    }

    public function storeType(Request $request, RelationshipDefinitionRegistry $definitions): RedirectResponse
    {
        $values = $this->typeValues($request, true);

        if ($definitions->has($values['key'])) {
            throw ValidationException::withMessages(['key' => 'That relationship key already exists.']);
        }

        RelationshipDefinition::query()->create($values);

        return $this->done();
    }

    public function updateType(
        Request $request,
        string $relationshipKey,
        RelationshipDefinitionRegistry $definitions,
    ): RedirectResponse {
        $existing = $definitions->all()[$relationshipKey] ?? null;
        abort_unless($existing !== null, 404);

        $values = $this->typeValues($request, false);
        RelationshipDefinition::query()->updateOrCreate(
            ['key' => $relationshipKey],
            $values,
        );

        return $this->done();
    }

    public function storeStage(
        Request $request,
        string $relationshipKey,
        RelationshipDefinitionRegistry $definitions,
    ): RedirectResponse {
        $existing = $definitions->all()[$relationshipKey] ?? null;
        abort_unless($existing !== null, 404);

        $values = $this->stageValues($request, true);

        if (isset($existing['stages'][$values['key']])) {
            throw ValidationException::withMessages(['key' => 'That stage key already exists.']);
        }

        $this->persistType($relationshipKey, $existing);
        RelationshipStageDefinition::query()->create([
            ...$values,
            'relationship_key' => $relationshipKey,
        ]);

        return $this->done();
    }

    public function updateStage(
        Request $request,
        string $relationshipKey,
        string $stageKey,
        RelationshipDefinitionRegistry $definitions,
    ): RedirectResponse {
        $existing = $definitions->all()[$relationshipKey] ?? null;
        abort_unless($existing !== null && isset($existing['stages'][$stageKey]), 404);

        $values = $this->stageValues($request, false);
        $this->persistType($relationshipKey, $existing);
        RelationshipStageDefinition::query()->updateOrCreate(
            ['relationship_key' => $relationshipKey, 'key' => $stageKey],
            $values,
        );

        return $this->done();
    }

    private function typeValues(Request $request, bool $creating): array
    {
        $rules = [
            'singular' => ['required', 'string', 'max:120'],
            'plural' => ['required', 'string', 'max:120'],
            'visible' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'between:-100000,100000'],
        ];

        if ($creating) {
            $rules['key'] = ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:_[a-z0-9]+)*$/'];
        }

        return $request->validate($rules);
    }

    private function stageValues(Request $request, bool $creating): array
    {
        $rules = [
            'label' => ['required', 'string', 'max:120'],
            'sort_order' => ['required', 'integer', 'between:-100000,100000'],
            'active' => ['required', 'boolean'],
        ];

        if ($creating) {
            $rules['key'] = ['required', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:_[a-z0-9]+)*$/'];
        }

        return $request->validate($rules);
    }

    private function persistType(string $key, array $definition): void
    {
        RelationshipDefinition::query()->firstOrCreate(
            ['key' => $key],
            [
                'singular' => $definition['singular'],
                'plural' => $definition['plural'],
                'visible' => $definition['visible'],
                'sort_order' => $definition['sort_order'],
            ],
        );
    }

    private function done(): RedirectResponse
    {
        return redirect()->route('crm.relationships.definitions.index')
            ->with('success', 'Relationship definitions saved.');
    }
}