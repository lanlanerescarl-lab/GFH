<?php
/**
 * Admin UI kit page (GET /{org}/admin/ui-kit).
 * Shows every shared Admin component and form control in every state.
 *
 * @var \Gfh\Core\View\AppView $this
 * @var \Gfh\Core\View\AssetUrls $assets
 */
$this->layout('layout');
$this->start('title');
echo $this->e('UI kit');
$this->end();

$this->start('head');
?>
    <script src="<?= $this->e($assets->shared('js/vendor/chart.js')) ?>" defer></script>
    <script type="module" src="<?= $this->e($assets->url('js/ui.js')) ?>"></script>
<?php
$this->end();

$headerActions = $this->partial('partials/button', [
    'label' => 'Open modal',
    'variant' => 'secondary',
    'attrs' => ['data-overlay-open' => 'kit-modal'],
]) . ' ' . $this->partial('partials/button', [
    'label' => 'Open drawer',
    'variant' => 'secondary',
    'attrs' => ['data-overlay-open' => 'kit-drawer'],
]) . ' ' . $this->partial('partials/button', [
    'label' => 'Trigger toast',
    'variant' => 'primary',
    'attrs' => [
        'data-toast-trigger' => '1',
        'data-toast-tone' => 'ok',
        'data-toast-title' => 'Stock count saved',
        'data-toast-message' => 'Batch adjustments were recorded in the ledger.',
    ],
]);
?>
<?= $this->partial('partials/page-header', [
    'eyebrow' => 'Stage 4 · Design system & component library',
    'title' => 'Admin UI kit',
    'subtitle' => 'Every Admin component, status tone, chart theme and form control rendered with self-hosted assets and keyboard operation.',
    'actionsHtml' => $headerActions,
]) ?>

<section class="kit-section" aria-labelledby="kit-tokens-heading">
    <h2 class="kit-section__title" id="kit-tokens-heading">Palette &amp; status tokens</h2>
    <div class="kit-swatch-grid">
        <div class="kit-swatch"><div class="kit-swatch__chip kit-swatch__chip--gfh-50"></div><strong>gfh-50</strong> <code>#EFF3FF</code></div>
        <div class="kit-swatch"><div class="kit-swatch__chip kit-swatch__chip--gfh-100"></div><strong>gfh-100</strong> <code>#C6DBEF</code></div>
        <div class="kit-swatch"><div class="kit-swatch__chip kit-swatch__chip--gfh-200"></div><strong>gfh-200</strong> <code>#9ECAE1</code></div>
        <div class="kit-swatch"><div class="kit-swatch__chip kit-swatch__chip--gfh-300"></div><strong>gfh-300</strong> <code>#6BAED6</code></div>
        <div class="kit-swatch"><div class="kit-swatch__chip kit-swatch__chip--gfh-500"></div><strong>gfh-500</strong> <code>#3182BD</code></div>
        <div class="kit-swatch"><div class="kit-swatch__chip kit-swatch__chip--gfh-800"></div><strong>gfh-800</strong> <code>#08519C</code></div>
        <div class="kit-swatch"><div class="kit-swatch__chip kit-swatch__chip--ok"></div><strong>status-ok</strong> <code>#16A34A</code></div>
        <div class="kit-swatch"><div class="kit-swatch__chip kit-swatch__chip--warn"></div><strong>status-warn</strong> <code>#D97706</code></div>
        <div class="kit-swatch"><div class="kit-swatch__chip kit-swatch__chip--danger"></div><strong>status-danger</strong> <code>#DC2626</code></div>
    </div>
</section>

<section class="kit-section" aria-labelledby="kit-kpi-heading">
    <h2 class="kit-section__title" id="kit-kpi-heading">KPI cards with sparklines</h2>
    <div class="kpi-grid">
        <?= $this->partial('partials/kpi-card', [
            'label' => 'Gross revenue (VAT-incl.)',
            'value' => '₱148,920.00',
            'delta' => '+14.2%',
            'tone' => 'ok',
            'period' => 'vs previous 7 days',
            'points' => [92, 104, 101, 118, 126, 139, 149],
        ]) ?>
        <?= $this->partial('partials/kpi-card', [
            'label' => 'Orders awaiting pick',
            'value' => '18',
            'delta' => '4 due today',
            'tone' => 'warn',
            'period' => 'Across 2 branches',
            'points' => [11, 15, 19, 22, 17, 20, 18],
        ]) ?>
        <?= $this->partial('partials/kpi-card', [
            'label' => 'Low-stock variants',
            'value' => '7',
            'delta' => '2 critical',
            'tone' => 'danger',
            'period' => 'Below reorder point',
            'points' => [3, 4, 4, 6, 5, 8, 7],
        ]) ?>
        <?= $this->partial('partials/kpi-card', [
            'label' => 'POS sync queue',
            'value' => '100%',
            'delta' => 'All synced',
            'tone' => 'info',
            'period' => '3 active terminals',
            'points' => [98, 99, 100, 100, 99, 100, 100],
        ]) ?>
    </div>
