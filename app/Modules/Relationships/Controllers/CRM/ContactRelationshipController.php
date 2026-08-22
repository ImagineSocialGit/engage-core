<?php

namespace App\Modules\Relationships\Controllers\CRM;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Contact;
use App\Modules\Relationships\Actions\UpsertContactRelationshipAction;
use App\Modules\Relationships\Models\ContactRelationship;
use App\Modules\Relationships\Services\RelationshipDefinitionRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContactRelationshipController extends Controller
{
    public function updateStage(
        Request $request,
        Contact $contact,
        ContactRelationship $contactRelationship,
        RelationshipDefinitionRegistry $definitions,
        UpsertContactRelationshipAction $upsertRelationship,
    ): RedirectResponse {
        abort_unless(
            (int) $contactRelationship->contact_id === (int) $contact->getKey()
                && $contactRelationship->is_active,
            404,
        );

        $definition = $definitions->get($contactRelationship->relationship_key);
        $stageKeys = array_keys($definition['stages']);

        if ($stageKeys === []) {
            return redirect()
                ->route('crm.contacts.show', $contact)
                ->with('error', 'This relationship does not use relationship stages.');
        }

        $validated = $request->validate([
            'stage_key' => [
                'required',
                'string',
                Rule::in($stageKeys),
            ],
        ]);

        $upsertRelationship->handle(
            contact: $contact,
            relationshipKey: $contactRelationship->relationship_key,
            stageKey: $validated['stage_key'],
        );

        return redirect()
            ->route('crm.contacts.show', $contact)
            ->with('success', $definition['singular'].' stage updated.');
    }
}