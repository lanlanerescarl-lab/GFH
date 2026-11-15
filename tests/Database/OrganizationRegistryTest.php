<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Core\App;
use Gfh\Core\Console\MakeOrgCommand;
use Gfh\Core\Database\ConnectionString;
use Gfh\Core\Repository\OrganizationRepository;
use Gfh\Core\Security\SecretBox;
use Gfh\Core\Security\SecretBoxException;
use Gfh\Core\Service\OrganizationRegistry;
use Gfh\Tests\Support\DatabaseTestCase;

final class OrganizationRegistryTest extends DatabaseTestCase
{
    private ?string $tempRoot = null;
    private string|false $previousEnvConnection = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousEnvConnection = getenv('GFH_ORG_CONNECTION');
        putenv('GFH_ORG_CONNECTION');
    }

    protected function tearDown(): void
    {
        if ($this->previousEnvConnection === false) {
            putenv('GFH_ORG_CONNECTION');
        } else {
            putenv('GFH_ORG_CONNECTION=' . $this->previousEnvConnection);
        }

        if ($this->tempRoot !== null && is_dir($this->tempRoot)) {
            $envFile = $this->tempRoot . '/.env';
            if (is_file($envFile)) {
                @unlink($envFile);
            }
            @rmdir($this->tempRoot);
            $this->tempRoot = null;
        }

        parent::tearDown();
    }

    public function testRegisterRejectsDuplicateSlug(): void
    {
        $box = SecretBox::fromEncodedKey('base64:' . base64_encode(random_bytes(32)));
        $registry = new OrganizationRegistry(new OrganizationRepository($this->core), $box);
        $connection = ConnectionString::parse('postgresql://gfh_app:first-secret@127.0.0.1:5432/gfh_org_test?sslmode=prefer');

        $registry->register('dup-org', 'Duplicate Org', 'DUP', $connection);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('An organization with the slug "dup-org" already exists.');
        $registry->register('dup-org', 'Another Org', 'AN', $connection);
    }

    public function testUpdateConnectionReencryptsWithNewKeyAndPreservesOrganizationState(): void
    {
        $repository = new OrganizationRepository($this->core);
        $oldBox = SecretBox::fromEncodedKey('base64:' . base64_encode(random_bytes(32)));
        $newBox = SecretBox::fromEncodedKey('base64:' . base64_encode(random_bytes(32)));

        $oldConnection = ConnectionString::parse('postgresql://gfh_app:old-secret@127.0.0.1:5432/gfh_org_test?sslmode=prefer');
        $newConnection = ConnectionString::parse('postgresql://gfh_app:new-secret@127.0.0.1:5432/gfh_org_test?sslmode=require');

        $created = (new OrganizationRegistry($repository, $oldBox))->register(
            'rot-org',
            'Rotation Org',
            'ROT',
            $oldConnection,
            'tenant_rot',
            ['primary' => '#3182BD'],
            ['batches', 'cold_chain'],
        );
        $repository->setMigrationVersion($created->id, 6);
        $member = $this->kit->member($created, 'rot-admin@example.test', 'admin');

        $updated = (new OrganizationRegistry($repository, $newBox))->updateConnection('rot-org', $newConnection);

        $this->assertSame($created->id, $updated->id);
        $this->assertSame('rot-org', $updated->slug);
        $this->assertSame('Rotation Org', $updated->name);
        $this->assertSame('ROT', $updated->shortName);
        $this->assertSame('tenant_rot', $updated->dbSchema);
        $this->assertSame(['primary' => '#3182BD'], $updated->branding);
        $this->assertSame(6, $updated->migrationVersion);
        $this->assertSame(['batches', 'cold_chain'], $repository->enabledFeatureKeys($updated->id));
        $this->assertNotNull($this->kit->permissions->activeMembership($member, $updated));
        $this->assertSame($newConnection->toUrl(), $newBox->decrypt($updated->connectionEncrypted));

        try {
            $oldBox->decrypt($updated->connectionEncrypted);
            $this->fail('Old key should no longer decrypt the rotated connection.');
        } catch (SecretBoxException) {
            $this->addToAssertionCount(1);
        }

        $schemaChanged = (new OrganizationRegistry($repository, $newBox))->updateConnection('rot-org', $newConnection, 'tenant_rot_v2');
        $this->assertSame('tenant_rot_v2', $schemaChanged->dbSchema);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('No organization with the slug "missing-org" is registered.');
        (new OrganizationRegistry($repository, $newBox))->updateConnection('missing-org', $newConnection);
    }

    public function testMakeOrgCommandUpdatesConnectionWithoutLeakingSecretAndRefusesCliFlag(): void
    {
        $oldKey = 'base64:' . base64_encode(random_bytes(32));
        $newKey = 'base64:' . base64_encode(random_bytes(32));
        $oldBox = SecretBox::fromEncodedKey($oldKey);
        $newBox = SecretBox::fromEncodedKey($newKey);

        $repository = new OrganizationRepository($this->core);
        $created = (new OrganizationRegistry($repository, $oldBox))->register(
            'cli-org',
            'CLI Org',
            'CLI',
            ConnectionString::parse('postgresql://gfh_app:old-pass@127.0.0.1:5432/gfh_org_test?sslmode=prefer'),
            'custom_schema',
            ['primary' => '#08519C'],
            ['batches'],
        );
        $repository->setMigrationVersion($created->id, 6);

        $secretUrl = 'postgresql://gfh_app:super-secret-pass@127.0.0.1:5432/gfh_org_test?sslmode=require';
        $app = $this->bootTempApp($newKey);

        $messages = [];
        $command = new MakeOrgCommand(
            $app,
            $this->core,
            null,
            static function (bool $isError, string $line) use (&$messages): void {
                $messages[] = ($isError ? 'ERR: ' : 'OUT: ') . $line;
            },
        );

        // Passing --connection on the command line must be refused.
        $cliFlagExit = $command->run(['bin/make-org.php', '--slug=cli-org', '--update-connection', '--no-test', '--connection=' . $secretUrl]);
        $this->assertSame(2, $cliFlagExit);

        // Using GFH_ORG_CONNECTION succeeds and preserves non-connection fields.
        putenv('GFH_ORG_CONNECTION=' . $secretUrl);
        $okExit = $command->run(['bin/make-org.php', '--slug=cli-org', '--update-connection', '--no-test']);
        $this->assertSame(0, $okExit);

        $reloaded = $repository->findBySlug('cli-org');
        $this->assertNotNull($reloaded);
        $this->assertSame('custom_schema', $reloaded->dbSchema);
        $this->assertSame(['primary' => '#08519C'], $reloaded->branding);
        $this->assertSame(6, $reloaded->migrationVersion);
        $this->assertSame(['batches'], $repository->enabledFeatureKeys($reloaded->id));
        $this->assertSame($secretUrl, $newBox->decrypt($reloaded->connectionEncrypted));

        // Unknown slug exits non-zero.
        $missingExit = $command->run(['bin/make-org.php', '--slug=no-such-org', '--update-connection', '--no-test']);
        $this->assertSame(1, $missingExit);

        // Invalid GFH_ORG_CONNECTION exits non-zero without echoing the raw value.
        putenv('GFH_ORG_CONNECTION=not-a-valid-secret-url-with-password');
        $invalidExit = $command->run(['bin/make-org.php', '--slug=cli-org', '--update-connection', '--no-test']);
        $this->assertSame(1, $invalidExit);

        $joinedOutput = implode("\n", $messages);
        $this->assertStringNotContainsString('super-secret-pass', $joinedOutput);
        $this->assertStringNotContainsString($secretUrl, $joinedOutput);
        $this->assertStringNotContainsString('not-a-valid-secret-url-with-password', $joinedOutput);

        // Prompt callback works when GFH_ORG_CONNECTION is unset.
        putenv('GFH_ORG_CONNECTION');
        $promptedUrl = 'postgresql://gfh_app:prompted-secret@127.0.0.1:5432/gfh_org_test?sslmode=prefer';
        $promptCommand = new MakeOrgCommand(
            $app,
            $this->core,
            static fn (): string => $promptedUrl,
            static function (bool $isError, string $line) use (&$messages): void {
                $messages[] = ($isError ? 'ERR: ' : 'OUT: ') . $line;
            },
        );
        $promptExit = $promptCommand->run(['bin/make-org.php', '--slug=cli-org', '--update-connection', '--no-test']);
        $this->assertSame(0, $promptExit);

        $afterPrompt = $repository->findBySlug('cli-org');
        $this->assertNotNull($afterPrompt);
        $this->assertSame($promptedUrl, $newBox->decrypt($afterPrompt->connectionEncrypted));
        $this->assertStringNotContainsString('prompted-secret', implode("\n", $messages));
    }

    private function bootTempApp(string $appKey): App
    {
        $this->tempRoot = sys_get_temp_dir() . '/gfh-org-cmd-' . bin2hex(random_bytes(4));
        mkdir($this->tempRoot, 0777, true);
        file_put_contents($this->tempRoot . '/.env', "APP_ENV=testing\nAPP_KEY=" . $appKey . "\n");

        $app = App::boot($this->tempRoot);
        restore_error_handler();
        restore_exception_handler();

        return $app;
    }
}
