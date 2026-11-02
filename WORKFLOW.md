# Payment workflow (v2)

**A payment counts only when `verifyCapture()` confirms the exact amount and currency with the bank.**
The return URL, the webhook and `mib:reconcile` only trigger that check. The package never marks anything
paid: your listeners do that when they receive an event.

The diagrams show the optional Laravel layer. A non-Laravel host uses the same core classes (`AttemptGuard`,
`NotificationVerifier`, `verifyCapture()`) and writes this wiring itself.

## 1. Starting a checkout

One payable (your invoice or payment) can have several attempts. Each attempt has its own gateway order ID,
and an ID is never reused, even after a failure.

```mermaid
flowchart TD
    A[Payer clicks pay] --> B["Take the host lock: Payable::lock()"]
    B --> C["MibGlobalPay::attemptGuard()->canStartNewAttempt()"]
    C --> D["After its local checks, asks the bank about every attempt that may still be paid (verifyCapture)"]
    D --> E{Guard result}

    E -->|"MoneyCaptured"| F[Refused: run the outcome pipeline for the captured attempt]
    E -->|"VerificationPending"| G[Refused: ask the payer to wait]
    E -->|"AttemptStillLive"| H[Refused: reuse reusableAttempt if there is one]
    E -->|"AlreadySettled, AttemptCaptured or PayableNotPayable"| I[Refused: nothing to pay]
    E -->|Allowed| J[Mark attemptsToSupersede as Superseded]

    J --> K[Insert the new attempt as Created]
    K --> L[initiateCheckout]
    L -->|Success| M["Store sessionId, successIndicator and sessionVersion, then set the attempt to Live"]
    L -->|Rejected| N[Set the attempt to Failed: that order ID is burned]
    M --> O["Redirect with the checkout component (Checkout.js)"]
    O --> P[Payer pays on the bank's hosted page]
```

If the bank cannot be reached, the guard throws, so no new attempt starts while an older one might be paid.

## 2. Finding out what happened

Every trigger ends in the same pipeline.

```mermaid
flowchart TD
    P[Payer finishes on the hosted page] --> R[Browser returns to the return URL]
    P --> W[Bank posts to the webhook]
    S[Scheduler] --> X[mib:reconcile]
    G[AttemptGuard finds money captured] --> L

    R --> R1["PaymentOutcome::resolveFromReturn()"]
    R1 --> R2["The success indicator is compared, only as a hint for the payer-facing page"]
    R2 --> V

    W --> W1{Verify the notification}
    W1 -->|No secret or no PayableResolver configured| W2[503]
    W1 -->|Secret missing or wrong| W3[401]
    W1 -->|Malformed| W4[400]
    W1 -->|Valid| W5{Claim the notification ID}
    W5 -->|Already claimed| W6[200 duplicate]
    W5 -->|New| W7[Queue ProcessNotification]
    W7 -->|Queueing failed| W8[Release the claim, 503: the bank redelivers]
    W7 -->|Queued| W9[200 accepted]
    W7 -.->|Queue worker| W10{Notification type}
    W10 -->|Refund or void| W11["RefundProcessed or VoidProcessed, then record the delivery"]
    W11 -->|Known attempt| V
    W10 -->|Payment or capture| W12{Known attempt?}
    W12 -->|No| W13[UnknownOrderNotification]
    W12 -->|Yes| V
    W10 -->|Anything else| W14[Ignored]

    X --> V
    V["verifyCapture() against Payable::expectedAmount(), outside the lock"] --> L
    L["Take Payable::lock(), re-read the payable"] --> D[Dispatch at most one outcome event]
    D -->|Listener threw| F[Record nothing: a job retry or mib:reconcile dispatches again]
    D -->|Lock returned| Y[Record the delivery of a money event]
```

The webhook answers within the bank's 2-second window because it only verifies, claims and queues. The bank
redelivers up to 20 times over 3 days. Schedule `mib:reconcile`, because it covers lost webhooks.

## 3. Outcome events

| `verifyCapture()` result | Payable | Event |
|---|---|---|
| `Captured` | Payable | `PaymentCaptured`: mark it paid and record the settlement |
| `Captured` | Settled by this same capture | None (duplicate) |
| `Captured` | Settled by another capture, or `Paid` with no settlement | `CaptureMismatch` (`DoubleCapture`) |
| `Captured` | Any other state | `PaymentCapturedForUnpayableOrder` |
| `AmountMismatch`, `CurrencyMismatch` | Any | `CaptureMismatch`: money moved but is wrong, so never auto-pay |
| `Pending` | Any | `PaymentPending` |
| `Reversed` | Any | `PaymentReversed` |
| `NotCaptured`, `NotFound` | Triggering transaction failed, or attempt expired | `PaymentFailed` |
| `NotCaptured`, `NotFound` | Attempt still live | None |

Each capture, mismatch or reversal event, and `RefundProcessed` and `VoidProcessed`, is a money event. It goes out
at least once per order ID, transaction ID and outcome until one dispatch completes, then not again, however many
triggers arrive. A listener failure records nothing, so a job retry or `mib:reconcile` dispatches again. A crash before
the record, or a second worker arriving before it, can repeat the event, so listeners must be idempotent on
`deliveryKey`. `PaymentCaptured` is also stopped by the settlement your listener saves.

`PaymentPending` and `PaymentFailed` are advisory: at most once per notification, and each time from a trigger that has
no notification, so make their listeners idempotent too.

With `MIB_AUTO_REVERSE_DOUBLE_CAPTURE` on (off by default), a `DoubleCapture` also queues a reversal: a void,
falling back to a refund.

## Still unconfirmed by MIB

The `NotFound` rule, the webhook body and header details and the production host are implemented but not yet
confirmed against the gateway. `NotFound` needs a body in the documented error format, so a 404 page from a wrong host
throws instead of reading as unpaid. If MIB's real response does not match, unpaid attempts throw too, and a payer with
an expired attempt cannot start a new one until the rule is corrected. See the top of [DESIGN-v2.md](./DESIGN-v2.md).
