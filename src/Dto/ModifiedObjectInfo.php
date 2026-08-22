<?php

namespace Iperson1337\AssetAssignmentBundle\Dto;

/**
 * DTO for modified object information
 */
final readonly class ModifiedObjectInfo
{
    public function __construct(
        public int $id,
        public string $className
    ) {}
    
    /**
     * Create from array
     * 
     * @param array{id: int, className: string} $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data['id'], $data['className']);
    }
    
    /**
     * Convert to array
     * 
     * @return array{id: int, className: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'className' => $this->className
        ];
    }
}

