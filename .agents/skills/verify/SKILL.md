---
name: verify
description: Run the full CI verification pipeline locally — lint, unit tests, and 90% coverage check. Use after making changes to confirm they are ready.
---

Run the following commands in sequence. Stop and report any failure immediately.

```bash
make lint
make test-coverage
```

Report:
- Whether lint passed or failed (and which files had violations)
- Whether tests passed and the coverage percentage achieved
- Any failures with the relevant error output
