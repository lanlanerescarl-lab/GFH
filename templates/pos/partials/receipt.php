<?php
/**
 * The 80 mm receipt. It is embedded in the shell inside a template element and filled by assets/js/receipt.js,
 * which writes text into the elements below by their data-bind and data-list names. It reads only what the server
 * or the terminal snapshot already provides, and it holds no legal wording of its own.
 *
 * An organization replaces this file from orgs/{slug}/pos/partials/receipt.php. Keep the data-bind and data-list
 * names you want filled; a name you leave out is simply not printed, and data-show elements stay hidden when
 * their value is empty.
 *
 * data-bind names: organization, branchName, branchAddress, terminalCode, receiptNumber, date, time, cashier,
 *   subtotal, discountLabel, discount, total, vatRate, vatAmount, netOfVat, cashGiven, change, footer
 * data-list names: lines, payments
 * data-show names: cashier, discount, cashGiven, change, address
 *
 * Extension lines (hook pos.receipt.lines) need nothing here: receipt.js prints them under their sale line, and under the
 * totals just before the element with data-bind="footer" (at the end when your template has none).
 *
 * @var \Gfh\Core\View\AppView $this
 */
?>
<article class="receipt" aria-label="Receipt">
    <header class="receipt__head">
        <h2 class="receipt__org" data-bind="organization"></h2>
        <p class="receipt__branch" data-bind="branchName"></p>
        <p class="receipt__address" data-bind="branchAddress" data-show="address"></p>
    </header>

    <dl class="receipt__meta">
        <div><dt>Receipt</dt><dd data-bind="receiptNumber"></dd></div>
        <div><dt>Date</dt><dd data-bind="date"></dd></div>
        <div><dt>Time</dt><dd data-bind="time"></dd></div>
        <div><dt>Terminal</dt><dd data-bind="terminalCode"></dd></div>
        <div data-show="cashier"><dt>Cashier</dt><dd data-bind="cashier"></dd></div>
    </dl>

    <ul class="receipt__lines" data-list="lines"></ul>

    <dl class="receipt__totals">
        <div><dt>Subtotal</dt><dd data-bind="subtotal"></dd></div>
        <div data-show="discount"><dt data-bind="discountLabel"></dt><dd data-bind="discount"></dd></div>
        <div class="receipt__grand"><dt>Total</dt><dd data-bind="total"></dd></div>
        <div class="receipt__vat"><dt>Net of VAT</dt><dd data-bind="netOfVat"></dd></div>
        <div class="receipt__vat"><dt>VAT included (<span data-bind="vatRate"></span>%)</dt><dd data-bind="vatAmount"></dd></div>
    </dl>

    <ul class="receipt__payments" data-list="payments"></ul>

    <dl class="receipt__totals">
        <div data-show="cashGiven"><dt>Cash given</dt><dd data-bind="cashGiven"></dd></div>
        <div class="receipt__change" data-show="change"><dt>Change</dt><dd data-bind="change"></dd></div>
    </dl>

    <p class="receipt__footer" data-bind="footer">Thank you for your purchase.</p>
</article>
