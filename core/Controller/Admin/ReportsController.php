<?php

declare(strict_types=1);

namespace Gfh\Core\Controller\Admin;

use Gfh\Core\Http\HttpException;
use Gfh\Core\Http\Request;
use Gfh\Core\Http\Response;
use Gfh\Core\Service\ReportService;

/**
 * Admin Reports page (GET /{org}/admin/reports): net sales, gross profit, units, cost and orders for one chosen
 * day, month or year. The module is reports_admin, so a Cashier or Staff member is refused with 403.
 * The page only reads and shows; every figure comes from ReportService.
 */
final class ReportsController extends AdminPageController
{
    public function index(Request $request): Response
    {
        ['staff' => $staff, 'tenant' => $tenant, 'services' => $services] = $this->authorize($request, 'reports_admin', 'view');

        $view = $this->queryText($request, 'view', 'day');
        if (!isset(ReportService::VIEWS[$view])) {
            $view = 'day';
        }
        $field = ReportService::FIELDS[$view];
        $input = trim($this->queryText($request, $field, ''));

        $report = null;
        $errors = [];
        $status = 200;
        try {
            $report = $services->reports()->periodReport($view, $input);
        } catch (HttpException $exception) {
            $errors = $this->errorsOf($exception, $field);
            $status = 422;
        }

        $reportsUrl = $this->adminUrl($request, $tenant, '/reports');
        $links = [];
        foreach (ReportService::VIEWS as $key => $label) {
            // Switching view keeps the same moment: Day 16 Nov 2026 -> Month Nov 2026 -> Year 2026.
            $query = $report !== null ? $report['switch'][$key] : ['view' => $key];
            $links[] = ['key' => $key, 'label' => $label, 'url' => $reportsUrl . '?' . http_build_query($query), 'active' => $key === $view];
        }

        $step = static fn (?string $value): ?string => $value === null
            ? null
            : $reportsUrl . '?' . http_build_query(['view' => $view, $field => $value]);

        return $this->render($request, $tenant, $staff, 'reports', [
            'view' => $view,
            'viewLabels' => ReportService::VIEWS,
            'field' => $field,
            'input' => $report !== null ? $report['value'] : $input,
            'errors' => $errors,
            'report' => $report,
            'reportsUrl' => $reportsUrl,
            'viewLinks' => $links,
            'previousUrl' => $report !== null ? $step($report['previous']) : null,
            'nextUrl' => $report !== null ? $step($report['next']) : null,
            'dashboardUrl' => $this->adminUrl($request, $tenant),
        ], $status);
    }
}
