<?php

namespace Iperson1337\AssetAssignmentBundle\Validator;

use Iperson1337\AssetAssignmentBundle\Exception\FolderNotFoundException;
use Iperson1337\AssetAssignmentBundle\Exception\InfiniteLoopException;
use Iperson1337\AssetAssignmentBundle\Exception\InvalidConfigurationException;
use OpenDxp\Model\Asset;
use OpenDxp\Model\DataObject\ClassDefinition;
use OpenDxp\Model\DataObject\ClassDefinition\Listing as ClassDefinitionListing;

/**
 * Validator for asset assignment configuration
 */
final readonly class ConfigurationValidator
{
    /**
     * Validate configuration profile
     * 
     * @param array<string, mixed> $profile Configuration profile
     * @throws InvalidConfigurationException
     * @throws FolderNotFoundException
     * @throws InfiniteLoopException
     */
    public function validate(array $profile): void
    {
        $this->validateRequiredFields($profile);
        $this->validateSourceFolder($profile);
        $this->validateTargetFolder($profile);
        $this->validateTargetNotInSource($profile);
        $this->validateDataObjectClass($profile);
        $this->validateMatchField($profile);
        $this->validateAttachmentField($profile);
        $this->validateBatchSize($profile);
    }
    
    /**
     * Validate required fields exist
     * 
     * @param array<string, mixed> $profile
     * @throws InvalidConfigurationException
     */
    private function validateRequiredFields(array $profile): void
    {
        $requiredFields = ['source_folder', 'target_folder', 'data_object_class_id', 'match_field'];
        $missingFields = [];
        
        foreach ($requiredFields as $field) {
            if (empty($profile[$field] ?? null)) {
                $missingFields[] = $field;
            }
        }
        
        if (!empty($missingFields)) {
            throw new InvalidConfigurationException(
                'Missing required configuration parameters',
                $missingFields
            );
        }
    }
    
    /**
     * Validate source folder exists
     * 
     * @param array<string, mixed> $profile
     * @throws FolderNotFoundException
     */
    private function validateSourceFolder(array $profile): void
    {
        $sourceFolderPath = $profile['source_folder'] ?? null;
        if (!$sourceFolderPath) {
            return; // Already validated in validateRequiredFields
        }
        
        $sourceFolder = Asset::getByPath($sourceFolderPath);
        if (!$sourceFolder instanceof Asset\Folder) {
            throw new FolderNotFoundException($sourceFolderPath);
        }
    }
    
    /**
     * Validate target folder exists
     * 
     * @param array<string, mixed> $profile
     * @throws FolderNotFoundException
     */
    private function validateTargetFolder(array $profile): void
    {
        $targetFolderPath = $profile['target_folder'] ?? null;
        if (!$targetFolderPath) {
            return; // Already validated in validateRequiredFields
        }
        
        $targetFolder = Asset::getByPath($targetFolderPath);
        if (!$targetFolder instanceof Asset\Folder) {
            throw new FolderNotFoundException($targetFolderPath);
        }
    }
    
    /**
     * Validate target folder is not inside source folder (prevents infinite loop)
     * 
     * @param array<string, mixed> $profile
     * @throws InfiniteLoopException
     */
    private function validateTargetNotInSource(array $profile): void
    {
        $sourcePath = rtrim($profile['source_folder'] ?? '', '/');
        $targetPath = rtrim($profile['target_folder'] ?? '', '/');

        if (empty($sourcePath) || empty($targetPath)) {
            return;
        }

        // Append slash so "/import/Товары" does not falsely match "/import/ТоварыПолные"
        if (str_starts_with($targetPath . '/', $sourcePath . '/')) {
            throw new InfiniteLoopException($sourcePath, $targetPath);
        }
    }
    
    /**
     * Validate DataObject class exists
     * 
     * @param array<string, mixed> $profile
     * @throws InvalidConfigurationException
     */
    private function validateDataObjectClass(array $profile): void
    {
        $dataObjectClassId = $profile['data_object_class_id'] ?? null;
        if (!$dataObjectClassId) {
            return; // Already validated in validateRequiredFields
        }
        
        // Get class definition - can accept both ID (number) and class name (string)
        if (is_numeric($dataObjectClassId)) {
            $classDefinition = ClassDefinition::getById((int)$dataObjectClassId);
        } else {
            // If string, find class by name using Listing
            $listing = new ClassDefinitionListing();
            $listing->setCondition('name = ?', [(string)$dataObjectClassId]);
            $listing->setLimit(1);
            $classes = $listing->load();
            $classDefinition = !empty($classes) ? $classes[0] : null;
        }
        
        if (!$classDefinition) {
            throw new InvalidConfigurationException("DataObject class '{$dataObjectClassId}' not found");
        }
        
        $className = ucfirst($classDefinition->getName());
        $dataObjectClass = "\\OpenDxp\\Model\\DataObject\\" . $className;
        
        if (!class_exists($dataObjectClass)) {
            throw new InvalidConfigurationException("DataObject class {$dataObjectClass} does not exist");
        }
    }
    
    /**
     * Validate match field exists in DataObject class
     * 
     * @param array<string, mixed> $profile
     * @throws InvalidConfigurationException
     */
    private function validateMatchField(array $profile): void
    {
        $dataObjectClassId = $profile['data_object_class_id'] ?? null;
        $matchField = $profile['match_field'] ?? null;
        
        if (!$dataObjectClassId || !$matchField) {
            return; // Already validated in validateRequiredFields
        }
        
        // Get class definition - can accept both ID (number) and class name (string)
        if (is_numeric($dataObjectClassId)) {
            $classDefinition = ClassDefinition::getById((int)$dataObjectClassId);
        } else {
            $classDefinition = ClassDefinition::getByName((string)$dataObjectClassId);
        }
        
        if (!$classDefinition) {
            return; // Already validated in validateDataObjectClass
        }
        
        $fieldDef = $classDefinition->getFieldDefinition($matchField);
        if (!$fieldDef) {
            throw new InvalidConfigurationException(
                "Match field '{$matchField}' not found in class '{$dataObjectClassId}'"
            );
        }
    }
    
    /**
     * Validate attachment field if specified
     * 
     * @param array<string, mixed> $profile
     * @throws InvalidConfigurationException
     */
    private function validateAttachmentField(array $profile): void
    {
        $attachmentField = $profile['attachment_field'] ?? null;
        if (!$attachmentField) {
            return; // Optional field
        }
        
        $dataObjectClassId = $profile['data_object_class_id'] ?? null;
        if (!$dataObjectClassId) {
            return; // Already validated in validateRequiredFields
        }
        
        // Get class definition - can accept both ID (number) and class name (string)
        if (is_numeric($dataObjectClassId)) {
            $classDefinition = ClassDefinition::getById((int)$dataObjectClassId);
        } else {
            $classDefinition = ClassDefinition::getByName((string)$dataObjectClassId);
        }
        
        if (!$classDefinition) {
            return; // Already validated in validateDataObjectClass
        }
        
        $fieldDef = $classDefinition->getFieldDefinition($attachmentField);
        if (!$fieldDef) {
            throw new InvalidConfigurationException(
                "Attachment field '{$attachmentField}' not found in class '{$dataObjectClassId}'"
            );
        }
    }
    
    /**
     * Validate batch size is within acceptable range
     * 
     * @param array<string, mixed> $profile
     * @throws InvalidConfigurationException
     */
    private function validateBatchSize(array $profile): void
    {
        $batchSize = $profile['batch_size'] ?? null;
        if ($batchSize === null) {
            return; // Will use default
        }
        
        $batchSize = (int)$batchSize;
        if ($batchSize < 1 || $batchSize > 1000) {
            throw new InvalidConfigurationException(
                "Batch size must be between 1 and 1000, got: {$batchSize}"
            );
        }
    }
}

