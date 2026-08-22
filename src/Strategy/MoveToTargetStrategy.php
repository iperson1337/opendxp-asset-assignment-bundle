<?php

namespace Iperson1337\AssetAssignmentBundle\Strategy;

use Doctrine\DBAL\Exception\ConnectionException;
use OpenDxp\Model\Asset;
use Psr\Log\LoggerInterface;

/**
 * Strategy: Move file directly to target folder
 */
final class MoveToTargetStrategy implements FileMovementStrategyInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private string $configName,
    ) {
    }

    public function moveFile(Asset $file, Asset\Folder $targetFolder, string $folderName): Asset
    {
        $targetFolderPath = $targetFolder->getFullPath();

        // Check if file with same name already exists in target folder
        $targetFilePath = $targetFolderPath . '/' . $file->getFilename();
        $existingFile = Asset::getByPath($targetFilePath);
        if ($existingFile) {
            // Delete existing file to avoid DuplicateFullPathException
            $this->logger->info("Deleting existing file before move: {$targetFilePath}");
            $existingFile->delete();
        }

        // Move file directly to target folder
        try {
            $file->setParent($targetFolder);
            $file->save();

            $this->logger->debug("✓ Moved file '{$file->getFilename()}' to {$targetFolderPath}");
        } catch (ConnectionException $e) {
            // Handle transaction rollback errors gracefully
            $this->logger->warning("Failed to save Asset '{$file->getFilename()}' due to transaction error: " . $e->getMessage(), [
                'file_path' => $targetFolderPath . '/' . $file->getFilename(),
                'exception' => $e
            ]);
            // Re-throw to let caller handle it
            throw $e;
        }

        return $file;
    }

    public function getName(): string
    {
        return 'move_to_target';
    }
}

