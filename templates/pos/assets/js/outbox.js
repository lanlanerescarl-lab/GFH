// The outbox: where a finished sale goes to reach the server. The screens use only two things from here:
//
//   submit(sale)      sends one sale and returns what the server answered
//   pendingCount()    how many sales are waiting to be sent
//
// Batch 7.1 (online only): submit() posts the sale right away and returns the server's result. If the request fails
// (no connection, a refusal) it throws and nothing is kept, so the ticket stays on screen and the cashier presses
// Retry. Sending the same sale again is safe because the server treats the sale id as the key. pendingCount() is always 0.
//
// How Batch 7.2 replaces this file without touching a screen:
//   - submit(sale) first writes the sale to an IndexedDB store, then returns at once with a local result
//     ({sale_id, receipt_number, status: 'queued', total, duplicate: false}); the same signature, so the Pay flow is unchanged;
//   - a sync loop (started here, woken by the browser's online event and a timer) posts queued sales in receipt-number
//     order through api.postSale(), removes each one on a 201 or a duplicate answer, and keeps it on a 5xx or network error;
//   - pendingCount() returns the number of queued sales, which the status bar badge already shows;
//   - a 409 or 422 on a queued sale moves it to a "needs attention" list for the sync screen instead of retrying forever.
// Nothing in the screens may call api.postSale() directly; that is what keeps this swap small.

/** @param {{postSale: (sale: object) => Promise<object>}} api */
export function createOutbox(api) {
  return {
    async submit(sale) {
      return api.postSale(sale);
    },

    pendingCount() {
      return 0;
    },
  };
}
