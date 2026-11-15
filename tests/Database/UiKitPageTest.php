<?php

declare(strict_types=1);

namespace Gfh\Tests\Database;

use Gfh\Core\Database\Database;
use Gfh\Core\Hooks\Hooks;
use Gfh\Core\Http\Middleware\RequireStaffMiddleware;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Service\AdminNavigationService;
use Gfh\Core\Support\Services;
use Gfh\Core\Tenancy\Tenant;
use Gfh\Core\View\AppView;
use Gfh\Core\View\AssetUrls;
use Gfh\Core\View\TemplateLocator;
use Gfh\Tests\Support\DatabaseTestCase;

final class UiKitPageTest extends DatabaseTestCase
{
    public function testSignedOutUserIsRefusedOnUiKitRoute(): void
    {
        $organization = $this->kit->organization('kit-a');
        $tenant = new Tenant($organization, new Database($this->kit->config));
        $app = \Gfh\Core\App::boot(dirname(__DIR__, 2));
        restore_error_handler();
        restore_exception_handler();
        $services = new Services($app);
        $middleware = new RequireStaffMiddleware($services);

        $htmlRequest = (new Request('GET', '/kit-a/admin/ui-kit'))->withAttribute('tenant', $tenant);
        $response = $middleware($htmlRequest, static fn (): Response => Response::html('should not reach'));

        $this->assertSame(302, $response->status());
        $this->assertSame('/kit-a/admin/login?next=%2Fkit-a%2Fadmin%2Fui-kit', $response->header('Location'));

        $jsonRequest = (new Request('GET', '/kit-a/admin/ui-kit', server: ['HTTP_ACCEPT' => 'application/json']))->withAttribute('tenant', $tenant);
        $this->assertHttpStatus(401, fn () => $middleware($jsonRequest, static fn (): Response => Response::html('should not reach')));

        $controller = new \Gfh\Core\Controller\StaffPagesController($services);
        $this->assertHttpStatus(401, fn () => $controller->uiKit($htmlRequest));
    }

    public function testUiKitRouteIsRegisteredBehindRequireStaffMiddleware(): void
    {
        $routes = (string) file_get_contents(dirname(__DIR__, 2) . '/core/routes.php');

        $this->assertMatchesRegularExpression(
            '#\$r->get\(\'/admin/ui-kit\'.*\[.*\$tenant.*\$staffSession.*\$requireStaff\]\)#',
            $routes,
        );
    }

    public function testUiKitPageRendersEveryComponentWithLabelsAndKeyboardSupport(): void
    {
        $organization = $this->kit->organization('kit-b');
        $admin = $this->kit->member($organization, 'admin@example.test', 'admin');
        $navigation = (new AdminNavigationService($this->kit->permissions, new Hooks(), true))
            ->items($admin, $organization, '', '/kit-b/admin/ui-kit');

        $project = dirname(__DIR__, 2);
        $view = new AppView(new TemplateLocator($project), 'kit-b', 'admin', [
            'organizationName' => $organization->name,
            'organizationShortName' => $organization->shortName,
            'slug' => 'kit-b',
            'basePath' => '',
            'homeUrl' => '/kit-b/admin',
            'assets' => new AssetUrls($project . '/public', '', 'kit-b', 'admin'),
            'navigation' => $navigation,
            'staffName' => $admin->fullName,
            'staffRole' => 'admin',
            'logoutUrl' => '/kit-b/admin/logout',
            'csrf' => 'csrf-token-1',
        ]);

        $html = $view->render('ui-kit');

        // Shell, skip link, active sidebar item, and scripts
        $this->assertStringContainsString('<a class="skip-link" href="#main">Skip to the main content</a>', $html);
        $this->assertStringContainsString('<a href="/kit-b/admin/ui-kit" aria-current="page">UI kit</a>', $html);
        $this->assertStringContainsString('src="/assets/js/vendor/chart.js"', $html);
        $this->assertStringContainsString('src="/assets/admin/js/ui.js"', $html);

        // Required components and states
        foreach ([
            'class="page-header"',
            'class="card"',
            'class="card card--interactive"',
            'class="kpi-card"',
            'class="kpi-card__sparkline"',
            'class="badge badge--ok"',
            'class="badge badge--warn"',
            'class="badge badge--danger"',
            'class="badge badge--info"',
            'class="badge badge--neutral"',
            'class="tag"',
            'data-tag-remove',
            'class="button button--secondary"',
            'class="button button--ghost"',
            'class="button button--danger"',
            'class="button button--secondary button--sm"',
            'class="button button--lg"',
            'aria-busy="true"',
            'role="tablist"',
            'role="tab"',
            'role="tabpanel"',
            'class="pipeline"',
            'pipeline__step--done',
            'pipeline__step--current',
            'pipeline__step--upcoming',
            'pipeline__step--cancelled',
            'class="empty-state"',
            'data-segmented',
            'class="segmented__option"',
            'data-table',
            'data-table-search',
            'data-table-filter="active"',
            'data-table-sort',
            'aria-sort="ascending"',
            'data-page-prev',
            'data-page-next',
            'id="kit-modal"',
            'id="kit-drawer"',
            'role="dialog"',
            'aria-modal="true"',
            'class="toast toast--ok"',
            'class="toast toast--warn"',
            'class="toast toast--danger"',
            'class="toast toast--info"',
            'id="admin-toast-region" aria-live="polite"',
            'type="text"',
            'type="number"',
            'type="date"',
            '<select',
            'type="checkbox"',
            'type="radio"',
            'role="switch"',
            'aria-checked="true"',
            'aria-checked="false"',
            'aria-invalid="true"',
            'data-chart="bar"',
            'data-chart="line"',
        ] as $snippet) {
            $this->assertStringContainsString($snippet, $html, 'Missing UI kit snippet: ' . $snippet);
        }

        // Every visible input, select and textarea must have an explicit <label for="...">.
        $dom = new \DOMDocument();
        @$dom->loadHTML($html);
        $xpath = new \DOMXPath($dom);

        $controls = $xpath->query('//input[@type!="hidden"] | //select | //textarea');
        $this->assertNotFalse($controls);
        $this->assertGreaterThanOrEqual(12, $controls->length);

        foreach ($controls as $control) {
            /** @var \DOMElement $control */
            $id = $control->getAttribute('id');
            $this->assertNotSame('', $id, 'Every form control must have an id');
            $labels = $xpath->query(sprintf('//label[@for="%s"]', $id));
            $this->assertNotFalse($labels);
            $this->assertGreaterThanOrEqual(1, $labels->length, sprintf('Control #%s must have a <label for="%s">', $id, $id));
        }
    }
}
