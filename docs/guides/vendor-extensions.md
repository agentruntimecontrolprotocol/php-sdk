# Vendor extensions (§15)

Vendor extensions let deployments carry custom data without changing the
core protocol.

## What's extensible

- Custom envelope types.
- Envelope extension metadata.
- Custom event payloads.
- Custom lease capabilities.

## Naming rules

Use `arcpx.<domain>.<name>.v<n>` for custom message types.

## Round-trip guarantee (§15)

Unknown extension fields remain opaque unless explicitly registered.

## Custom event kinds

Use `EventEmit` for structured domain events.

## Custom envelope types

Register extension type names in `ExtensionRegistry`.

## Custom lease capabilities

Keep capability names namespaced and validate them at the runtime edge.

## Envelope extensions

Use the envelope `extensions` map for metadata that does not change core
dispatch.

## Authoring discipline

Document the namespace, owner, version, payload shape, and compatibility
promise.

## Discovery via `capabilities`

Advertise required extension support in `Capabilities::$extensions`.

## Runnable example

See `samples/vendor-extensions/`.
