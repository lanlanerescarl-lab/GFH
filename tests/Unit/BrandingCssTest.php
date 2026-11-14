<?php

declare(strict_types=1);

namespace Gfh\Tests\Unit;

use Gfh\Core\View\BrandingCss;
use PHPUnit\Framework\TestCase;

final class BrandingCssTest extends TestCase
{
    public function testNoBrandingGivesAStylesheetWithNoVariables(): void
    {
        $built = BrandingCss::build([]);

        $this->assertStringNotContainsString(':root', $built['css']);
        $this->assertSame([], $built['ignored']);
    }

    public function testValidValuesBecomeVariablesAndColorsAreNormalized(): void
    {
        $built = BrandingCss::build([
            'primary' => '#08519C',
            'primary_strong' => '#036',
            'font_heading' => 'Source Serif 4',
            'radius_card' => '12px',
            'radius_control' => 4,
        ]);

        $this->assertSame([], $built['ignored']);
        $this->assertStringContainsString('--color-primary: #08519c;', $built['css']);
        $this->assertStringContainsString('--color-primary-strong: #003366;', $built['css']);
        $this->assertStringContainsString('--font-heading: "Source Serif 4", Georgia, "Times New Roman", serif;', $built['css']);
        $this->assertStringContainsString('--radius-card: 12px;', $built['css']);
        $this->assertStringContainsString('--radius-control: 4px;', $built['css']);
    }

    public function testUnknownKeysAndWrongTypesAreIgnoredAndReported(): void
    {
        $built = BrandingCss::build(['primary' => ['#fff'], 'evil' => 'x', 'radius_card' => 12.5, 'font_body' => 7]);

        $this->assertEqualsCanonicalizing(['primary', 'evil', 'radius_card', 'font_body'], $built['ignored']);
        $this->assertStringNotContainsString(':root', $built['css']);
    }

    public function testCssInjectionThroughAnyValueIsRefused(): void
    {
        $built = BrandingCss::build([
            'primary' => '#fff;}body{display:none',
            'primary_strong' => 'url(http://evil.example/x)',
            'background' => "#ffffff\n",
            'ink' => 'expression(alert(1))',
            'font_body' => 'Arial;}body{background:url(x)',
            'font_heading' => 'Evil"; } x { ',
            'radius_card' => '10px;}',
            'radius_control' => '1e3px',
        ]);

        $this->assertCount(8, $built['ignored']);
        $this->assertStringNotContainsString(':root', $built['css']);
        $this->assertStringNotContainsString('url(', $built['css']);
        $this->assertStringNotContainsString('evil', $built['css']);
        $this->assertStringNotContainsString('<', $built['css']);
        $this->assertSame(1, substr_count($built['css'], '/*'));
    }

    public function testRadiiOutsideZeroToTwentyFourAreRefused(): void
    {
        foreach (['25px', '-1px', '12em', '12', '', 25, -1] as $value) {
            $this->assertSame(['radius_card'], BrandingCss::build(['radius_card' => $value])['ignored'], 'radius ' . json_encode($value));
        }

        $this->assertSame([], BrandingCss::build(['radius_card' => '0px'])['ignored']);
        $this->assertSame([], BrandingCss::build(['radius_card' => 24])['ignored']);
    }

    public function testAPrimaryColorWithTooLittleContrastForWhiteTextIsRefused(): void
    {
        $built = BrandingCss::build(['primary' => '#ffff00', 'primary_strong' => '#f5f5f5']);

        $this->assertEqualsCanonicalizing(['primary', 'primary_strong'], $built['ignored']);
        $this->assertStringNotContainsString('--color-primary', $built['css']);
    }

    public function testTextAndBackgroundAreJudgedAsAPair(): void
    {
        $light = BrandingCss::build(['ink' => '#cccccc']);
        $this->assertSame(['ink'], $light['ignored'], 'light text on the default light background');

        $dark = BrandingCss::build(['background' => '#000000']);
        $this->assertSame(['background'], $dark['ignored'], 'default dark text on a black background');

        $both = BrandingCss::build(['ink' => '#ffffff', 'background' => '#000000']);
        $this->assertSame([], $both['ignored']);
        $this->assertStringContainsString('--color-ink: #ffffff;', $both['css']);
        $this->assertStringContainsString('--color-bg: #000000;', $both['css']);
    }

    public function testContrastRatioMatchesTheWcagFormula(): void
    {
        $this->assertEqualsWithDelta(21.0, BrandingCss::contrast('#000000', '#ffffff'), 0.01);
        $this->assertEqualsWithDelta(1.0, BrandingCss::contrast('#336699', '#336699'), 0.001);
        $this->assertEqualsWithDelta(BrandingCss::contrast('#fff', '#08519c'), BrandingCss::contrast('#08519c', '#fff'), 0.001);
    }

    public function testTheDefaultTokensPassTheirOwnContrastRules(): void
    {
        $tokens = (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/css/tokens.css');

        $this->assertSame(1, preg_match('/--color-ink: (#[0-9a-f]{6});/', $tokens, $ink));
        $this->assertSame(1, preg_match('/--color-bg: (#[0-9a-f]{6});/', $tokens, $background));
        $this->assertSame(1, preg_match('/--color-primary: (#[0-9a-f]{6});/', $tokens, $primary));
        $this->assertSame(1, preg_match('/--color-primary-strong: (#[0-9a-f]{6});/', $tokens, $strong));

        $this->assertSame('#1a2a3a', $ink[1], 'BrandingCss::DEFAULT_INK must match tokens.css');
        $this->assertSame('#eff3ff', $background[1], 'BrandingCss::DEFAULT_BACKGROUND must match tokens.css');
        $this->assertGreaterThanOrEqual(4.5, BrandingCss::contrast($ink[1], $background[1]));
        $this->assertGreaterThanOrEqual(4.5, BrandingCss::contrast($primary[1], '#ffffff'));
        $this->assertGreaterThanOrEqual(4.5, BrandingCss::contrast($strong[1], '#ffffff'));
    }
}
