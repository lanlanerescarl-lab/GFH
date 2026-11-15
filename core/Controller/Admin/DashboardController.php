<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\Admin;

use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;

/** Admin Dashboard screen: gross profit first, net sales, orders, low-stock count, cost completeness, charts & widgets. */
final class DashboardController extends AdminPageController
{
    private const PERIODS = [
        'today' => 'Today',
        'month_to_date' => 'Month to date',
        'last_30_days' => 'Last 30 days',
    ];

    public function index(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, 'dashboard', 'view');

        $dashboard = $services->reports()->dashboard();
        $selected = $this->queryText($request, 'period', 'last_30_days');
        if (!isset(self::PERIODS[$selected])) {
            $selected = 'last_30_days';
        }

        return $this->render($request, $tenant, $staff, 'dashboard', [
            'dashboard' => $dashboard,
            'periods' => self::PERIODS,
            'selectedPeriod' => $selected,
            'period' => $dashboard['periods'][$selected],
            'dashboardUrl' => $this->adminUrl($request, $tenant),
            'inventoryUrl' => $this->can($staff, $tenant, 'inventory', 'view') ? $this->adminUrl($request, $tenant, '/inventory') : null,
            'reorderUrl' => $this->can($staff, $tenant, 'inventory', 'view') ? $this->adminUrl($request, $tenant, '/inventory/reorder') : null,
            'ordersUrl' => $this->can($staff, $tenant, 'sales', 'view') ? $this->adminUrl($request, $tenant, '/orders') : null,
            'procurementUrl' => $this->can($staff, $tenant, 'procurement', 'view') ? $this->adminUrl($request, $tenant, '/procurement') : null,
        ]);
    }
}
