<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Core\App;
use Gfh\Core\Console\MakeOrgCommand;
use Gfh\Core\Http\Request;
use Gfh\Core\Support\Services;
use Gfh\Tests\Support\DatabaseTestCase;

/**
 * Batch 8.2b: bin/make-org.php --from-skeleton. The command runs in a temporary root that holds a copy of the real
 * orgs/_skeleton, so no real organization folder is ever created; the registry is the test core database.
 */
final class MakeOrgFromSkeletonTest extends DatabaseTestCase
{
    private string $tempRoot;
    private App $tempApp;
    private string|false $previousConnection;
    private bool $bootedRealApp = false;
    private string $orgUrl;
    /** @var list<string> */
    private array $lines = [];
    /** @var list<string> */
    private array $migrated = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousConnection = getenv('GFH_ORG_CONNECTION');
        $this->orgUrl = (string) getenv('GFH_TEST_ORG_URL');

        $this->tempRoot = sys_get_temp_dir() . '/gfh-mkorg-' . bin2hex(random_bytes(4));
        mkdir($this->tempRoot . '/orgs', 0777, true);
        file_put_contents($this->tempRoot . '/.env', "APP_ENV=testing\nAPP_KEY=base64:" . base64_encode(random_bytes(32)) . "\n");
        self::copyTree(dirname(__DIR__, 2) . '/orgs/_skeleton', $this->tempRoot . '/orgs/_skeleton');

