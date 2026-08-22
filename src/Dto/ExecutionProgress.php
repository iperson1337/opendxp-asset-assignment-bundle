<?php

namespace Iperson1337\AssetAssignmentBundle\Dto;

/**
 * DTO for execution progress
 */
final readonly class ExecutionProgress
{
    public function __construct(
        public int $processed,
        public int $total,
        public int $movedFiles,
        public int $attachedFiles,
        public bool $isRunning,
        public ?\DateTimeImmutable $startedAt = null
    ) {}
    
    /**
     * Create from array
     * 
     * @param array{
     *     processed: int,
     *     total: int,
     *     movedFiles: int,
     *     attachedFiles: int,
     *     isRunning: bool,
     *     startedAt?: string|null
     * } $data
     */
    public static function fromArray(array $data): self
    {
        $startedAt = null;
        if (isset($data['startedAt']) && is_string($data['startedAt'])) {
            $startedAt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $data['startedAt']) ?: null;
        }
        
        return new self(
            $data['processed'],
            $data['total'],
            $data['movedFiles'],
            $data['attachedFiles'],
            $data['isRunning'],
            $startedAt
        );
    }
    
    /**
     * Convert to array
     * 
     * @return array{
     *     processed: int,
     *     total: int,
     *     movedFiles: int,
     *     attachedFiles: int,
     *     isRunning: bool,
     *     startedAt: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'processed' => $this->processed,
            'total' => $this->total,
            'movedFiles' => $this->movedFiles,
            'attachedFiles' => $this->attachedFiles,
            'isRunning' => $this->isRunning,
            'startedAt' => $this->startedAt?->format('Y-m-d H:i:s'),
        ];
    }
    
    /**
     * Get progress percentage
     */
    public function getProgressPercentage(): float
    {
        if ($this->total === 0) {
            return 0.0;
        }
        return round(($this->processed / $this->total) * 100, 2);
    }
}