</section>

<section class="kit-section" aria-labelledby="kit-charts-heading">
    <h2 class="kit-section__title" id="kit-charts-heading">Charts (shared Chart.js theme: gfh-500, gfh-800, gfh-300, gfh-200)</h2>
    <div class="kit-grid">
        <?= $this->partial('partials/card', [
            'title' => 'Sales by channel',
            'subtitle' => 'Four-series palette (--chart-1 to --chart-4)',
            'bodyHtml' => '<div class="chart-wrap"><canvas data-chart="bar" data-chart-labels="Mon|Tue|Wed|Thu|Fri|Sat" data-chart-series="Online Shop:42,48,51,63,58,74;Counter POS:68,72,64,81,89,96;Wholesale:24,18,32,29,35,40;Direct:12,15,11,18,14,22" aria-label="Sales by channel bar chart" role="img"></canvas></div>',
        ]) ?>
        <?= $this->partial('partials/card', [
            'title' => 'Weekly stock movements',
            'subtitle' => 'Receipts vs fulfilment issues',
            'bodyHtml' => '<div class="chart-wrap"><canvas data-chart="line" data-chart-labels="W1|W2|W3|W4|W5|W6" data-chart-series="Issued units:120,145,132,168,174,190;Received units:160,110,180,140,200,175" aria-label="Weekly stock movements line chart" role="img"></canvas></div>',
        ]) ?>
    </div>
</section>

<section class="kit-section" aria-labelledby="kit-cards-heading">
    <h2 class="kit-section__title" id="kit-cards-heading">Cards</h2>
    <div class="kit-grid">
        <?= $this->partial('partials/card', [
            'title' => 'Standard card',
            'subtitle' => 'With header action and footer bar',
            'headerActionsHtml' => $this->partial('partials/badge', ['label' => 'Active', 'tone' => 'ok']),
            'bodyHtml' => '<p>Cards use <code>--radius-card</code> (10 px) and <code>--shadow-rest</code>.</p>',
            'footerHtml' => $this->partial('partials/button', ['label' => 'Cancel', 'variant' => 'ghost', 'size' => 'sm'])
                . $this->partial('partials/button', ['label' => 'Save changes', 'variant' => 'primary', 'size' => 'sm']),
        ]) ?>
        <?= $this->partial('partials/card', [
            'title' => 'Interactive & compact card',
            'subtitle' => 'Elevates on hover with --shadow-raised',
            'variant' => 'interactive',
            'bodyHtml' => '<p>Used for clickable branch, terminal, or supplier tiles.</p>',
        ]) ?>
    </div>
</section>

<section class="kit-section" aria-labelledby="kit-badges-heading">
    <h2 class="kit-section__title" id="kit-badges-heading">Badges, status tones, tags &amp; buttons</h2>
    <div class="kit-grid">
        <?= $this->partial('partials/card', [
            'title' => 'Badges & removable tags',
            'bodyHtml' => '<div class="kit-row kit-stack">'
                . $this->partial('partials/badge', ['label' => 'Paid / Active', 'tone' => 'ok'])
                . $this->partial('partials/badge', ['label' => 'Pending deposit', 'tone' => 'warn'])
                . $this->partial('partials/badge', ['label' => 'Oversold / Review', 'tone' => 'danger'])
                . $this->partial('partials/badge', ['label' => 'In transit', 'tone' => 'info'])
                . $this->partial('partials/badge', ['label' => 'Archived', 'tone' => 'neutral'])
                . '</div><div class="kit-row">'
                . $this->partial('partials/tag', ['label' => 'Cold chain', 'removable' => true])
                . $this->partial('partials/tag', ['label' => 'Main Warehouse', 'removable' => true])
                . $this->partial('partials/tag', ['label' => 'VAT-inclusive', 'removable' => false])
                . '</div>',
        ]) ?>
        <?= $this->partial('partials/card', [
            'title' => 'Button variants & states',
            'bodyHtml' => '<div class="kit-row kit-stack">'
                . $this->partial('partials/button', ['label' => 'Primary', 'variant' => 'primary'])
                . $this->partial('partials/button', ['label' => 'Secondary', 'variant' => 'secondary'])
                . $this->partial('partials/button', ['label' => 'Ghost', 'variant' => 'ghost'])
                . $this->partial('partials/button', ['label' => 'Danger', 'variant' => 'danger'])
                . '</div><div class="kit-row">'
                . $this->partial('partials/button', ['label' => 'Small', 'variant' => 'secondary', 'size' => 'sm'])
                . $this->partial('partials/button', ['label' => 'Large', 'variant' => 'primary', 'size' => 'lg'])
                . $this->partial('partials/button', ['label' => 'Disabled', 'variant' => 'primary', 'disabled' => true])
                . $this->partial('partials/button', ['label' => 'Saving…', 'variant' => 'primary', 'loading' => true])
                . '</div>',
        ]) ?>
    </div>
