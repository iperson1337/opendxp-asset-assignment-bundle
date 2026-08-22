<?php

namespace Iperson1337\AssetAssignmentBundle\Dto;

use OpenDxp\Model\Asset;
use OpenDxp\Model\DataObject\ClassDefinition;
use OpenDxp\Model\DataObject\ClassDefinition\Data;

/**
 * DTO for configuration data
 */
final readonly class ConfigurationDto
{
    public function __construct(
        public string $configName,
        public string $sourceFolderPath,
        public string $targetFolderPath,
        public Asset\Folder $sourceFolder,
        public Asset\Folder $targetFolder,
        public int|string $dataObjectClassId,
        public string $matchField,
        public ?string $attachmentField,
        public string $strategy,
        public ClassDefinition $classDefinition,
        public string $className,
        public string $dataObjectClass,
        public ?Data $fieldDef,
        public bool $clearGalleryBeforeAssignment
    ) {
    }
}

