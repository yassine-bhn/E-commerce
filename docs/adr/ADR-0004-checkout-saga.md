# ADR-0004 — Checkout process-manager saga

Status: accepted. Relies on: ECO-003, CMD-006, EVT-002.

## Decision

Checkout uses a process-manager saga: **PlaceOrder** + stock reservation **sync** on `command.bus`, payment authorization **async**, order confirmation **async**. Failures trigger **ReleaseStock** and **VoidPayment** compensations.

Entry point: `App\Ordering\Application\Saga\CheckoutSaga` dispatches `PlaceOrder` on `command.bus`.

## Rejected alternatives

- Choreography-only (rejected: hard to trace compensations across 4 contexts).
- Single synchronous transaction for payment (rejected: PCI scope and provider latency).
