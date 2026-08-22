<?php

namespace Iperson1337\AssetAssignmentBundle\Exception;

final class AttachmentException extends AssetAssignmentException
{
    public function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}

