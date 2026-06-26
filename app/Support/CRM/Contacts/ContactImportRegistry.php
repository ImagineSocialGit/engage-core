<?php

namespace App\Support\CRM\Contacts;

use App\Contracts\CRM\Contacts\ContactImportHandler;
use App\Data\CRM\Contacts\ContactImportField;
use App\Models\Contact;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class ContactImportRegistry
{
    /**
     * @var array<string, ContactImportField>
     */
    private array $fields = [];

    /**
     * @var array<class-string<ContactImportHandler>>
     */
    private array $handlers = [];

    public function registerField(ContactImportField $field): self
    {
        $this->fields[$field->key] = $field;

        return $this;
    }

    /**
     * @param  array<int, ContactImportField>  $fields
     */
    public function registerFields(array $fields): self
    {
        foreach ($fields as $field) {
            $this->registerField($field);
        }

        return $this;
    }

    /**
     * @param  class-string<ContactImportHandler>  $handler
     */
    public function registerHandler(string $handler): self
    {
        if (! is_subclass_of($handler, ContactImportHandler::class)) {
            throw new InvalidArgumentException(
                $handler.' must implement '.ContactImportHandler::class.'.'
            );
        }

        $this->handlers[] = $handler;
        $this->handlers = array_values(array_unique($this->handlers));

        return $this;
    }

    /**
     * @return Collection<int, ContactImportField>
     */
    public function fields(): Collection
    {
        return collect($this->fields)
            ->sortBy([
                ['sort', 'asc'],
                ['label', 'asc'],
            ])
            ->values();
    }

    /**
     * @return Collection<int, array{label: string, fields: Collection<int, ContactImportField>}>
     */
    public function sections(): Collection
    {
        return $this->fields()
            ->groupBy(fn (ContactImportField $field): string => $field->section)
            ->map(fn (Collection $fields, string $section): array => [
                'label' => $section,
                'fields' => $fields->values(),
            ])
            ->values();
    }

    /**
     * @return array<int, string>
     */
    public function fieldKeys(): array
    {
        return $this->fields()
            ->pluck('key')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    public function requiredFieldKeys(): array
    {
        return $this->fields()
            ->filter(fn (ContactImportField $field): bool => $field->required)
            ->pluck('key')
            ->all();
    }

    /**
     * @return Collection<int, ContactImportField>
     */
    public function contactAttributeFields(): Collection
    {
        return $this->fields()
            ->filter(fn (ContactImportField $field): bool => $field->contactAttribute !== null)
            ->values();
    }

    public function mappedValue(array $row, array $mapping, string $field): ?string
    {
        $header = $mapping[$field] ?? null;

        if ($header === null || $header === '') {
            return null;
        }

        $value = $row[$header] ?? null;

        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    public function handleModuleImports(Contact $contact, array $row, array $mapping): void
    {
        foreach ($this->handlers as $handler) {
            app($handler)->handle(
                contact: $contact,
                row: $row,
                mapping: $mapping,
                mappedValue: fn (array $row, array $mapping, string $field): ?string => $this->mappedValue(
                    row: $row,
                    mapping: $mapping,
                    field: $field,
                ),
            );
        }
    }
}