<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Core\App;
use Gfh\Core\Auth\Session;
use Gfh\Core\Auth\StaffUser;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Support\Services;
use Gfh\Core\Tenancy\Organization;
use Gfh\Core\View\PosServiceWorker;
use Gfh\Tests\Support\DatabaseTestCase;
use Gfh\Tests\Support\TestKit;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Batch 7.2: the POS service worker served by PHP at /{org}/pos/sw.js.
 *
 * Every request goes through the real App + Router + core/routes.php. The worker's behavior in a browser (install,
 * caching, offline start) is NOT proven here; this proves what the server sends: the headers that make the scope work,
 * a cache name and a list of only same-origin, versioned asset addresses, and no secret anywhere in the source.
 */
final class SwOfflineTest extends DatabaseTestCase
{
    private App $app;
    private Organization $org1;
    private StaffUser $cashier;
    private bool $booted = false;
    /** @var list<string> */
    private array $madeFolders = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = App::boot(dirname(__DIR__, 2));
        $this->booted = true;
        $this->org1 = $this->kit->organization('sw-a-' . bin2hex(random_bytes(3)));
        $this->cashier = $this->kit->staff('casey@sw-a.test', TestKit::PASSWORD, null, 'active', 'Casey Cashier');
        $this->kit->join($this->org1, $this->cashier, 'cashier');
        $this->shop->branch('S1');
    }

    protected function tearDown(): void
    {
        foreach ($this->madeFolders as $folder) {
            $this->removeTree($folder);
        }
        if ($this->booted) {
            restore_error_handler();
            restore_exception_handler();
        }
        parent::tearDown();
    }

    // ------------------------------------------------------------------ helpers

    /** @return array{services: Services, session: Session} */
    private function desk(?StaffUser $actor): array
    {
        $session = Session::inMemory('staff', $this->app->config(), $actor === null ? [] : ['user_id' => $actor->id]);

        return ['services' => new Services($this->app, false, $this->core, $this->org, testSessions: ['staff' => $session]), 'session' => $session];
    }

    private function get(string $path, ?StaffUser $actor = null): Response
    {
        return $this->app->handle(new Request('GET', $path, server: ['REMOTE_ADDR' => '198.51.100.30']), $this->desk($actor)['services']);
    }

    private function slug(): string
    {
        return $this->org1->slug;
    }

    private function worker(?StaffUser $actor = null): Response
    {
        return $this->get('/' . $this->slug() . '/pos/sw.js', $actor);
    }

    /** @return array<string, mixed> the CONFIG object the worker source carries */
    private function config(string $source): array
    {
        $this->assertSame(1, preg_match('/^const CONFIG = (\{.*\});$/m', $source, $match), 'the worker has one CONFIG line');

        return json_decode($match[1], true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<string> */
    private function precache(?StaffUser $actor = null): array
    {
        return $this->config($this->worker($actor)->body())['precache'];
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($path);
    }

    // ------------------------------------------------------------------ the route and its headers

    public function testTheWorkerIsServedWithoutASessionWithTheHeadersThatMakeTheScopeWork(): void
    {
        $response = $this->worker();

        $this->assertSame(200, $response->status());
        $this->assertStringStartsWith('application/javascript', (string) $response->header('Content-Type'));
        $this->assertSame('no-cache', $response->header('Cache-Control'));
        // The page is /{org}/pos with no trailing slash; the script lives at /{org}/pos/sw.js, whose default scope
        // (/{org}/pos/) would not control the page. This header allows the explicit scope the page registers with.
        $this->assertSame('/' . $this->slug() . '/pos', $response->header('Service-Worker-Allowed'));
    }

    public function testAnUnknownOrganizationHasNoWorker(): void
    {
        $this->assertSame(404, $this->get('/no-such-org-' . bin2hex(random_bytes(3)) . '/pos/sw.js')->status());
    }

    public function testThePostMethodIsNotAllowed(): void
    {
        $response = $this->app->handle(new Request('POST', '/' . $this->slug() . '/pos/sw.js'), $this->desk(null)['services']);
        $this->assertContains($response->status(), [404, 405]);
    }

    // ------------------------------------------------------------------ cache name and list

    public function testTheWorkerCarriesACacheNameForThisOrganizationAndAVersion(): void
    {
        $config = $this->config($this->worker()->body());

        $this->assertMatchesRegularExpression('/^gfh-pos-' . preg_quote($this->slug(), '/') . '-v-[0-9a-f]{12}$/', $config['cacheName']);
        $this->assertSame('gfh-pos-' . $this->slug() . '-', $config['cachePrefix']);
        $this->assertSame('gfh-pos-' . $this->slug() . '-media', $config['mediaCacheName']);
        $this->assertSame(400, $config['maxThumbnails']);
        $this->assertSame('/' . $this->slug() . '/pos', $config['shellUrl']);
    }

    public function testThePrecacheListHoldsOnlySameOriginAddressesAndNothingFromTheApi(): void
    {
        $list = $this->precache();

        $this->assertNotSame([], $list);
        foreach ($list as $url) {
            $this->assertStringStartsWith('/', $url, "$url is not a path on this origin");
            $this->assertStringStartsNotWith('//', $url, "$url is protocol-relative, so it names another host");
            $parts = parse_url($url);
            $this->assertIsArray($parts, $url);
            $this->assertArrayNotHasKey('host', $parts, "$url names a host");
            $this->assertArrayNotHasKey('scheme', $parts, "$url names a scheme");
            $this->assertStringNotContainsString('://', $url);
            $this->assertStringNotContainsString('/api/', $url, "$url is under /api/, which is never cached");
        }
        $this->assertSame(array_values(array_unique($list)), $list, 'no address twice');
    }

    public function testThePrecacheListHoldsTheShellAndTheScriptsAndStylesTheShellAndLayoutLoad(): void
    {
        $slug = $this->slug();
        $list = $this->precache();
        $this->assertContains("/$slug/pos", $list, 'the shell page');

        // Everything the rendered shell page asks the browser to load from this site must be kept.
        $page = $this->get("/$slug/pos", $this->cashier);
        $this->assertSame(200, $page->status());
        preg_match_all('~<(?:script|link)\b[^>]*\b(?:src|href)="([^"]+)"~', $page->body(), $found);
        $loaded = array_values(array_filter($found[1], static fn (string $url): bool => str_starts_with($url, '/') && preg_match('~\.(?:js|css)(?:\?|$)|branding\.css~', $url) === 1));
        $this->assertNotSame([], $loaded, 'the page loads scripts and styles');
        foreach ($loaded as $url) {
            $this->assertContains(strtok($url, '?'), $list, "the page loads $url but the worker does not keep it");
        }

        // The app's own modules: every script published under the POS folder.
        $scripts = glob(dirname(__DIR__, 2) . '/public/assets/pos/js/*.js') ?: [];
        $this->assertGreaterThan(20, count($scripts));
        foreach ($scripts as $file) {
            $this->assertContains('/assets/pos/js/' . basename($file), $list, basename($file) . ' is missing from the list');
        }
        foreach (['/assets/pos/js/swclient.js', '/assets/pos/js/idb.js', '/assets/pos/js/outbox.js', '/assets/pos/css/app.css', '/assets/js/shell.js', '/assets/css/base.css', "/$slug/branding.css"] as $must) {
            $this->assertContains($must, $list, $must);
        }
        $fonts = array_values(array_filter($list, static fn (string $url): bool => str_ends_with($url, '.woff2')));
        $this->assertNotSame([], $fonts, 'the fonts fonts.css names are kept');
    }

    public function testTheScriptsInTheListAreTheSameFilesTheTemplatesPublish(): void
    {
        $templates = array_map('basename', glob(dirname(__DIR__, 2) . '/templates/pos/assets/js/*.js') ?: []);
        $published = array_map('basename', glob(dirname(__DIR__, 2) . '/public/assets/pos/js/*.js') ?: []);
        sort($templates);
        sort($published);
        $this->assertSame($templates, $published, 'run bin/publish-assets.php: public/assets/pos/js is out of date');
    }

    // ------------------------------------------------------------------ nothing secret

    public function testTheWorkerSourceHoldsNoTokenNoNameAndNoSessionValue(): void
    {
        $desk = $this->desk($this->cashier);
        $page = $this->app->handle(new Request('GET', '/' . $this->slug() . '/pos'), $desk['services']);
        preg_match('~id="pos-config">(.*?)</script>~s', $page->body(), $match);
        $csrf = (string) (json_decode($match[1], true)['csrf'] ?? '');
        $this->assertNotSame('', $csrf, 'the shell does carry a CSRF token');

        $signedIn = $this->app->handle(new Request('GET', '/' . $this->slug() . '/pos/sw.js'), $desk['services'])->body();
        $anonymous = $this->worker()->body();

        $this->assertSame($anonymous, $signedIn, 'the worker is the same for everyone: nothing about a person is in it');
        $this->assertStringNotContainsString($csrf, $signedIn);
        $this->assertStringNotContainsString('Casey', $signedIn);
        $this->assertStringNotContainsString('casey@sw-a.test', $signedIn);
        $this->assertStringNotContainsString($this->cashier->id, $signedIn);
        $this->assertStringNotContainsString(TestKit::PASSWORD, $signedIn);
        $this->assertSame([], array_filter(array_keys($this->config($signedIn)), static fn (string $key): bool => preg_match('/csrf|token|user|session|password/i', $key) === 1));
    }

    public function testTheWorkerTemplateMakesNoExternalRequestAndKeepsNoSecretInTheCache(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pos/service-worker.js');
        $this->assertDoesNotMatchRegularExpression('~https?://~i', $template);
        $this->assertDoesNotMatchRegularExpression('~importScripts|XMLHttpRequest|WebSocket~', $template);
        $this->assertStringContainsString('sanitizeShell', $template, 'the saved shell is stripped of the token and the name');
        $this->assertDoesNotMatchRegularExpression('~skipWaiting\(|clients\.claim\(~', $template, 'a new version waits for the next full load');
        $this->assertSame(1, preg_match('/^const CONFIG = __CONFIG__;$/m', $template), 'one placeholder, on its own line');
    }

    public function testTheWorkerSourceIsPlainAsciiJavaScript(): void
    {
        $source = $this->worker()->body();
        $this->assertSame(1, preg_match('/^[\x09\x0A\x0D\x20-\x7E]*$/', $source), 'ASCII only, so the declared charset cannot matter');
        $this->assertStringContainsString("self.addEventListener('install'", $source);
        $this->assertStringContainsString("self.addEventListener('activate'", $source);
        $this->assertStringContainsString("self.addEventListener('fetch'", $source);
        $this->assertStringContainsString("self.addEventListener('message'", $source);
    }

    // ------------------------------------------------------------------ the page registers it

    public function testThePageRegistersTheWorkerWithTheExplicitScopeOfThePageItself(): void
    {
        $page = $this->get('/' . $this->slug() . '/pos', $this->cashier);
        preg_match('~id="pos-config">(.*?)</script>~s', $page->body(), $match);
        $posBase = json_decode($match[1], true)['posBase'];
        $this->assertSame('/' . $this->slug() . '/pos', $posBase, 'the page URL has no trailing slash, and so does the scope');

        $client = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pos/assets/js/swclient.js');
        $this->assertStringContainsString('register(`${posBase}/sw.js`, { scope: posBase })', $client);
        $this->assertStringContainsString("addEventListener('load'", $client, 'registered after load');

        $app = (string) file_get_contents(dirname(__DIR__, 2) . '/templates/pos/assets/js/app.js');
        $this->assertStringContainsString("from './swclient.js'", $app);
        $this->assertStringContainsString('registerWorker(config.posBase)', $app);
        $this->assertStringContainsString('await forgetShell(config.posBase)', $app, 'signing out deletes the saved shell');
    }

    // ------------------------------------------------------------------ the builder, on a throwaway tree

    /** @return string a temporary project root with a published POS and a worker template */
    private function miniRoot(): string
    {
        $root = sys_get_temp_dir() . '/gfh-sw-' . bin2hex(random_bytes(4));
        $this->madeFolders[] = $root;
        foreach (['public/assets/css', 'public/assets/js', 'public/assets/fonts', 'public/assets/pos/css', 'public/assets/pos/js', 'templates/pos'] as $folder) {
            mkdir("$root/$folder", 0777, true);
        }
        foreach (['css/tokens.css', 'css/fonts.css', 'css/base.css', 'js/shell.js', 'pos/css/tokens.css', 'pos/css/app.css', 'pos/js/app.js', 'pos/js/store.js'] as $file) {
            file_put_contents("$root/public/assets/$file", "/* $file */");
        }
        file_put_contents("$root/public/assets/css/fonts.css", '@font-face{src:url("../fonts/inter.woff2") format("woff2")} @font-face{src:url(\'../fonts/serif.woff2\')}');
        file_put_contents("$root/templates/pos/service-worker.js", "const CONFIG = __CONFIG__;\n");

        return $root;
    }

    public function testTheCacheNameChangesWhenAnyKeptFileChanges(): void
    {
        $root = $this->miniRoot();
        $before = (new PosServiceWorker($root, '', 'demo'))->cacheName();
        $this->assertSame($before, (new PosServiceWorker($root, '', 'demo'))->cacheName(), 'stable while nothing changes');

        file_put_contents("$root/public/assets/pos/js/store.js", '/* a new deployment */');
        $after = (new PosServiceWorker($root, '', 'demo'))->cacheName();
        $this->assertNotSame($before, $after, 'a new deployment gives a new cache, so old caches are deleted on activate');

        file_put_contents("$root/public/assets/pos/js/extra.js", '//');
        $this->assertNotSame($after, (new PosServiceWorker($root, '', 'demo'))->cacheName(), 'a new file does too');
    }

    public function testAnOrganizationsOwnScriptWinsAndFontsComeFromFontsCss(): void
    {
        $root = $this->miniRoot();
        mkdir("$root/public/assets/orgs/demo/pos/js", 0777, true);
        file_put_contents("$root/public/assets/orgs/demo/pos/js/app.js", '//own');
        file_put_contents("$root/public/assets/orgs/demo/pos/js/own.js", '//own');

        $list = (new PosServiceWorker($root, '/shop', 'demo'))->precacheUrls();
        $this->assertContains('/shop/assets/orgs/demo/pos/js/app.js', $list);
        $this->assertNotContains('/shop/assets/pos/js/app.js', $list, 'the organization\'s file replaces the shared one');
        $this->assertContains('/shop/assets/pos/js/store.js', $list);
        $this->assertContains('/shop/assets/orgs/demo/pos/js/own.js', $list);
        $this->assertContains('/shop/assets/fonts/inter.woff2', $list);
        $this->assertContains('/shop/assets/fonts/serif.woff2', $list);
        $this->assertSame('/shop/demo/pos', $list[0]);
        $this->assertSame('/shop/demo/pos', (new PosServiceWorker($root, '/shop', 'demo'))->scope());
    }

    /** @return array<string, array{0: string}> */
    public static function badSlugs(): array
    {
        return ['dots' => ['../x'], 'slash' => ['a/b'], 'upper' => ['Demo'], 'empty' => ['']];
    }

    #[DataProvider('badSlugs')]
    public function testAnOrganizationNameThatIsNotASlugIsRefused(string $slug): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PosServiceWorker($this->miniRoot(), '', $slug);
    }
}
