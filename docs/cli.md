# CLI

The PHP SDK ships `bin/arcp`, registered as a Composer binary.

## Install

```sh
composer install
bin/arcp --help
```

## `arcp serve`

Run a WebSocket runtime:

```sh
bin/arcp serve --host 127.0.0.1 --port 8765
```

Useful options:

| Option | Meaning |
| --- | --- |
| `--host` | Bind address. |
| `--port` | TCP port. |

## `arcp send`

Invoke a tool and print the terminal result:

```sh
bin/arcp send ws://127.0.0.1:8765 echo -a '{"message":"hello"}'
```

Arguments are JSON objects.

## `arcp tail`

Subscribe to envelopes and print events:

```sh
bin/arcp tail ws://127.0.0.1:8765
```

## `arcp replay`

Replay an event log file:

```sh
bin/arcp replay events.sqlite -a msg_previous
```

## stdio

Use `StdioTransport` directly from PHP when the runtime is a child
process. The CLI focuses on WebSocket serving, sending, tailing, and log
replay.

## Exit codes

The command exits non-zero on invalid input, failed connection, failed
serialization, or a protocol error returned by the runtime.
