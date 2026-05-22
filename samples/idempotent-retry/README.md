# idempotent-retry

Shows how a stable `(principal, idempotency_key)` maps retries to the
same job id, while a conflicting target fails fast.

Run:

```sh
php samples/idempotent-retry/main.php
```
