<?php

namespace Database\Seeders;

use App\Enums\TagType;
use App\Enums\WebinarTag;
use App\Models\Tag;
use Illuminate\Database\Seeder;

class TagSeeder extends Seeder
{
    public function run(): void
    {
        foreach (WebinarTag::cases() as $tag) {
            Tag::updateOrCreate(
                ['slug' => $tag->value],
                [
                    'name' => $tag->label(),
                    'type' => TagType::Webinar->value,
                    'is_active' => true,
                ]
            );
        }
    }
}