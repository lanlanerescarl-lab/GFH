<?php

declare(strict_types=1);

namespace Gfh\Tests\Unit;

use Gfh\Core\Auth\PasswordPolicy;
use Gfh\Core\Http\HttpException;
use PHPUnit\Framework\TestCase;

final class PasswordPolicyTest extends TestCase
{
    public function testHashesVerifyAndAreNotThePassword(): void
    {
        $policy = new PasswordPolicy();
        $hash = $policy->hash('a-long-password-1');

        $this->assertNotSame('a-long-password-1', $hash);
        $this->assertTrue($policy->verify('a-long-password-1', $hash));
        $this->assertFalse($policy->verify('a-long-password-2', $hash));
        $this->assertFalse($policy->needsRehash($hash));
    }

    public function testRulesAreEnforcedWithFieldLevelMessages(): void
    {
        $policy = new PasswordPolicy();

        foreach (['short', str_repeat('x', 73), '           ', 'ana@example.test'] as $bad) {
            try {
                $policy->assertValid($bad, 'ana@example.test');
                $this->fail('Should have refused: ' . $bad);
            } catch (HttpException $exception) {
                $this->assertSame(422, $exception->status());
                $this->assertArrayHasKey('password', $exception->fields());
            }
        }

        $policy->assertValid('a-long-password-1', 'ana@example.test');
        $this->addToAssertionCount(1);
    }
}
