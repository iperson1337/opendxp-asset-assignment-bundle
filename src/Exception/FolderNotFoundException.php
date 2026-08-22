<?php

namespace Iperson1337\AssetAssignmentBundle\Exception;

final class FolderNotFoundException extends AssetAssignmentException
{
    public function __construct(string $folderPath)
    {
        parent::__construct("Folder not found: {$folderPath}");
    }
}

