<?php

namespace Iperson1337\AssetAssignmentBundle\Strategy;

use Doctrine\DBAL\Exception\ConnectionException;
use OpenDxp\Model\Asset;
use Psr\Log\LoggerInterface;

/**
 * Strategy: Create folder by match field and move file to it
 */
final class CreateFolderAndMoveStrategy implements FileMovementStrategyInterface
{
    private int $createdFolders = 0;

    /** @var array<string, Asset\Folder> Cache folder objects by path for the lifetime of this strategy instance */
    private array $folderCache = [];

    public function __construct(
        private LoggerInterface $logger,
        private string $configName,
    ) {
    }

    public function getCreatedFoldersCount(): int
    {
        return $this->createdFolders;
    }

    public function moveFile(Asset $file, Asset\Folder $targetFolder, string $folderName): Asset
    {
        $targetFolderPath = $targetFolder->getFullPath();
        $newFolderPath = $targetFolderPath . '/' . $folderName;

        // Get or create folder — cache by path to avoid repeated getByPath() within a group
        if (isset($this->folderCache[$newFolderPath])) {
            $targetFolderObj = $this->folderCache[$newFolderPath];
        } else {
            $targetFolderObj = Asset::getByPath($newFolderPath);
            if (!$targetFolderObj) {
                try {
                    $targetFolderObj = new Asset\Folder();
                    $targetFolderObj->setParent($targetFolder);
                    $targetFolderObj->setFilename($folderName);
                    $targetFolderObj->save();

                    $this->createdFolders++;
                    $this->logger->info("✓ Created folder: {$newFolderPath}");
                } catch (ConnectionException $e) {
                    $this->logger->warning("Failed to create folder '{$newFolderPath}' due to transaction error: " . $e->getMessage(), [
                        'exception' => $e,
                    ]);
                    throw $e;
                }
            }
            $this->folderCache[$newFolderPath] = $targetFolderObj;
        }

        // Check if file with same name already exists in target folder
        $targetFilePath = $newFolderPath . '/' . $file->getFilename();
        $existingFile = Asset::getByPath($targetFilePath);
        if ($existingFile) {
            $this->logger->debug("Deleting existing file before move: {$targetFilePath}");
            $existingFile->delete();
        }

        // Move file to the created/existing folder
        try {
            $file->setParent($targetFolderObj);
            $file->save();

            $this->logger->debug("✓ Moved file '{$file->getFilename()}' to {$newFolderPath}");
        } catch (ConnectionException $e) {
            // Handle transaction rollback errors gracefully
            $this->logger->warning("Failed to save Asset '{$file->getFilename()}' due to transaction error: " . $e->getMessage(), [
                'file_path' => $newFolderPath . '/' . $file->getFilename(),
                'exception' => $e
            ]);
            // Re-throw to let caller handle it
            throw $e;
        }

        return $file;
    }

    public function getName(): string
    {
        return 'create_folder_and_move';
    }
}

