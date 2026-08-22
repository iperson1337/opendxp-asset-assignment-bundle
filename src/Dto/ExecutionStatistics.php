<?php

namespace Iperson1337\AssetAssignmentBundle\Dto;

/**
 * DTO for execution statistics
 */
final class ExecutionStatistics
{
    private int $processed = 0;
    private int $createdFolders = 0;
    private int $movedFiles = 0;
    private int $attachedFiles = 0;
    private int $unmatchedFiles = 0;
    private array $errors = [];

    public function getProcessed(): int
    {
        return $this->processed;
    }

    public function getCreatedFolders(): int
    {
        return $this->createdFolders;
    }

    public function getMovedFiles(): int
    {
        return $this->movedFiles;
    }

    public function getAttachedFiles(): int
    {
        return $this->attachedFiles;
    }

    public function getUnmatchedFiles(): int
    {
        return $this->unmatchedFiles;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getErrorsCount(): int
    {
        return count($this->errors);
    }

    public function incrementProcessed(): void
    {
        $this->processed++;
    }

    public function incrementCreatedFolders(): void
    {
        $this->createdFolders++;
    }

    public function incrementMovedFiles(): void
    {
        $this->movedFiles++;
    }

    public function incrementAttachedFiles(): void
    {
        $this->attachedFiles++;
    }

    public function incrementUnmatchedFiles(int $count = 1): void
    {
        $this->unmatchedFiles += $count;
    }

    public function addError(string $error): void
    {
        $this->errors[] = $error;
    }

    public function toArray(): array
    {
        return [
            'processed' => $this->processed,
            'created_folders' => $this->createdFolders,
            'moved_files' => $this->movedFiles,
            'attached_files' => $this->attachedFiles,
            'unmatched_files' => $this->unmatchedFiles,
            'errors' => $this->getErrorsCount(),
        ];
    }
}

