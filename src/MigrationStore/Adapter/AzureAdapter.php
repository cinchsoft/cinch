<?php

namespace Cinch\MigrationStore\Adapter;

use Cinch\Common\Checksum;
use Cinch\Common\Dsn;
use Cinch\Common\Location;
use Cinch\Component\Assert\Assert;
use Cinch\MigrationStore\Directory;
use Exception;
use GuzzleHttp\Exception\GuzzleException;
use RuntimeException;

/** This supports Azure DevOps Services (cloud), not Azure DevOps Server (on-premise). */
class AzureAdapter extends GitAdapter
{
    const TOKEN_ENV_NAME = 'CINCH_AZURE_TOKEN';

    /* docs say set to 6.0 but response headers return 7.0 -- Nov 9, 2022 */
    private const API_VERSION = '7.0';
    private readonly array $branchInfo;

    public function __construct(string $baseUri, string $branch, string $storeDir, array $config)
    {
        parent::__construct($baseUri, $branch, $storeDir, $config);
        $this->branchInfo = [
            'searchCriteria.compareVersion.version' => $this->branch,
            'searchCriteria.compareVersion.versionType' => 'branch'
        ];
    }

    /**
     * @throws GuzzleException
     */
    public function search(Directory $dir): array
    {
        $query = http_build_query([
            'api-version' => self::API_VERSION,
            'scopePath' => $this->resolvePath($dir->path),
            'recursionLevel' => ($dir->flags & Directory::RECURSIVE) ? 'full' : 'oneLevel',
            ...$this->branchInfo
        ]);

        $tree = $this->getTree("$this->baseUri/items?$query");
        return $this->toFiles($tree['values'], $dir->exclude, 'path', 'gitObjectType', 'objectId');
    }

    /**
     * @throws GuzzleException
     */
    public function addFile(string $path, string $content, string $message): FileId
    {
        $r = $this->client->post("$this->baseUri/pushes?api-version=" . self::API_VERSION, [
            'json' => [
                "refUpdates" => [
                    [
                        "name" => "refs/heads/$this->branch",
                        "oldObjectId" => $this->getLastCommitId()
                    ]
                ],
                'commits' => [
                    [
                        'comment' => $this->buildCommitMessage($message),
                        'changes' => [
                            [
                                'changeType' => 'add',
                                'item' => ['path' => $this->resolvePath($path)],
                                'newContent' => [
                                    'content' => base64_encode($content),
                                    'contentType' => 'base64encoded'
                                ]
                            ]
                        ]
                    ]
                ]
            ]
        ]);

        return new FileId($this->toJson($r)['commits'][0]['commitId']);
    }

    /**
     * @throws GuzzleException
     */
    public function deleteFile(string $path, string $message, FileId $fileId): void
    {
        $this->client->post("$this->baseUri/pushes?api-version=" . self::API_VERSION, [
            'json' => [
                "refUpdates" => [
                    [
                        "name" => "refs/heads/$this->branch",
                        "oldObjectId" => $fileId->value
                    ]
                ],
                'commits' => [
                    [
                        'comment' => $this->buildCommitMessage($message),
                        'changes' => [
                            [
                                'changeType' => 'delete',
                                'item' => ['path' => $this->resolvePath($path)]
                            ]
                        ]
                    ]
                ]
            ]
        ]);
    }

    /**
     * @throws GuzzleException
     */
    public function getFile(string $path): File
    {
        $query = $this->getItemsQueryString($path);
        $data = $this->getFileByUri("$this->baseUri/items?$query", [
            'headers' => ['Content-Type' => 'application/json'] // metadata and content
        ]);

        return new GitFile(
            $this,
            new Location($path),
            new Checksum($data['objectId']),
            $data['content']
        );
    }

    /**
     * @throws GuzzleException
     */
    public function getContents(string $path): string
    {
        $query = $this->getItemsQueryString($path);
        return $this->getContentsByUri("$this->baseUri/items?$query", [
            'headers' => ['Content-Type' => 'text/plain'] // only content
        ]);
    }

    private function getItemsQueryString(string $path): string
    {
        if (!($path = $this->resolvePath($path)))
            throw new RuntimeException("cannot get items without a path");

        return http_build_query([
            'api-version' => self::API_VERSION,
            'path' => rawurlencode($path),
            'includeContent' => 'true',
            ...$this->branchInfo
        ]);
    }

    /** unfortunately, azure requires latest commit id to add/delete a file. separate call required
     * @throws GuzzleException
     */
    private function getLastCommitId(): string
    {
        $query = http_build_query([
            'name' => $this->branch,
            'api-version' => self::API_VERSION
        ]);

        $stats = $this->toJson($this->client->get("$this->baseUri/stats/branches?$query"));
        return $stats['commit']['commitId'];
    }

    /**
     * @throws Exception
     */
    public static function fromDsn(Dsn $dsn, string $userAgent): static
    {
        // azure:organization/<project>/<repo>/storeDir?branch=branch

        Assert::equals($dsn->getScheme(), 'azure', "expected azure dsn");
        Assert::empty($dsn->getHost(), 'gitlab host not supported');

        $parts = explode('/', trim($dsn->getPath(), '/'), 3);

        /* azure limits org name to ASCII letters and digits for the first and last character and allows
         * ASCII letters, digits and hyphen for middle characters.
         */
        $pattern = '~^(?:[a-z\d]|[a-z\d][a-z\d-]*[a-z\d])$~i';
        $org = Assert::regex(array_shift($parts), $pattern, 'azure organization');

        $project = Assert::notEmpty(array_shift($parts), 'azure project');
        $repo = Assert::notEmpty(array_shift($parts), 'azure repo');
        $storeDir = array_shift($parts) ?? '';

        $branch = Assert::notEmpty($dsn->getOption('branch'), 'azure branch');

        $baseUri = sprintf('%s/%s/_apis/git/repositories/%s',
            rawurlencode($org), rawurlencode($project), rawurlencode($repo));

        return new static($baseUri, $branch, $storeDir, [
            'base_uri' => "https://dev.azure.com",
            'connect_timeout' => $dsn->getConnectTimeout(),
            'read_timeout' => $dsn->getTimeout() / 1000,
            'headers' => [
                'User-Agent' => $userAgent,
                // user:token, don't need user but colon must be present
                'Authorization' => 'Basic ' . base64_encode(':' . self::getToken($dsn))
            ], ...self::getSslConfig($dsn)
        ]);
    }
}