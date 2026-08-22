<?php

namespace Iperson1337\AssetAssignmentBundle\Event;

use Symfony\Contracts\EventDispatcher\Event;

/**
 * Event dispatched when Asset Assignment execution is completed
 * Contains information about all modified DataObjects (universal for any class type)
 */
final class AssetAssignmentCompletedEvent extends Event
{
    public function __construct(
        public readonly string $configName,
        public readonly array $modifiedObjects,
        public readonly array $statistics,
        public readonly ?string $attachmentField = null
    ) {
    }

    /**
     * Get modified objects as array of ['id' => int, 'className' => string]
     */
    public function getModifiedObjects(): array
    {
        return $this->modifiedObjects;
    }

    /**
     * Get statistics about the execution
     */
    public function getStatistics(): array
    {
        return $this->statistics;
    }

    /**
     * Get configuration name
     */
    public function getConfigName(): string
    {
        return $this->configName;
    }

    /**
     * Get attachment field name configured for this assignment (if any)
     */
    public function getAttachmentField(): ?string
    {
        return $this->attachmentField;
    }
}
