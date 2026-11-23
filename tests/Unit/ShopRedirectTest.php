<?php

declare(strict_types=1);

namespace Gfh\Tests\Unit;

use Gfh\Core\Support\ShopRedirect;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShopRedirectTest extends TestCase
{
    private const BASE = '/demo/shop';

    /** @return array<string, array{0: mixed, 1: ?string}> candidate => accepted value (null when refused) */
    public static function cases(): array
    {
        return [
            'an order page' => ['/demo/shop/orders', '/demo/shop/orders'],
            'checkout' => ['/demo/shop/checkout', '/demo/shop/checkout'],
            'a product page with a query' => ['/demo/shop/products/oak-table?variant=abc', '/demo/shop/products/oak-table?variant=abc'],
            'a filtered order list' => ['/demo/shop/orders?progress_key=ready&page=2', '/demo/shop/orders?progress_key=ready&page=2'],
            'an encoded space in a query' => ['/demo/shop/products?q=oak%20table', '/demo/shop/products?q=oak%20table'],
            'an absolute URL' => ['https://evil.example/demo/shop/orders', null],
            'a URL with the right path on another host' => ['http://evil.example/demo/shop/', null],
            'a protocol-relative address' => ['//evil.example/demo/shop/orders', null],
            'a protocol-relative address that ends in the prefix' => ['//evil.example/demo/shop/', null],
            'a leading backslash' => ['/\\evil.example', null],
            'a backslash inside' => ['/demo/shop/\\evil.example', null],
            'an encoded slash pair' => ['/demo/shop/%2F%2Fevil.example', null],
            'an encoded backslash' => ['/demo/shop/%5Cevil.example', null],
            'an encoded dot segment' => ['/demo/shop/%2e%2e/admin', null],
            'a double-encoded dot segment' => ['/demo/shop/%252e%252e/admin', null],
            'a triple-encoded dot segment' => ['/demo/shop/%25252e%25252e/admin', null],
            'a plain dot segment' => ['/demo/shop/../admin', null],
            'the Admin' => ['/demo/admin', null],
            'an Admin path' => ['/demo/admin/orders', null],
            'the bare site root' => ['/', null],
            'the Shop without a trailing path' => ['/demo/shop', null],
            'another organization' => ['/other/shop/orders', null],
            'a look-alike prefix' => ['/demo/shopping/orders', null],
            'a different case' => ['/DEMO/shop/orders', null],
            'a javascript address' => ['javascript:alert(1)', null],
            'a data address' => ['data:text/html,x', null],
            'a newline' => ["/demo/shop/orders\nLocation: https://evil.example", null],
            'a carriage return' => ["/demo/shop/orders\r", null],
            'an encoded newline' => ['/demo/shop/orders%0d%0aSet-Cookie:x=1', null],
            'a null byte' => ["/demo/shop/orders\0", null],
            'an encoded null byte' => ['/demo/shop/orders%00', null],
            'a space' => ['/demo/shop/or ders', null],
            'a fragment' => ['/demo/shop/orders#x', null],
            'a colon' => ['/demo/shop/a:b', null],
            'a non-ASCII look-alike slash' => ["/demo/shop/\u{2215}evil", null],
            'the sign-in page (a loop)' => ['/demo/shop/sign-in', null],
            'the sign-in page with a query' => ['/demo/shop/sign-in?next=/demo/shop/orders', null],
            'the register page (a loop)' => ['/demo/shop/register', null],
            'empty' => ['', null],
            'an array' => [['/demo/shop/orders'], null],
            'null' => [null, null],
            'a number' => [5, null],
            'too long' => ['/demo/shop/' . str_repeat('a', 400), null],
        ];
    }

    #[DataProvider('cases')]
    public function testOnlyAPathInsideThisShopIsAccepted(mixed $candidate, ?string $expected): void
    {
        $this->assertSame($expected, ShopRedirect::safeNext($candidate, self::BASE));
    }

    public function testTheInstallFolderIsPartOfTheShopBase(): void
    {
        $this->assertSame('/gfh/demo/shop/orders', ShopRedirect::safeNext('/gfh/demo/shop/orders', '/gfh/demo/shop'));
        $this->assertNull(ShopRedirect::safeNext('/demo/shop/orders', '/gfh/demo/shop'));
    }
}