        $this->tempApp = App::boot($this->tempRoot);
        restore_error_handler();
        restore_exception_handler();
    }

    protected function tearDown(): void
    {
        if ($this->bootedRealApp) {
            restore_error_handler();
            restore_exception_handler();
        }
        putenv($this->previousConnection === false ? 'GFH_ORG_CONNECTION' : 'GFH_ORG_CONNECTION=' . $this->previousConnection);
        self::removeTree($this->tempRoot);
        parent::tearDown();
    }

    private static function copyTree(string $from, string $to): void
    {
        mkdir($to, 0777, true);
        foreach (scandir($from) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            is_dir($from . '/' . $name) ? self::copyTree($from . '/' . $name, $to . '/' . $name) : copy($from . '/' . $name, $to . '/' . $name);
        }
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            is_dir($path . '/' . $name) && !is_link($path . '/' . $name) ? self::removeTree($path . '/' . $name) : unlink($path . '/' . $name);
        }
        rmdir($path);
    }

    /** @param list<string> $arguments */
    private function makeOrg(array $arguments, ?\Closure $migrate = null): int
    {
        putenv('GFH_ORG_CONNECTION=' . $this->orgUrl);
        $this->lines = [];
        $command = new MakeOrgCommand(
            $this->tempApp,
            $this->core,
            null,
            function (bool $isError, string $line): void {
                $this->lines[] = ($isError ? 'ERR: ' : 'OUT: ') . $line;
            },
            $migrate ?? function (string $slug): int {
                $this->migrated[] = $slug;

                return 0;
            },
        );

        return $command->run(['bin/make-org.php', ...$arguments]);
    }

    private function printed(): string
    {
        return implode("\n", $this->lines);
    }

    private function registered(string $slug): bool
    {
        $statement = $this->core->prepare('SELECT count(*) FROM organizations WHERE slug = ?');
        $statement->execute([$slug]);

        return (int) $statement->fetchColumn() === 1;
    }

    private function newSlug(): string
    {
        return 'second-' . bin2hex(random_bytes(3));
    }

    // ------------------------------------------------------------------ the copy and the registration

    public function testItCopiesTheSkeletonRegistersTheOrganizationAndPrintsTheRemainingSteps(): void
    {
        $slug = $this->newSlug();
        $exit = $this->makeOrg(['--slug=' . $slug, '--name=Second Shop', '--short-name=Second', '--from-skeleton', '--no-test']);

        $this->assertSame(0, $exit, $this->printed());
        $this->assertTrue($this->registered($slug));
        $this->assertFileExists($this->tempRoot . '/orgs/' . $slug . '/README.md');
        $this->assertFileExists($this->tempRoot . '/orgs/' . $slug . '/shop/README.md');
        $this->assertFileExists($this->tempRoot . '/orgs/' . $slug . '/branding.example.json');
        $this->assertSame([], glob($this->tempRoot . '/orgs/' . $slug . '/*/*.php') ?: [], 'no live override was copied');
        $this->assertDirectoryExists($this->tempRoot . '/orgs/_skeleton', 'the skeleton itself is untouched');

        $output = $this->printed();
        $this->assertStringContainsString('Remaining steps:', $output);
        $this->assertStringContainsString('not created by this command', $output, 'it says it does not create the database or roles');
        $this->assertStringContainsString('php bin/migrate.php --org=' . $slug, $output);
        $this->assertStringContainsString('database/roles/04_grant_org.sql', $output);
        $this->assertStringContainsString('bin/create-user.php', $output);
        $this->assertStringContainsString('so the migrations were not run', $output);
        $this->assertSame([], $this->migrated, '--no-test means the connection was not tested, so nothing was migrated');
        $this->assertStringNotContainsString($this->orgUrl, $output, 'the connection string is never printed');
    }

    public function testItRunsTheOrganizationMigrationsWhenTheConnectionWorks(): void
    {
        $slug = $this->newSlug();
        $exit = $this->makeOrg(['--slug=' . $slug, '--name=Second Shop', '--from-skeleton']);

        $this->assertSame(0, $exit, $this->printed());
        $this->assertSame([$slug], $this->migrated);
        $this->assertStringContainsString('connected', $this->printed());
        $this->assertStringNotContainsString('php bin/migrate.php --org=', $this->printed(), 'a step that is done is not listed again');
        $this->assertStringContainsString('Remaining steps:', $this->printed());
    }

    public function testItExitsOneWhenTheMigrationFailsAndStillPrintsTheRemainingSteps(): void
    {
        $slug = $this->newSlug();
        $exit = $this->makeOrg(['--slug=' . $slug, '--name=Second Shop', '--from-skeleton'], static fn (string $s): int => 3);

        $this->assertSame(1, $exit);
        $this->assertTrue($this->registered($slug), 'the organization stays registered');
        $this->assertDirectoryExists($this->tempRoot . '/orgs/' . $slug);
        $this->assertStringContainsString('did not finish (exit code 3)', $this->printed());
        $this->assertStringContainsString('php bin/migrate.php --org=' . $slug, $this->printed());

        $slug = $this->newSlug();
        $exit = $this->makeOrg(['--slug=' . $slug, '--name=Second Shop', '--from-skeleton'], static function (string $s): int {
            throw new \RuntimeException('permission denied for schema public');
        });
        $this->assertSame(1, $exit);
        $this->assertStringContainsString('permission denied for schema public', $this->printed());
        $this->assertStringContainsString('Remaining steps:', $this->printed());
    }

    // ------------------------------------------------------------------ what it refuses

    public function testItRefusesAnExistingFolderBeforeRegisteringAnything(): void
    {
        $slug = $this->newSlug();
        mkdir($this->tempRoot . '/orgs/' . $slug . '/shop', 0777, true);
        file_put_contents($this->tempRoot . '/orgs/' . $slug . '/shop/home.php', 'MINE');

        $exit = $this->makeOrg(['--slug=' . $slug, '--name=Second Shop', '--from-skeleton', '--no-test']);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('already exists', $this->printed());
        $this->assertFalse($this->registered($slug), 'nothing was registered');
        $this->assertSame('MINE', file_get_contents($this->tempRoot . '/orgs/' . $slug . '/shop/home.php'));
        $this->assertFileDoesNotExist($this->tempRoot . '/orgs/' . $slug . '/README.md', 'nothing was copied into it');
        $this->assertSame([], $this->migrated);
    }

    public function testItRefusesABadSlugWithoutCreatingAnything(): void
    {
        foreach (['Bad_Slug', '../evil', 'x'] as $slug) {
            $exit = $this->makeOrg(['--slug=' . $slug, '--name=Bad', '--from-skeleton', '--no-test']);
            $this->assertSame(1, $exit, $slug);
        }
        $this->assertSame(['_skeleton'], array_values(array_diff(scandir($this->tempRoot . '/orgs') ?: [], ['.', '..'])));
    }

    public function testItNeverTakesAConnectionOnTheCommandLine(): void
    {
        $slug = $this->newSlug();
        $exit = $this->makeOrg(['--slug=' . $slug, '--name=Second Shop', '--from-skeleton', '--connection=postgresql://u:secret-pass@127.0.0.1/db']);

        $this->assertSame(2, $exit);
        $this->assertFalse($this->registered($slug));
        $this->assertDirectoryDoesNotExist($this->tempRoot . '/orgs/' . $slug);
        $this->assertStringNotContainsString('secret-pass', $this->printed());
    }

    public function testTheFlagCannotBeCombinedWithUpdateConnection(): void
    {
        $this->assertSame(2, $this->makeOrg(['--slug=' . $this->newSlug(), '--update-connection', '--from-skeleton', '--no-test']));
    }

    public function testWithoutTheNewFlagItWorksAsBeforeAndCreatesNoFolder(): void
    {
        $slug = $this->newSlug();
        $exit = $this->makeOrg(['--slug=' . $slug, '--name=Plain Shop', '--no-test']);

        $this->assertSame(0, $exit, $this->printed());
        $this->assertTrue($this->registered($slug));
        $this->assertDirectoryDoesNotExist($this->tempRoot . '/orgs/' . $slug);
        $this->assertStringContainsString('Next steps:', $this->printed());
        $this->assertSame([], $this->migrated, 'the old path does not migrate by itself');
    }

    // ------------------------------------------------------------------ the organization it makes

    public function testASecondOrganizationFromTheSkeletonAnswersItsShopWithItsOwnBrandingAndNoDemoOverride(): void
    {
        $slug = $this->newSlug();
        $exit = $this->makeOrg([
            '--slug=' . $slug, '--name=Second Shop Ltd', '--short-name=Second', '--from-skeleton', '--no-test',
            '--branding={"primary":"#0B6E4F","primary_strong":"#084C38"}',
        ]);
        $this->assertSame(0, $exit, $this->printed());

        $app = App::boot(dirname(__DIR__, 2));
        $this->bootedRealApp = true;
        $services = fn (): Services => new Services($app, false, $this->core, $this->org);

        $products = $app->handle(new Request('GET', '/' . $slug . '/shop/products'), $services());
        $this->assertSame(200, $products->status());
        $this->assertStringContainsString('Second Shop Ltd', $products->body(), 'its own name');
        $this->assertStringContainsString('/' . $slug . '/branding.css', $products->body());
        $this->assertStringNotContainsString('Welcome to the demonstration shop', $products->body());

        $branding = $app->handle(new Request('GET', '/' . $slug . '/branding.css'), $services());
        $this->assertSame(200, $branding->status());
        $this->assertStringContainsString('#0b6e4f', strtolower($branding->body()), 'its own primary color');
        $this->assertStringContainsString('#084c38', strtolower($branding->body()));

        $home = $app->handle(new Request('GET', '/' . $slug . '/shop'), $services());
        $this->assertSame(200, $home->status());
        $this->assertStringContainsString('Second Shop Ltd', $home->body());
        $this->assertStringNotContainsString('Welcome to the demonstration shop', $home->body(), 'the demo override does not reach it');
        $this->assertStringNotContainsString('demonstration', strtolower($home->body()));
    }
}
