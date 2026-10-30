# Changelog

## 1.0.2 — 2026-10-25

- The payment option and the invoice line the client sees now carry the SnapEX Pay brand:
  "SnapEX Pay — crypto (USDT and other coins)" and "SnapEX Pay — crypto" (Russian and Turkish the same way).

## 1.0.1 — 2026-10-16

- WISECP 5: the webhook now saves the module's record on its own checkout (it went to checkout 0, so a parallel
  request could read a stale copy), the payment carries the SnapEX Pay invoice (`INV-…`) as its transaction ID, and a
  returning client lands on the invoice's own "payment received" page.
- Checked end to end in a real WISECP 5.0-beta.3 installation (developer licence): webhook, client return, late
  webhook after the return — one payment each.

## 1.0.0 — 2026-10-15

- First release: SnapEX Pay as a WISECP third-party payment gateway.
- Invoice in USD with `external_id` and `Idempotency-Key`; reload reuses the open invoice, a new one after expiry or an amount change.
- Signed webhook (HMAC-SHA256, 5-minute timestamp window) and the client's return both re-read the invoice from the API before recording.
- Self-refreshing status page for a client who returns before confirmation.
- Partial payments reported as pending, extra payments logged; test mode for admins; Partner ID; who pays the network fee.
- English, Turkish and Russian.
