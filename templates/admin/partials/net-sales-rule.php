<?php
/**
 * The one sentence that says what "net sales" counts, shared by the Dashboard and the Reports page so they never
 * describe it differently. It restates the rule of the org_contract views (migration 0005).
 *
 * @var \Gfh\Core\View\AppView $this
 */
?>
<strong>Net sales</strong> are order totals without the 12% VAT. They count every completed POS sale (including sales still waiting for review) and every online or staff order that is not cancelled, on the day it was placed, whether or not it has been paid or delivered.
