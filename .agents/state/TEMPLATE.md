# Task <task-id>
Type: <feature|bugfix|refactor|investigation-only|review-only|pattern-decision-only|tdd-cycle>
Started: <ISO-8601>
Requirement: <one line>

## Gates
GATE: PLAN ok — <ISO-8601> — test-planner

## RED record
RED: tests/Unit/Example/BehaviorTest.php — "Failed asserting that false is true." — cmd: vendor/bin/phpunit --filter BehaviorTest → FAIL
ATTEMPTS: 0

## Human approvals
APPROVAL NEEDED: <subject> — <ISO-8601> — orchestrator
APPROVED: <subject> — <name> — <ISO-8601>
