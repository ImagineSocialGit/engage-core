<?php

namespace App\Support\ProjectState;

class ProjectStateManager
{
    public function __construct(
        private readonly ProjectStateExporter $exporter,
        private readonly ProjectStateImporter $importer,
        private readonly ProjectStateDocumentCodec $documentCodec,
        private readonly ProjectStateDocumentValidator $documentValidator,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function export(): array
    {
        return $this->exporter->export();
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public function validate(array $document): array
    {
        return $this->documentValidator->validate($document);
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public function import(array $document): array
    {
        return $this->importer->import($document);
    }

    /**
     * @param array<string, mixed> $document
     */
    public function encode(array $document): string
    {
        return $this->documentCodec->encode($document);
    }

    /**
     * @return array<string, mixed>
     */
    public function decode(string $json): array
    {
        return $this->documentCodec->decode($json);
    }
}