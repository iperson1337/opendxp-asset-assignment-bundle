<?php

namespace Iperson1337\AssetAssignmentBundle\Service;

use Iperson1337\AssetAssignmentBundle\Dto\ConfigurationDto;
use Iperson1337\AssetAssignmentBundle\Exception\ConfigurationNotFoundException;
use Iperson1337\AssetAssignmentBundle\Exception\FolderNotFoundException;
use Iperson1337\AssetAssignmentBundle\Exception\InfiniteLoopException;
use Iperson1337\AssetAssignmentBundle\Exception\InvalidConfigurationException;
use Iperson1337\AssetAssignmentBundle\Validator\ConfigurationValidator;
use OpenDxp\Bundle\DataHubBundle\Configuration\Dao;
use OpenDxp\Model\Asset;
use OpenDxp\Model\DataObject;
use Psr\Log\LoggerInterface;

/**
 * Service for loading and preparing configuration
 */
final readonly class ConfigurationLoader
{
    public function __construct(
        private ConfigurationValidator $validator,
        private LoggerInterface $logger
    ) {
    }

    /**
     * Load configuration for asset assignment
     *
     * @throws ConfigurationNotFoundException
     * @throws InvalidConfigurationException
     * @throws FolderNotFoundException
     * @throws InfiniteLoopException
     */
    public function load(string $configName): ConfigurationDto
    {
        // Load configuration
        $config = Dao::getByName($configName);
        if (!$config) {
            throw new ConfigurationNotFoundException($configName);
        }

        $configuration = $config->getConfiguration();
        $profile = $configuration['profile'] ?? [];

        // Validate configuration
        $this->validator->validate($profile);

        $sourceFolderPath = $profile['source_folder'] ?? '';
        $targetFolderPath = $profile['target_folder'] ?? '';
        $dataObjectClassId = $profile['data_object_class_id'] ?? null;
        $matchField = $profile['match_field'] ?? '';
        $attachmentField = $profile['attachment_field'] ?? null;
        $strategy = $profile['strategy'] ?? 'create_folder_and_move';
        $clearGalleryBeforeAssignment = $profile['clear_gallery_before_assignment'] ?? false;

        // Get source folder
        $sourceFolder = Asset::getByPath($sourceFolderPath);
        if (!$sourceFolder instanceof Asset\Folder) {
            throw new FolderNotFoundException($sourceFolderPath);
        }

        // Get target folder
        $targetFolder = Asset::getByPath($targetFolderPath);
        if (!$targetFolder instanceof Asset\Folder) {
            throw new FolderNotFoundException($targetFolderPath);
        }

        // Get class definition - can accept both ID (number) and class name (string)
        if (is_numeric($dataObjectClassId)) {
            $classDefinition = DataObject\ClassDefinition::getById((int)$dataObjectClassId);
        } else {
            // If string, find class by name using Listing
            $listing = new DataObject\ClassDefinition\Listing();
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

        // Get attachment field definition if specified
        $fieldDef = null;
        if ($attachmentField) {
            $fieldDef = $classDefinition->getFieldDefinition($attachmentField);
            if (!$fieldDef) {
                throw new InvalidConfigurationException("Attachment field '{$attachmentField}' not found in class '{$dataObjectClassId}'");
            }
        }

        $this->logger->info("Configuration loaded", [
            'source_folder' => $sourceFolderPath,
            'target_folder' => $targetFolderPath,
            'data_object_class_id' => $dataObjectClassId,
            'match_field' => $matchField,
            'attachment_field' => $attachmentField,
            'strategy' => $strategy
        ]);

        return new ConfigurationDto(
            $configName,
            $sourceFolderPath,
            $targetFolderPath,
            $sourceFolder,
            $targetFolder,
            $dataObjectClassId,
            $matchField,
            $attachmentField,
            $strategy,
            $classDefinition,
            $className,
            $dataObjectClass,
            $fieldDef,
            $clearGalleryBeforeAssignment
        );
    }

    /**
     * Load all files from source folder in batches to avoid memory spikes
     *
     * @return array<Asset>
     */
    public function loadFiles(ConfigurationDto $config): array
    {
        $all = [];
        foreach ($this->loadFilesInBatches($config) as $batch) {
            array_push($all, ...$batch);
        }
        return $all;
    }

    /**
     * Stream files from source folder in batches — use this for large folders
     *
     * @return \Generator<array<Asset>>
     */
    public function loadFilesInBatches(ConfigurationDto $config, int $batchSize = 200): \Generator
    {
        $offset = 0;
        do {
            $listing = new Asset\Listing();
            $listing->setCondition('path LIKE ? AND type != ?', [
                $config->sourceFolderPath . '%',
                'folder',
            ]);
            $listing->setLimit($batchSize);
            $listing->setOffset($offset);
            $listing->setOrderKey('id');
            $listing->setOrder('ASC');

            $batch = $listing->load();
            if (!empty($batch)) {
                yield $batch;
            }

            $offset += $batchSize;
        } while (count($batch ?? []) === $batchSize);
    }
}