</section>

<section class="kit-section" aria-labelledby="kit-nav-heading">
    <h2 class="kit-section__title" id="kit-nav-heading">Tabs, segmented control &amp; status pipeline</h2>
    <div class="kit-grid kit-stack">
        <?= $this->partial('partials/card', [
            'title' => 'Keyboard-operable Tabs',
            'subtitle' => 'Use Left/Right/Home/End keys on the tab list',
            'bodyHtml' => $this->partial('partials/tabs', [
                'id' => 'kit-tabs',
                'label' => 'Product details tabs',
                'tabs' => [
                    ['id' => 'overview', 'label' => 'Overview', 'active' => true, 'contentHtml' => '<p>Weighted-average cost and current stock across branches.</p>'],
                    ['id' => 'tiers', 'label' => 'Price tiers', 'contentHtml' => '<p>Retail, wholesale and distributor tier pricing rules.</p>'],
                    ['id' => 'ledger', 'label' => 'Stock ledger', 'contentHtml' => '<p>Append-only stock movements for this variant.</p>'],
                ],
            ]),
        ]) ?>
        <?= $this->partial('partials/card', [
            'title' => 'Segmented control',
            'subtitle' => 'Roving tabindex with arrow keys',
            'bodyHtml' => $this->partial('partials/segmented-control', [
                'label' => 'Date range',
                'options' => [
                    ['value' => 'today', 'label' => 'Today', 'active' => true],
                    ['value' => '7d', 'label' => '7 days'],
                    ['value' => '30d', 'label' => '30 days'],
                    ['value' => 'quarter', 'label' => 'Quarter'],
                ],
            ]),
        ]) ?>
    </div>
    <?= $this->partial('partials/card', [
        'title' => 'Status pipeline (active & cancelled states)',
        'bodyHtml' => '<div class="kit-stack">'
            . $this->partial('partials/status-pipeline', [
                'label' => 'Standard fulfilment progress',
                'steps' => [
                    ['label' => 'Order placed', 'meta' => '15 Nov · 09:10', 'state' => 'done'],
                    ['label' => 'Deposit verified', 'meta' => '15 Nov · 09:42', 'state' => 'done'],
                    ['label' => 'Picking & packing', 'meta' => 'In progress', 'state' => 'current'],
                    ['label' => 'Out for delivery', 'meta' => 'Pending', 'state' => 'upcoming'],
                    ['label' => 'Completed', 'meta' => 'Pending', 'state' => 'upcoming'],
                ],
            ])
            . '</div>'
            . $this->partial('partials/status-pipeline', [
                'label' => 'Cancelled order progress',
                'steps' => [
                    ['label' => 'Order placed', 'meta' => '14 Nov · 11:00', 'state' => 'done'],
                    ['label' => 'Cancelled by staff', 'meta' => 'Stock reservation released', 'state' => 'cancelled'],
                ],
            ]),
    ]) ?>
</section>

