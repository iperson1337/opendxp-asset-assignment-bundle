<?php

namespace Iperson1337\AssetAssignmentBundle\Exception;

final class ConfigurationNotFoundException extends AssetAssignmentException
{
    public function __construct(string $configName)
    {
        parent::__construct("Configuration '{$configName}' not found");
    }
}

