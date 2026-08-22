<?php

namespace Iperson1337\AssetAssignmentBundle\Exception;

final class InvalidFieldTypeException extends AssetAssignmentException
{
    public function __construct(string $fieldType, array $supportedTypes = [])
    {
        $message = "Unsupported field type: {$fieldType}";
        if (!empty($supportedTypes)) {
            $message .= '. Supported types: ' . implode(', ', $supportedTypes);
        }
        parent::__construct($message);
    }
}

