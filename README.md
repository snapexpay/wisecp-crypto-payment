# SnapEX Pay for WISECP

Accept crypto on WISECP invoices and orders. The client chooses **SnapEX Pay — crypto (USDT and other coins)**, pays on the SnapEX Pay
page (USDT on TRON, Ethereum, BSC and other networks, or other coins converted to USDT), and the invoice is
marked paid in WISECP automatically.

- No wallets or nodes to run; funds arrive in your SnapEX Pay merchant balance.
- The payment is recorded from the signed webhook or as soon as the client comes back from the payment
  page — whichever comes first. Either way the invoice is first read back from the SnapEX Pay API; the
  webhook body alone never marks anything paid, and a payment is never recorded twice.
- A client who comes back before the payment is confirmed sees a status page that re-checks every
  10 seconds and moves on to WISECP's "payment successful" page by itself.
- Test mode: test invoices confirm by themselves without real money; only a logged-in admin sees the
  button while it is on.
- Languages: English, Turkish, Russian.

Requirements: WISECP 3 or WISECP 5 (PHP 8.1 or newer with cURL, as WISECP itself; WISECP 5 needs PHP 8.2), a SnapEX
Pay merchant account. Tested in a real WISECP 5.0-beta.3 installation.
No extra database tables: the module keeps its data in the WISECP checkout.

> **Connecting to SnapEX Pay?** Create a merchant account, check the pricing and see every ready-made module on **[pay.snapex.pro](https://pay.snapex.pro)**.

## Install

1. Upload the contents of this archive to the WISECP root folder, keeping the path:
   ```
   coremio/modules/Payment/SnapexPay/…
   ```
2. WISECP admin → payment gateway settings → find **SnapEX Pay (Crypto)** among the modules, enable it
   and open its settings. (WISECP 5: **Payment Gateways** → add it under **Enabled Module Selection**, save,
   then open its card.)
3. In the SnapEX Pay merchant cabinet create an **API key** with the `invoices.read` and
   `invoices.write` scopes and paste it into **API key**.
4. In the cabinet add a webhook with the URL shown under **Webhook secret**
   (`https://<your WISECP>/payment/SnapexPay/<token>/callback` in WISECP 3, `…/payments/SnapexPay/<token>/callback`
   in WISECP 5; copy it from the settings page) and paste the `whsec_…` secret into
   **Webhook secret**. The webhook is how payments arrive when the client does not come back to the site.
5. If your invoices are not in USD, keep **USD** among the WISECP currencies with an exchange rate: the
   module converts the amount to USD with WISECP's rates. (If you set the gateway to convert to USD,
   WISECP hands the amount over in USD and no conversion is needed.)
6. Turn on **Test mode**, stay logged in to the admin area, open an unpaid invoice in the client area in
   the same browser, pay it on the SnapEX page with a test method and check it turns paid. Then turn test
   mode off.

## Settings

| Setting | |
|---|---|
| API key | From the SnapEX Pay cabinet. |
| Webhook secret | `whsec_…` from the cabinet webhook settings. The webhook URL is shown under the field. |
| Network fee paid by | *Merchant*: the client pays exactly the invoice amount. *Client*: the fee is added on top. |
| Payment window, minutes | How long the payment page stays valid (1–1440, default 60). Paying again later opens a fresh one. |
| Test mode | Test invoices, logged-in admins only. Test payments are ignored once test mode is off. |
| Partner ID | Optional. The Partner ID of the integrator who set this up (SnapEX Pay referral program). Leave empty if you set it up yourself. |
| API URL | Leave as is. |

## What lands in WISECP

- **Paid in full** → the payment is reported as successful and WISECP marks the invoice (or order) paid.
  The SnapEX Pay invoice number (`INV-…`) is the payment's transaction ID; the payment note also says what the client
  paid with.
- **Paid partly** (the client sent less and did not top up in time) → the payment is reported as
  *pending*, with the received amount in the payment note. Review it before approving.
- **Expired** without payment → nothing is recorded; the client can pay again.
- **Extra payment** after the invoice was closed → an entry in the WISECP module log; the money is in your
  SnapEX Pay balance.

API errors and every recorded payment are written to the WISECP module log. The API key and the webhook
secret are never logged.

## Test without money

Test mode creates SnapEX Pay invoices with `environment=test`: they go through the same statuses and send
the same signed webhooks, but never touch the real balance. Pick any method on the payment page — the test
invoice confirms by itself shortly after and WISECP records the payment like a real one.

## Support

support: https://snapex.pro
