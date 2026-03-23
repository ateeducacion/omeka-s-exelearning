---
name: i18n
description: Run the full translation workflow — extract strings, update .po files, check for untranslated strings, and compile .mo files.
---

Run the full i18n pipeline in sequence. Stop and report any failure immediately.

```bash
make i18n
```

This runs: `generate-pot` → `update-po` → `check-untranslated` → `compile-mo`.

Report:
- Whether each step passed or failed
- Any untranslated strings found (file and string key)
- Confirmation that .mo files were compiled successfully
