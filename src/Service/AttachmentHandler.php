<?php

namespace Iperson1337\AssetAssignmentBundle\Service;

use Iperson1337\AssetAssignmentBundle\Exception\AttachmentException;
use Iperson1337\AssetAssignmentBundle\Exception\InvalidFieldTypeException;
use OpenDxp\Model\DataObject;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use Psr\Log\LoggerInterface;

/**
 * Service for handling file attachments to DataObject fields
 */
readonly class AttachmentHandler
{
    public function __construct(
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Attach files to DataObject field
     *
     * @param DataObject\Concrete $dataObject Target DataObject
     * @param string $attachmentField Field name to attach to
     * @param Data $fieldDef Field definition
     * @param array<array{file: \OpenDxp\Model\Asset, index: int}> $fileGroup Array of files with 'file' and 'index' keys
     * @param bool $clearGalleryBeforeAssignment Clear gallery before assignment
     * @param string $configName Configuration name for logging
     * @return void
     * @throws InvalidFieldTypeException When field type is not supported
     * @throws AttachmentException When attachment fails
     */
    public function attachFiles(
        DataObject\Concrete $dataObject,
        string $attachmentField,
        Data $fieldDef,
        array $fileGroup,
        bool $clearGalleryBeforeAssignment,
        string $configName
    ): void {
        $getter = 'get' . ucfirst($attachmentField);
        $setter = 'set' . ucfirst($attachmentField);
        $fieldType = $fieldDef->getFieldtype();

        $supportedTypes = ['imageGallery', 'image', 'hotspotimage', 'manyToManyRelation', 'advancedManyToManyRelation'];
        
        if (!in_array($fieldType, $supportedTypes, true)) {
            throw new InvalidFieldTypeException($fieldType, $supportedTypes);
        }

        try {
            if ($fieldType === 'imageGallery') {
                $this->attachToImageGallery(
                    $dataObject,
                    $getter,
                    $setter,
                    $fileGroup,
                    $clearGalleryBeforeAssignment,
                    $configName
                );
            } elseif (in_array($fieldType, ['image', 'hotspotimage'], true)) {
                $this->attachToSingleAssetField($dataObject, $setter, $fileGroup);
            } elseif (in_array($fieldType, ['manyToManyRelation', 'advancedManyToManyRelation'], true)) {
                $this->attachToMultiAssetField($dataObject, $getter, $setter, $fileGroup);
            }
        } catch (\Throwable $e) {
            throw new AttachmentException(
                "Failed to attach files to field '{$attachmentField}': " . $e->getMessage(),
                $e
            );
        }
    }

    /**
     * Prepare gallery items from file group
     */
    public function prepareGalleryItems(array $fileGroup): array
    {
        $galleryItems = [];
        foreach ($fileGroup as $fileData) {
            $hotspotImage = new \OpenDxp\Model\DataObject\Data\Hotspotimage();
            $hotspotImage->setImage($fileData['file']);
            $galleryItems[] = $hotspotImage;
        }
        return $galleryItems;
    }

    /**
     * Attach files to imageGallery field
     */
    private function attachToImageGallery(
        DataObject\Concrete $dataObject,
        string $getter,
        string $setter,
        array $fileGroup,
        bool $clearGalleryBeforeAssignment,
        string $configName
    ): void {
        $galleryItems = $this->prepareGalleryItems($fileGroup);
        if (empty($galleryItems)) {
            return;
        }

        $gallery = $dataObject->$getter();

        // Clear gallery if configured
        if ($clearGalleryBeforeAssignment) {
            $gallery = null;
            $this->logger->info("Clearing existing gallery for DataObject ID {$dataObject->getId()}");
        }

        if (!$gallery) {
            $gallery = new \OpenDxp\Model\DataObject\Data\ImageGallery();
        }

        $existingItems = $gallery->getItems() ?? [];

        // Build map of existing asset IDs to prevent duplicates
        $existingAssetIds = [];
        foreach ($existingItems as $item) {
            if ($item instanceof \OpenDxp\Model\DataObject\Data\Hotspotimage) {
                $image = $item->getImage();
                if ($image instanceof \OpenDxp\Model\Asset) {
                    $existingAssetIds[$image->getId()] = true;
                }
            }
        }

        // Add only new items (not already in gallery)
        foreach ($galleryItems as $item) {
            if ($item instanceof \OpenDxp\Model\DataObject\Data\Hotspotimage) {
                $image = $item->getImage();
                if ($image instanceof \OpenDxp\Model\Asset && !isset($existingAssetIds[$image->getId()])) {
                    $existingItems[] = $item;
                    $existingAssetIds[$image->getId()] = true;
                }
            }
        }

        $gallery->setItems($existingItems);
        $dataObject->$setter($gallery);
    }

    /**
     * Attach file to single asset field (image, hotspotimage)
     */
    private function attachToSingleAssetField(
        DataObject\Concrete $dataObject,
        string $setter,
        array $fileGroup
    ): void {
        // For single asset fields, use the first file (index 0)
        foreach ($fileGroup as $fileData) {
            if ($fileData['index'] === 0) {
                $dataObject->$setter($fileData['file']);
                break;
            }
        }
    }

    /**
     * Attach files to multi asset field (manyToManyRelation, advancedManyToManyRelation)
     */
    private function attachToMultiAssetField(
        DataObject\Concrete $dataObject,
        string $getter,
        string $setter,
        array $fileGroup
    ): void {
        $currentAssets = $dataObject->$getter() ?: [];
        if (!is_array($currentAssets)) {
            $currentAssets = [$currentAssets];
        }

        // Build map of existing asset IDs to prevent duplicates
        $existingAssetIds = [];
        foreach ($currentAssets as $asset) {
            if ($asset instanceof \OpenDxp\Model\Asset) {
                $existingAssetIds[$asset->getId()] = true;
            }
        }

        // Add only new files (not already in relation)
        foreach ($fileGroup as $fileData) {
            $file = $fileData['file'];
            if ($file instanceof \OpenDxp\Model\Asset && !isset($existingAssetIds[$file->getId()])) {
                $currentAssets[] = $file;
                $existingAssetIds[$file->getId()] = true; // Mark as added
            }
        }

        $dataObject->$setter($currentAssets);
    }
}