<section class="kit-section" aria-labelledby="kit-forms-heading">
    <h2 class="kit-section__title" id="kit-forms-heading">Form controls (every input type &amp; state)</h2>
    <form class="card" action="<?= $this->e($homeUrl . '/ui-kit') ?>" method="get" novalidate>
        <div class="kit-grid">
            <div>
                <?= $this->partial('partials/field', [
                    'id' => 'kit-sku',
                    'name' => 'sku',
                    'label' => 'Variant SKU (text)',
                    'type' => 'text',
                    'value' => 'GFH-RICE-25KG',
                    'help' => 'Unique code printed on shelf labels and receipts.',
                    'required' => true,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'kit-price',
                    'name' => 'price',
                    'label' => 'Retail price, VAT-inclusive (number)',
                    'type' => 'number',
                    'value' => '1450.00',
                    'min' => '0',
                    'step' => '0.01',
                    'required' => true,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'kit-expiry',
                    'name' => 'expiry_date',
                    'label' => 'Batch expiry date (date)',
                    'type' => 'date',
                    'value' => '2026-12-01',
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'kit-branch',
                    'name' => 'branch_id',
                    'label' => 'Receiving branch (select)',
                    'type' => 'select',
                    'value' => 'main',
                    'options' => [
                        'main' => 'Main Warehouse · Quezon City',
                        'north' => 'North Branch · Caloocan',
                        'south' => 'South Branch · Makati',
                    ],
                ]) ?>
            </div>
            <div>
                <?= $this->partial('partials/field', [
                    'id' => 'kit-error-input',
                    'name' => 'reorder_point',
                    'label' => 'Reorder point (validation error state)',
                    'type' => 'number',
                    'value' => '-5',
                    'error' => 'Enter zero or a positive quantity.',
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'kit-disabled-input',
                    'name' => 'weighted_cost',
                    'label' => 'Weighted-average unit cost (disabled)',
                    'type' => 'text',
                    'value' => '₱1,120.5000 (computed by InventoryService)',
                    'disabled' => true,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'kit-channels',
                    'name' => 'channels',
                    'label' => 'Sales channels (checkbox)',
                    'type' => 'checkbox',
                    'value' => 'shop',
                    'options' => [
                        'shop' => 'Online Shop catalog',
                        'pos' => 'Counter POS terminals',
                    ],
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'kit-pick-strategy',
                    'name' => 'pick_strategy',
                    'label' => 'Stock pick strategy (radio)',
                    'type' => 'radio',
                    'value' => 'fefo',
                    'options' => [
                        'fifo' => 'FIFO (oldest receipt first)',
                        'fefo' => 'FEFO (earliest expiry first)',
                    ],
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'kit-track-batches',
                    'name' => 'track_batches',
                    'label' => 'Require batch & expiry on receiving (switch)',
                    'type' => 'switch',
                    'checked' => true,
                ]) ?>
                <?= $this->partial('partials/field', [
                    'id' => 'kit-switch-off',
                    'name' => 'allow_backorder',
                    'label' => 'Allow online backorders (switch off)',
                    'type' => 'switch',
                    'checked' => false,
                ]) ?>
            </div>
        </div>
    </form>
</section>

<section class="kit-section" aria-labelledby="kit-table-heading">
    <h2 class="kit-section__title" id="kit-table-heading">Data table (sorting, search, filter chips &amp; pagination)</h2>
    <?= $this->partial('partials/data-table', [
        'id' => 'kit-inventory-table',
        'searchLabel' => 'Search inventory variants',
        'searchPlaceholder' => 'Search SKU, product or branch…',
        'pageSize' => 4,
        'chips' => [
            ['value' => 'active', 'label' => 'In stock'],
            ['value' => 'low', 'label' => 'Low stock'],
            ['value' => 'archived', 'label' => 'Archived'],
        ],
        'columns' => [
            ['label' => 'SKU', 'sortable' => true, 'sortType' => 'text', 'sortDirection' => 'ascending'],
            ['label' => 'Product & variant', 'sortable' => true, 'sortType' => 'text'],
            ['label' => 'Status', 'sortable' => false],
            ['label' => 'On hand', 'sortable' => true, 'sortType' => 'number', 'numeric' => true],
            ['label' => 'Unit price (VAT-incl.)', 'sortable' => true, 'sortType' => 'number', 'numeric' => true],
        ],
        'rows' => [
            [
                'status' => 'active',
                'cells' => [
                    ['text' => 'GFH-COFFEE-1KG'],
                    ['text' => 'Cordillera Arabica Beans · 1 kg bag'],
                    ['html' => $this->partial('partials/badge', ['label' => 'In stock', 'tone' => 'ok'])],
                    ['text' => '42.000', 'sortValue' => '42', 'numeric' => true],
                    ['text' => '₱890.00', 'sortValue' => '890.00', 'numeric' => true],
                ],
            ],
            [
                'status' => 'low',
                'cells' => [
                    ['text' => 'GFH-MILK-1L'],
                    ['text' => 'Fresh Barista Milk · 1 L carton'],
                    ['html' => $this->partial('partials/badge', ['label' => 'Low stock', 'tone' => 'warn'])],
                    ['text' => '6.000', 'sortValue' => '6', 'numeric' => true],
                    ['text' => '₱135.00', 'sortValue' => '135.00', 'numeric' => true],
                ],
            ],
            [
                'status' => 'active',
                'cells' => [
                    ['text' => 'GFH-RICE-25KG'],
                    ['text' => 'Dinorado Milled Rice · 25 kg sack'],
                    ['html' => $this->partial('partials/badge', ['label' => 'In stock', 'tone' => 'ok'])],
                    ['text' => '85.000', 'sortValue' => '85', 'numeric' => true],
                    ['text' => '₱1,450.00', 'sortValue' => '1450.00', 'numeric' => true],
                ],
            ],
            [
                'status' => 'low',
                'cells' => [
                    ['text' => 'GFH-SUGAR-50KG'],
                    ['text' => 'Washed Cane Sugar · 50 kg sack'],
                    ['html' => $this->partial('partials/badge', ['label' => 'Low stock', 'tone' => 'warn'])],
                    ['text' => '4.000', 'sortValue' => '4', 'numeric' => true],
                    ['text' => '₱3,200.00', 'sortValue' => '3200.00', 'numeric' => true],
                ],
            ],
            [
                'status' => 'archived',
                'cells' => [
                    ['text' => 'GFH-TIN-2024'],
                    ['text' => 'Holiday Gift Tin 2024 · Piece'],
                    ['html' => $this->partial('partials/badge', ['label' => 'Archived', 'tone' => 'neutral'])],
                    ['text' => '0.000', 'sortValue' => '0', 'numeric' => true],
                    ['text' => '₱450.00', 'sortValue' => '450.00', 'numeric' => true],
                ],
            ],
        ],
    ]) ?>
