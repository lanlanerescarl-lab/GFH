<?php

declare(strict_types=1);

namespace Gfh\Tests\Unit;

use Gfh\Core\Config\Config;
use Gfh\Core\Config\ConfigException;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testParsesTheSupportedSyntax(): void
    {
        // A nowdoc keeps every backslash and dollar sign exactly as it would appear in a real .env file.
        $env = <<<'ENV'
# a comment
APP_ENV=local
export APP_URL=http://localhost:8000   # trailing comment
SPACED="two words # not a comment"
ESCAPED="line1\nline2 \"quoted\" \\ \$HOME"
LITERAL='keep \n as is'
EMPTY=

ENV;
        // Windows editors save a byte order mark and CRLF line endings.
        $values = Config::parseEnv("\xEF\xBB\xBF" . str_replace("\n", "\r\n", $env));

        $this->assertSame('local', $values['APP_ENV']);
        $this->assertSame('http://localhost:8000', $values['APP_URL']);
        $this->assertSame('two words # not a comment', $values['SPACED']);
        $this->assertSame("line1\nline2 \"quoted\" \\ \$HOME", $values['ESCAPED']);
        $this->assertSame('keep \\n as is', $values['LITERAL']);
        $this->assertSame('', $values['EMPTY']);
    }

    public function testABadLineIsReportedWithItsNumber(): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessageMatches('/line 2/');
        Config::parseEnv("GOOD=1\nthis is not valid\n");
    }

    public function testAnUnterminatedQuoteIsRefused(): void
    {
        $this->expectException(ConfigException::class);
        Config::parseEnv('BROKEN="never closed');
    }

    public function testTypedGettersAndDefaults(): void
    {
        $config = new Config(['A' => 'yes', 'B' => '0', 'C' => '42', 'D' => 'abc', 'E' => '']);

        $this->assertTrue($config->bool('A'));
        $this->assertFalse($config->bool('B', true));
        $this->assertTrue($config->bool('E', true), 'an empty value falls back to the default');
        $this->assertSame(42, $config->int('C'));
        $this->assertSame(7, $config->int('D', 7));
        $this->assertSame('x', $config->string('MISSING', 'x'));
    }

    public function testRequiredSettingsMustBePresent(): void
    {
        $this->expectException(ConfigException::class);
        (new Config(['A' => '  ']))->required('A');
    }

    public function testTheEnvironmentDefaultsToTheSafeChoice(): void
    {
        $this->assertSame('production', (new Config([]))->environment());
        $this->assertSame('production', (new Config(['APP_ENV' => 'prodution']))->environment(), 'a typo counts as production');
        $this->assertSame('local', (new Config(['APP_ENV' => 'local']))->environment());
        $this->assertFalse((new Config(['APP_ENV' => 'production', 'APP_DEBUG' => 'true']))->debug());
        $this->assertTrue((new Config(['APP_ENV' => 'local', 'APP_DEBUG' => 'true']))->debug());
    }
}
