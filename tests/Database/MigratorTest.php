<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Core\Database\MigrationException;
use Gfh\Core\Database\MigrationSet;
use Gfh\Core\Database\Migrator;
use Gfh\Tests\Support\DatabaseTestCase;

/** Stage 1 acceptance rules, checked on a scratch scope inside the rolled-back test transaction. */
final class MigratorTest extends DatabaseTestCase
{
    private string $directory = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = sys_get_temp_dir() . '/gfh-migrator-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
        file_put_contents($this->directory . '/0001_first.sql', "CREATE TABLE zz_migrator_one (id integer);\n");
        file_put_contents($this->directory . '/0002_second.sql', "CREATE TABLE zz_migrator_two (id integer);\n");
    }

    protected function tearDown(): void
    {
        // Only ever delete inside the scratch folder this test created (it is empty if setUp was skipped).
        if ($this->directory !== '' && str_contains($this->directory, 'gfh-migrator-') && is_dir($this->directory)) {
            foreach (glob($this->directory . '/*') ?: [] as $file) {
                unlink($file);
            }
            @rmdir($this->directory);
        }
        parent::tearDown();
    }

    public function testItAppliesEachMigrationOnceAndRunningAgainChangesNothing(): void
    {
        $set = new MigrationSet('zz_test', $this->directory);
        $migrator = new Migrator();

        $this->assertSame([1, 2], $migrator->migrate($this->core, $set));
        $this->assertSame([], $migrator->migrate($this->core, $set));
        $this->assertSame(2, $migrator->currentVersion($this->core, 'zz_test'));
        $this->assertSame(2, (int) $this->core->query("SELECT COUNT(*) FROM schema_migrations WHERE scope = 'zz_test'")->fetchColumn());
    }

    public function testAModifiedAppliedMigrationIsRefused(): void
    {
        $set = new MigrationSet('zz_test', $this->directory);
        $migrator = new Migrator();
        $migrator->migrate($this->core, $set);

        file_put_contents($this->directory . '/0001_first.sql', "CREATE TABLE zz_migrator_one (id integer, extra text);\n");

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/changed after it was applied/');
        $migrator->migrate($this->core, $set);
    }

    public function testLineEndingsDoNotCountAsAChange(): void
    {
        $set = new MigrationSet('zz_test', $this->directory);
        $migrator = new Migrator();
        $migrator->migrate($this->core, $set);

        file_put_contents($this->directory . '/0001_first.sql', "CREATE TABLE zz_migrator_one (id integer);\r\n");

        $this->assertSame([], $migrator->migrate($this->core, $set));
    }

    public function testAMissingAppliedFileIsRefused(): void
    {
        $set = new MigrationSet('zz_test', $this->directory);
        $migrator = new Migrator();
        $migrator->migrate($this->core, $set);
        unlink($this->directory . '/0002_second.sql');

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/file is missing/');
        $migrator->migrate($this->core, $set);
    }

    public function testANewMigrationOlderThanTheLatestAppliedIsRefused(): void
    {
        // Only 0002 exists when the first run happens, so version 2 becomes the latest applied.
        unlink($this->directory . '/0001_first.sql');
        $set = new MigrationSet('zz_order', $this->directory);
        $migrator = new Migrator();
        $this->assertSame([2], $migrator->migrate($this->core, $set));

        // A file numbered 0001 showing up afterwards would run out of order, so it is refused.
        file_put_contents($this->directory . '/0001_late.sql', "CREATE TABLE zz_migrator_late (id integer);\n");

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/older than the latest applied/');
        $migrator->migrate($this->core, $set);
    }

    public function testAFailingMigrationRollsBackAndIsNotRecorded(): void
    {
        file_put_contents($this->directory . '/0003_broken.sql', "CREATE TABLE zz_migrator_ok (id integer);\nSELECT * FROM table_that_does_not_exist;\n");
        $set = new MigrationSet('zz_test', $this->directory);
        $migrator = new Migrator();

        try {
            $migrator->migrate($this->core, $set);
            $this->fail('A failing migration should stop the run.');
        } catch (MigrationException $exception) {
            $this->assertStringContainsString('0003_broken.sql', $exception->getMessage());
        }

        $this->assertSame(2, $migrator->currentVersion($this->core, 'zz_test'));
        $this->assertNull($this->core->query("SELECT to_regclass('zz_migrator_ok')")->fetchColumn() ?: null);
    }

    public function testBadFileNamesAreRejected(): void
    {
        file_put_contents($this->directory . '/3_wrong.sql', "SELECT 1;\n");

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/not named like/');
        (new Migrator())->migrate($this->core, new MigrationSet('zz_test', $this->directory));
    }
}
