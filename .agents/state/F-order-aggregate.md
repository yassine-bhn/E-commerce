# Task F-order-aggregate
Type: feature
Started: 2026-10-19T17:20:00+01:00
Requirement: Ordering — Order aggregate + PlaceOrder via domain model (OrderStatus, version, OrderPlaced event, repository + outbox).

## Gates
GATE: PLAN ok — 2026-10-19T17:20:00+01:00 — orchestrator
GATE: GREEN ok — 2026-10-19T17:25:00+01:00 — implementer
GATE: REFACTOR ok — 2026-10-19T17:30:00+01:00 — refactorer

## RED record
RED: tests/Unit/Ordering/Domain/OrderTest.php — "testPlaceOnlineRecordsOrderPlacedEventWithPendingStatus" — cmd: vendor/bin/phpunit tests/Unit/Ordering/Domain/OrderTest.php → PASS
ATTEMPTS: 1

## Behavior plan
1. Order::place records OrderPlaced + PENDING + version 1 (Unit) — done
2. PlaceOrderHandler persists via OrderRepository + outbox (Functional StorefrontApiTest + Integration PlaceOrderOutboxTest) — done
3. TransitionOrderStatusHandler uses Order aggregate + OrderStatusChanged outbox — done

## Artifacts
- src/Ordering/Domain/Model/{Order,OrderLine,OrderId}.php
- src/Ordering/Domain/Event/{OrderPlaced,OrderStatusChanged}.php
- src/Ordering/Domain/Repository/OrderRepository.php
- src/Ordering/Infrastructure/Persistence/DoctrineOrderRepository.php
- tests/Unit/Ordering/Domain/OrderTest.php
- tests/Integration/Ordering/PlaceOrderOutboxTest.php
