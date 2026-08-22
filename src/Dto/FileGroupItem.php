<?php

namespace Iperson1337\AssetAssignmentBundle\Dto;

use OpenDxp\Model\Asset;

/**
 * DTO for file group item
 */
final readonly class FileGroupItem
{
    public function __construct(
        public Asset $file,
        public int $index
    ) {}
    
    /**
     * Create from array
     * 
     * @param array{file: Asset, index: int} $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['file'], $data['index']);
    }
    
    /**
     * Convert to array
     * 
     * @return array{file: Asset, index: int}
     */
    public function toArray(): array
    {
        return [
            'file' => $this->file,
            'index' => $this->index
        ];
    }
}

