<?php

namespace Iperson1337\AssetAssignmentBundle\Exception;

final class InvalidConfigurationException extends AssetAssignmentException
{
    public function __construct(string $message, array $missingFields = [])
    {
        if (!empty($missingFields)) {
            $message .= ' Missing fields: ' . implode(', ', $missingFields);
        }
        parent::__construct($message);
    }
}

