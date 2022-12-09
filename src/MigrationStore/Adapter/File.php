<?php

namespace Cinch\MigrationStore\Adapter;

use Cinch\Common\Checksum;
use Cinch\Common\Location;

abstract class File
{
    public function __construct(protected readonly Location $location)
    {
    }

    public function getLocation(): Location
    {
        return $this->location;
    }

    public abstract function getChecksum(): Checksum;

    public abstract function getContents(): string;
}