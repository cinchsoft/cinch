<?php

namespace Cinch\MigrationStore\Adapter;

use Cinch\Common\Checksum;
use Cinch\Common\Location;

class LocalFile extends File
{
    private string|null $content = null;
    private Checksum|null $checksum = null;

    public function __construct(private readonly string $absolutePath, Location $location)
    {
        parent::__construct($location);
    }

    public function getAbsolutePath(): string
    {
        return $this->absolutePath;
    }

    public function getContents(): string
    {
        if ($this->content === null) {
            $this->content = slurp($this->absolutePath);
            $this->checksum = Checksum::fromData($this->content);
        }

        return $this->content;
    }

    public function getChecksum(): Checksum
    {
        if ($this->checksum === null)
            $this->getContents();
        return $this->checksum;
    }
}