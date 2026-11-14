<?php

declare(strict_types=1);

namespace Gfh\Tests\Support;

use Gfh\Core\Database\ConnectionString;
use Gfh\Core\Database\Database;
use Gfh\Core\Database\MigrationSet;
use Gfh\Core\Database\Migrator;
use Gfh\Core\Http\HttpException;
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that need PostgreSQL. They run against two throwaway databases named in
 * GFH_TEST_CORE_URL and GFH_TEST_ORG_URL (migrator-role URLs). The database names must end in _test,
 * so a test can never touch real data. The migrations are applied once, and every test runs inside a
 * transaction that is rolled back, so tests never affect each other.
 */
abstract class DatabaseTestCase extends TestCase
{
    private static ?\PDO $sharedCore = null;
    private static ?\PDO $sharedOrg = null;

    protected \PDO $core;
    protected \PDO $org;
    protected TestKit $kit;
    protected OrgKit $shop;

    protected function setUp(): void
    {
        if (self::$sharedCore === null) {
            $coreUrl = getenv('GFH_TEST_CORE_URL');
            $orgUrl = getenv('GFH_TEST_ORG_URL');
            if (!is_string($coreUrl) || $coreUrl === '' || !is_string($orgUrl) || $orgUrl === '') {
                $this->markTestSkipped('Set GFH_TEST_CORE_URL and GFH_TEST_ORG_URL to run the database tests (see docs/testing.md).');
            }

            self::$sharedCore = self::open($coreUrl, 'core', dirname(__DIR__, 2) . '/database/core/migrations');
            self::$sharedOrg = self::open($orgUrl, 'org', dirname(__DIR__, 2) . '/database/org/migrations');
        }

        $this->core = self::$sharedCore;
        $this->org = self::$sharedOrg;
        $this->core->beginTransaction();
        $this->org->beginTransaction();
        $this->kit = new TestKit($this->core, $this->org);
        $this->shop = new OrgKit($this->core, $this->org);
    }

    protected function tearDown(): void
    {
        foreach ([$this->core ?? null, $this->org ?? null] as $pdo) {
            if ($pdo instanceof \PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    private static function open(string $url, string $scope, string $migrations): \PDO
    {
        $connection = ConnectionString::parse($url);
        if (!str_ends_with($connection->database, '_test')) {
            throw new \RuntimeException(sprintf('Refusing to run tests against "%s": a test database name must end in _test.', $connection->database));
        }

        $pdo = Database::connect($connection, 'public', false, 'gfh-tests');
        (new Migrator())->migrate($pdo, new MigrationSet($scope, $migrations));

        return $pdo;
    }

    /** Asserts that $action throws an HttpException with this status, and returns it for further checks. */
    protected function assertHttpStatus(int $status, callable $action): HttpException
    {
        try {
            $action();
        } catch (HttpException $exception) {
            $this->assertSame($status, $exception->status(), 'Unexpected status: ' . $exception->getMessage());

            return $exception;
        }

        $this->fail(sprintf('Expected an HttpException with status %d, but nothing was thrown.', $status));
    }

    protected function countAudit(string $action): int
    {
        $statement = $this->core->prepare('SELECT COUNT(*) FROM audit_log WHERE action = :action');
        $statement->execute(['action' => $action]);

        return (int) $statement->fetchColumn();
    }
}
