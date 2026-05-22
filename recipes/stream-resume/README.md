# stream-resume

A writer emits result chunks into the event log. The client records the
last seen sequence, reconnects with `resume`, and reassembles chunks
from replay plus live tail.
