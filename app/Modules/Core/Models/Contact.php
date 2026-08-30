<?php

namespace App\Modules\Core\Models;

use Database\Factories\ContactFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Contact extends Model
{
    use HasFactory;

    protected static function newFactory(): ContactFactory
    {
        return ContactFactory::new();
    }

    protected $fillable = [
        'first_name',
        'last_name',
        'name',
        'email',
        'phone',
        'birthday',
        'source',
        'subsource',
        'contact_import_batch_id',
        'last_contacted_at',
        'last_activity_at',
        'meta',
    ];

    protected $casts = [
        'birthday' => 'date',
        'last_contacted_at' => 'datetime',
        'last_activity_at' => 'datetime',
        'meta' => 'array',
    ];

    /**
     * Resolve historical occurrence membership batch-first while retaining the
     * latest-import pointer as a compatibility source for pre-occurrence rows.
     *
     * @param Builder<Contact> $query
     * @param array<int, int|string> $batchIds
     * @return Builder<Contact>
     */
    public function scopeImportedInBatches(Builder $query, array $batchIds): Builder
    {
        $batchIds = array_values(array_unique(array_filter(array_map(
            static fn (int|string $batchId): int => (int) $batchId,
            $batchIds,
        ), static fn (int $batchId): bool => $batchId > 0)));

        if ($batchIds === []) {
            return $query->whereRaw('1 = 0');
        }

        $occurrenceContactIds = ContactImportOccurrence::query()
            ->select('contact_id')
            ->whereIn('contact_import_batch_id', $batchIds);

        $legacyContactIds = self::query()
            ->select('id')
            ->whereIn('contact_import_batch_id', $batchIds);

        return $query->whereIn(
            'contacts.id',
            $occurrenceContactIds->union($legacyContactIds),
        );
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(ContactImportBatch::class, 'contact_import_batch_id');
    }

    public function importOccurrences(): HasMany
    {
        return $this->hasMany(ContactImportOccurrence::class);
    }

    public function notes(): HasMany
    {
        return $this->hasMany(Note::class);
    }

    public function tags(): HasMany
    {
        return $this->hasMany(ContactTag::class);
    }
}