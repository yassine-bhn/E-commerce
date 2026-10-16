# Audit log

One file per task: `.agents/audit/<task-id>.log`

Format (one line per event):

```
<ISO-8601> <dispatch|gate|blocked|escalate|approval> <agent-name> — <one-line detail>
```

Example:

```
2026-10-17T10:00:00+01:00 dispatch orchestrator — created F-checkout-saga, workflow=feature
2026-10-17T10:05:00+01:00 gate test-planner — GATE: PLAN ok
```

Only the orchestrator writes here (AGT-004).