</section>

<section class="kit-section" aria-labelledby="kit-empty-toasts-heading">
    <h2 class="kit-section__title" id="kit-empty-toasts-heading">Empty state &amp; toast notifications</h2>
    <div class="kit-grid">
        <?= $this->partial('partials/empty-state', [
            'title' => 'No purchase orders match these filters',
            'body' => 'Clear the active status chip or create a new purchase order to restock low variants.',
            'actionsHtml' => $this->partial('partials/button', ['label' => 'New purchase order', 'variant' => 'primary'])
                . $this->partial('partials/button', ['label' => 'Reset filters', 'variant' => 'secondary']),
        ]) ?>
        <div class="toast-region toast-region--inline" aria-label="Sample toast states">
            <?= $this->partial('partials/toast', ['tone' => 'ok', 'title' => 'Payment recorded', 'message' => 'Order #1042 is now marked Paid.']) ?>
            <?= $this->partial('partials/toast', ['tone' => 'warn', 'title' => 'Batch expiring soon', 'message' => 'Lot B-19 expires in 5 days.']) ?>
            <?= $this->partial('partials/toast', ['tone' => 'danger', 'title' => 'POS oversell flagged', 'message' => 'Sale synced offline exceeded on-hand stock; review needed.']) ?>
            <?= $this->partial('partials/toast', ['tone' => 'info', 'title' => 'Asset bundle published', 'message' => 'Templates are synced with public/assets/.']) ?>
        </div>
    </div>
</section>

<div class="toast-region" id="admin-toast-region" aria-live="polite"></div>

<?= $this->partial('partials/modal', [
    'id' => 'kit-modal',
    'title' => 'Record stock adjustment',
    'bodyHtml' => $this->partial('partials/field', [
        'id' => 'modal-adj-qty',
        'name' => 'adjustment_qty',
        'label' => 'Quantity delta',
        'type' => 'number',
        'value' => '10',
        'step' => '0.001',
    ]) . $this->partial('partials/field', [
        'id' => 'modal-adj-reason',
        'name' => 'adjustment_reason',
        'label' => 'Audit reason',
        'type' => 'text',
        'value' => 'Cycle count reconciliation',
    ]),
    'footerHtml' => $this->partial('partials/button', ['label' => 'Cancel', 'variant' => 'ghost', 'attrs' => ['data-overlay-close' => '1']])
        . $this->partial('partials/button', ['label' => 'Save adjustment', 'variant' => 'primary', 'attrs' => ['data-overlay-close' => '1']]),
]) ?>

<?= $this->partial('partials/drawer', [
    'id' => 'kit-drawer',
    'title' => 'Order #1042 inspection drawer',
    'bodyHtml' => '<p>Drawers slide from the right edge using <code>--shadow-overlay</code>, trap keyboard focus while open, and close with Escape.</p>'
        . $this->partial('partials/field', [
            'id' => 'drawer-note',
            'name' => 'fulfilment_note',
            'label' => 'Fulfilment note',
            'type' => 'text',
            'value' => 'Pack with insulated liner.',
        ]),
    'footerHtml' => $this->partial('partials/button', ['label' => 'Done', 'variant' => 'primary', 'attrs' => ['data-overlay-close' => '1']]),
]) ?>
