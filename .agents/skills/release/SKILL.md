---
name: release
description: Package the module for distribution. Invoke with a version number, e.g. /release 1.2.3
disable-model-invocation: true
---

Run the packaging command with the version provided in $ARGUMENTS:

```bash
make package VERSION=$ARGUMENTS
```

Report the output path of the generated .zip file and confirm it completed successfully.
