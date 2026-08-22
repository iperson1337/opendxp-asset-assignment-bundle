<?php

namespace Iperson1337\AssetAssignmentBundle\Exception;

final class InfiniteLoopException extends AssetAssignmentException
{
    public function __construct(string $sourcePath, string $targetPath)
    {
        parent::__construct(
            "Target folder '{$targetPath}' cannot be inside source folder '{$sourcePath}' - this would cause infinite loop!"
        );
    }
}

