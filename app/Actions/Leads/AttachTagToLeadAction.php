<?php

namespace App\Actions\Leads;

use App\Models\Lead;
use App\Models\Tag;

class AttachTagToLeadAction
{
    public function execute(Lead $lead, string $tagSlug): void
    {
        $tag = Tag::query()
            ->where('slug', $tagSlug)
            ->where('is_active', true)
            ->firstOrFail();

        $lead->tags()->syncWithoutDetaching([$tag->id]);
    }
}