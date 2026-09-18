<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Support\MessageAttachmentReferences;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

final class MessageAttachmentAuthoringService
{
    public function __construct(private readonly MessageAttachmentRegistry $registry) {}

    /** @return array<string, mixed> */
    public function validationRules(string $prefix = ''): array
    {
        $key = static fn (string $field): string => $prefix === '' ? $field : $prefix.'.'.$field;

        return [
            $key('attachments_present') => ['nullable', 'boolean'],
            $key('attachment_refs') => ['nullable', 'array', 'max:'.MessageAttachmentReferences::MAX_COUNT],
            $key('attachment_refs.*') => ['required', 'string', 'max:192', 'regex:/^[a-z][a-z0-9_]{0,63}:[a-zA-Z0-9._:-]{1,128}$/'],
            $key('attachment_upload_source') => ['nullable', 'string', 'regex:/^[a-z][a-z0-9_]{0,63}$/'],
            $key('attachment_upload') => [
                'nullable', 'file',
                'max:'.max(1, (int) floor(config('messaging.email.attachments.max_file_bytes', 10485760) / 1024)),
            ],
        ];
    }

    /** @param array<int, array<string, string>> $current
     *  @return array{options: array<int, array<string, mixed>>, selected: array<int, string>, upload_sources: array<int, array{key: string, label: string}>}
     */
    public function presentation(array $current = [], ?int $contactId = null): array
    {
        $selected = array_map(
            static fn (array $ref): string => $ref['source'].':'.$ref['id'],
            MessageAttachmentReferences::normalize($current),
        );
        $options = $this->registry->selectable($contactId);
        $known = array_fill_keys(array_map(
            static fn (array $option): string => $option['source'].':'.$option['id'],
            $options,
        ), true);

        foreach ($selected as $key) {
            if (isset($known[$key])) {
                continue;
            }
            [$source, $id] = explode(':', $key, 2);
            $options[] = [
                'source' => $source,
                'id' => $id,
                'filename' => 'Previously selected attachment (unavailable for new selection)',
                'size_bytes' => 0,
            ];
        }

        return [
            'options' => $options,
            'selected' => $selected,
            'upload_sources' => $this->registry->uploadableSources(),
        ];
    }

    /** @param array<string, mixed> $payload
     *  @param array<int, string> $values
     *  @return array<string, mixed>
     */
    public function apply(
        array $payload,
        array $values,
        ?int $contactId = null,
        ?UploadedFile $upload = null,
        ?string $uploadSource = null,
        ?Model $uploadedBy = null,
    ): array {
        $references = [];
        foreach ($values as $value) {
            if (! is_string($value) || ! str_contains($value, ':')) {
                throw new InvalidArgumentException('Choose a valid stored attachment.');
            }
            [$source, $id] = explode(':', $value, 2);
            $references[] = ['source' => $source, 'id' => $id];
        }

        $references = MessageAttachmentReferences::normalize($references);
        $selectable = [];
        foreach ($this->registry->selectable($contactId) as $option) {
            $selectable[$option['source'].':'.$option['id']] = true;
        }
        foreach ($references as $reference) {
            if (! isset($selectable[$reference['source'].':'.$reference['id']])) {
                throw new InvalidArgumentException('An attachment is unavailable for this message.');
            }
        }
        try {
            $files = $this->registry->resolve($references, $contactId);
            if ($upload instanceof UploadedFile) {
                $size = $upload->getSize();
                $maxFile = max(1, (int) config('messaging.email.attachments.max_file_bytes', 10485760));
                $maxTotal = max(1, (int) config('messaging.email.attachments.max_total_bytes', 15728640));
                $existingBytes = array_sum(array_map(
                    static fn ($file): int => $file->sizeBytes,
                    $files,
                ));
                if (! is_int($size) || $size < 1 || $size > $maxFile
                    || $existingBytes + $size > $maxTotal
                    || count($references) >= MessageAttachmentReferences::MAX_COUNT) {
                    throw new InvalidArgumentException('Email attachments exceed the configured size or count limit.');
                }

                $stored = $this->registry->upload(
                    trim((string) $uploadSource), $upload, $contactId, $uploadedBy,
                );
                $references[] = ['source' => $stored->source, 'id' => $stored->id];
                $references = MessageAttachmentReferences::normalize($references);
                $this->registry->resolve($references, $contactId);
            }
        } catch (\RuntimeException $exception) {
            throw new InvalidArgumentException($exception->getMessage(), previous: $exception);
        }

        if ($references === []) {
            unset($payload['attachments']);
        } else {
            $payload['attachments'] = $references;
        }

        return $payload;
    }
}