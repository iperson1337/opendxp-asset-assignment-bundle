<?php

namespace Iperson1337\AssetAssignmentBundle\Message;

final readonly class AssetAssignmentMessage
{
    public function __construct(
        public string $configName
    ) {}
}

