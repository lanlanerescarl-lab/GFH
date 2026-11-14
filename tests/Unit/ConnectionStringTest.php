<?php

declare(strict_types=1);

namespace Gfh\Tests\Unit;

use Gfh\Core\Database\ConnectionString;
use Gfh\Core\Security\SecretBox;
use Gfh\Core\Security\SecretBoxException;
use PHPUnit\Framework\TestCase;

final class ConnectionStringTest extends TestCase
{
    public function testParsesAUrlWithEncodedCharacters(): void
    {
        $connection = ConnectionString::parse('postgresql://app.user:p%40ss%3Aw%2Frd@db.example.test:6543/gfh_org?sslmode=require');

        $this->assertSame('app.user', $connection->user);
        $this->assertSame('p@ss:w/rd', $connection->password);
        $this->assertSame('db.example.test', $connection->host);
        $this->assertSame(6543, $connection->port);
        $this->assertSame('gfh_org', $connection->database);
        $this->assertSame('require', $connection->sslMode);
        $this->assertSame('pgsql:host=db.example.test;port=6543;dbname=gfh_org;sslmode=require', $connection->dsn());
    }

    public function testTheNormalisedUrlParsesBackToTheSameValues(): void
    {
        $original = ConnectionString::fromParts('127.0.0.1', 5432, 'gfh_core_x', 'u', 'we ird#pw/:@', 'disable');
        $again = ConnectionString::parse($original->toUrl());

        $this->assertSame($original->password, $again->password);
        $this->assertSame($original->port, $again->port);
        $this->assertSame(5432, $again->port);
    }

    public function testDescribeNeverContainsThePassword(): void
    {
        $connection = ConnectionString::parse('postgresql://user:SuperSecret@localhost/db');

        $this->assertStringNotContainsString('SuperSecret', $connection->describe());
    }

    public function testBadInputIsRefusedWithoutEchoingTheSecret(): void
    {
        foreach ([
            'mysql://user:SuperSecret@localhost/db',
            'postgresql://user:SuperSecret@localhost/db?options=-c%20x',
            'postgresql://user:SuperSecret@localhost/db;host=evil',
            'postgresql://user:SuperSecret@/db',
            'postgresql://:SuperSecret@localhost/db',
            'postgresql://user:SuperSecret@local host/db',
            'postgresql://user:SuperSecret@localhost/db?sslmode=maybe',
        ] as $bad) {
            try {
                ConnectionString::parse($bad);
                $this->fail('Should have refused: ' . preg_replace('/:[^:@]+@/', ':***@', $bad));
            } catch (\InvalidArgumentException $exception) {
                $this->assertStringNotContainsString('SuperSecret', $exception->getMessage());
            }
        }
    }

    public function testSecretBoxRoundTripsAndRejectsTheWrongKeyOrTampering(): void
    {
        if (!extension_loaded('sodium')) {
            $this->markTestSkipped('The sodium extension is not enabled.');
        }

        $key = 'base64:' . base64_encode(random_bytes(32));
        $box = SecretBox::fromEncodedKey($key);
        $stored = $box->encrypt('postgresql://u:p@h/db');

        $this->assertNotSame('postgresql://u:p@h/db', $stored);
        $this->assertSame('postgresql://u:p@h/db', $box->decrypt($stored));
        $this->assertNotSame($stored, $box->encrypt('postgresql://u:p@h/db'), 'a fresh nonce each time');

        $other = SecretBox::fromEncodedKey('base64:' . base64_encode(random_bytes(32)));
        try {
            $other->decrypt($stored);
            $this->fail('The wrong key must not decrypt.');
        } catch (SecretBoxException) {
            $this->addToAssertionCount(1);
        }

        $tampered = substr($stored, 0, -1) . (substr($stored, -1) === 'a' ? 'b' : 'a');
        $this->expectException(SecretBoxException::class);
        $box->decrypt($tampered);
    }

    public function testABadKeyIsRefused(): void
    {
        if (!extension_loaded('sodium')) {
            $this->markTestSkipped('The sodium extension is not enabled.');
        }

        $this->expectException(SecretBoxException::class);
        SecretBox::fromEncodedKey('base64:' . base64_encode('too short'));
    }
}
