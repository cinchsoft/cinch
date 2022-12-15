<?php

namespace Cinch\MigrationStore\Adapter;

use Cinch\Common\Checksum;
use Cinch\Common\StorePath;
use Exception;

class GitFile extends File
{
    public function __construct(
        private readonly GitAdapter $git,
        StorePath $path,
        private readonly Checksum $sha,
        private string|null $content = null)
    {
        parent::__construct($path);
    }

    /**
     * @throws Exception
     */
    public function getContents(): string
    {
        if ($this->content === null)
            $this->content = $this->git->getContents($this->path->value);
        return $this->content;
    }

    public function getChecksum(): Checksum
    {
        return $this->sha;
    }
}