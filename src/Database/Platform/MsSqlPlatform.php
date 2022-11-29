<?php

namespace Cinch\Database\Platform;

use Cinch\Common\Dsn;
use Cinch\Component\Assert\Assert;
use Cinch\Database\Platform;
use Cinch\Database\Identifier;
use Cinch\Database\Session;
use Cinch\Database\UnsupportedVersionException;
use Exception;
use PDO;
use RuntimeException;

class MsSqlPlatform implements Platform
{
    private readonly float $version;
    private readonly Session $session;

    public function getName(): string
    {
        return 'mssql';
    }

    public function getDriver(): string
    {
        return 'mssql';
    }

    public function getVersion(): float
    {
        return $this->version;
    }

    public function formatDateTime(\DateTimeInterface $dateTime): string
    {
        return $dateTime->format(self::DATETIME_FORMAT);
    }

    public function createIdentifier(string $value): Identifier
    {
        return new class($this->session, $value) extends Identifier {
            public function __construct(Session $session, string $value)
            {
                Assert::regex($value, '~^[\x{0001}-\x{ffff}]{1,128}$~u', 'identifier');
                parent::__construct($value, $session->quoteString($value), $session->quoteIdentifier($value));
            }
        };
    }

    public function addParams(Dsn $dsn, array $params): array
    {
        $params['user'] = $dsn->getUser(default: 'sa'); // standard administrator
        $params['port'] = $dsn->getPort() ?? 1433;
        $params['driverOptions']['LoginTimeout'] = $dsn->getConnectTimeout();
        $params['driverOptions']['Encrypt'] = 1;
        $params['driverOptions']['ApplicationIntent'] = 'ReadWrite';
        $params['driverOptions']['TrustServerCertificate'] = 1;
        return $params;
    }

    public function initSession(Session $session, Dsn $dsn): Session
    {
        /** @var PDO $pdo */
        $pdo = $session->getNativeConnection();

        /* cannot be passed to constructor according to PDO and Microsoft docs */
        $pdo->setAttribute(PDO::SQLSRV_ATTR_ENCODING, PDO::SQLSRV_ENCODING_UTF8);
        $pdo->setAttribute(PDO::SQLSRV_ATTR_QUERY_TIMEOUT, (int) ($dsn->getTimeout() / 1000));

        $dbname = trim($dsn->getPath(), '/');

        /* select 'compatibility_level' but also grab some properties (avoid 2nd query) */
        $result = $session->executeQuery("select compatibility_level, serverproperty('ProductVersion'),
            serverproperty('Edition') from sys.databases where name = ?", [$dbname]);

        [$compatLevel, $version, $edition] = $result->fetchNumeric();
        $this->version = (float) $version;

        /* cinch supports:
         *   * SQL Server - minimum version: 2014 (v12.x) released March 18, 2014
         *   * Azure SQL Database (v12.x) - version is totally separate from SQL Server version
         */
        if ($this->version < 12.0)
            throw new UnsupportedVersionException($edition, $this->version, 12.0);

        /* 110 is SQL Server 2014: https://learn.microsoft.com/en-us/sql/t-sql/statements/alter-database-transact-sql-compatibility-level?view=sql-server-ver16 */
        if ($compatLevel < 110)
            throw new UnsupportedVersionException($edition, $this->version, 12.0,
                "compatibility_level $compatLevel < 110");

        return $this->session = $session;
    }

    public function lockSession(string $name, int $timeout): bool
    {
        $timeout = max(0, $timeout);

        /* there is no OUTPUT param for sp_getapplock, so we have to assign return value to a variable and then
         * select it. Can't use prepared statements because that only allows a single statement.
         */
        $r = $this->session->executeQuery("
            declare @r int; 
            exec @r = sp_getapplock {$this->session->quoteString($name)}, 'Exclusive', 'Session', $timeout; 
            select @r");

        return match ($r = $r->fetchOne()) {
            0, 1 => true,
            -1 => false, // timeout
            -2 => throw new Exception("the lock request for '$name' was canceled"),
            -3 => throw new Exception("the lock request for '$name' was chosen as a deadlock victim"),
            default => throw new RuntimeException("unknown error calling sp_getapplock '$name' (returned $r)")
        };
    }

    public function unlockSession(string $name): void
    {
        /* please see comments within lockSession */
        $r = $this->session->executeQuery("
            declare @r int; 
            exec @r = sp_releaseapplock {$this->session->quoteString($name)}, 'Session'; 
            select @r");

        if (($r = $r->fetchOne()) != 0)
            throw new RuntimeException("unknown error calling sp_releaseapplock '$name' (returned $r)");
    }
}