# ack-backpressure

Shows the shape of a `session.ack`-driven consumer that slows a producer when
the outstanding event window grows beyond policy.

Run:

```sh
php samples/ack-backpressure/main.php
```
