<?php

namespace Iperson1337\AssetAssignmentBundle\Service;

use Iperson1337\AssetAssignmentBundle\Dto\ConfigurationDto;
use OpenDxp\Model\Asset;
use OpenDxp\Model\DataObject\ClassDefinition\Data;
use Psr\Log\LoggerInterface;

/**
 * Service for grouping files by base code for imageGallery processing
 */
final readonly class FileGrouper
{
    public function __construct(
        private LoggerInterface $logger
    ) {
    }

    /**
     * Group files by base code for imageGallery processing
     *
     * @param array<Asset> $files
     * @param ConfigurationDto $config
     * @return array<string, array<array{file: Asset, index: int}>>
     */
    public function group(array $files, ConfigurationDto $config): array
    {
        $groupedFiles = [];
        $isImageGallery = $this->isImageGalleryField($config->attachmentField, $config->fieldDef);
        $skippedCount = 0;

        $this->logger->info("Grouping " . count($files) . " files (attachment field is imageGallery: " . ($isImageGallery ? 'yes' : 'no') . ")");

        foreach ($files as $file) {
            $filename = pathinfo($file->getFilename(), PATHINFO_FILENAME);
            
            // Parse filename to check if it matches the pattern {code}-{index}
            if (preg_match('/^(.+?)-(\d+)$/', $filename, $matches)) {
                $baseCode = $matches[1];
                $index = (int)$matches[2];
                
                if (!$isImageGallery) {
                    // Skip files with index if field is not imageGallery
                    $skippedCount++;
                    $this->logger->debug("Skipping file with index '{$file->getFilename()}' - attachment field is not imageGallery");
                    continue;
                }
                
                // For imageGallery: group files by base code and track index
                if (!isset($groupedFiles[$baseCode])) {
                    $groupedFiles[$baseCode] = [];
                }
                $groupedFiles[$baseCode][] = [
                    'file' => $file,
                    'index' => $index
                ];
            } else {
                // For non-imageGallery fields: process files without index pattern
                if (!$isImageGallery) {
                    $groupedFiles[$filename] = [
                        [
                            'file' => $file,
                            'index' => 0
                        ]
                    ];
                } else {
                    // For imageGallery: skip files that don't match the {code}-{index} pattern
                    $skippedCount++;
                    $this->logger->debug("Skipping file '{$file->getFilename()}' - does not match {code}-{index} pattern for imageGallery");
                }
            }
        }

        if ($skippedCount > 0) {
            $this->logger->info("Skipped {$skippedCount} files during grouping");
        }

        // Sort files within each group by index
        foreach ($groupedFiles as $baseCode => &$fileGroup) {
            usort($fileGroup, function($a, $b) {
                return $a['index'] <=> $b['index'];
            });
        }

        $this->logger->info("Created " . count($groupedFiles) . " file groups");

        return $groupedFiles;
    }

    private function isImageGalleryField(?string $attachmentField, ?Data $fieldDef): bool
    {
        if (!$attachmentField || !$fieldDef) {
            return false;
        }

        return $fieldDef->getFieldtype() === 'imageGallery';
    }
}

