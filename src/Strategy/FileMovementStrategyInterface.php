<?php

namespace Iperson1337\AssetAssignmentBundle\Strategy;

use OpenDxp\Model\Asset;

/**
 * Interface for file movement strategies
 */
interface FileMovementStrategyInterface
{
    /**
     * Move file according to the strategy
     *
     * @param Asset $file File to move
     * @param Asset\Folder $targetFolder Target folder
     * @param string $folderName Folder name for nested strategies
     * @return Asset Moved file
     * @throws \Exception
     */
    public function moveFile(Asset $file, Asset\Folder $targetFolder, string $folderName): Asset;

    /**
     * Get strategy name
     */
    public function getName(): string;
}

