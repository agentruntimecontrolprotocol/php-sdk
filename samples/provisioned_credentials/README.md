# Provisioned Credentials

Demonstrates ARCP v1.1 `model.use` and `provisioned_credentials`.

The runtime is configured with `InMemoryCredentialProvisioner`. The
client requests both feature flags during the handshake, invokes a tool
with a lease carrying `model.use` and `cost.budget`, receives the
credential in `job.accepted`, and the runtime revokes it when the job
completes.

Run:

```sh
php samples/provisioned_credentials/main.php
```

`LiteLLMProvisioner.php` shows the shape of a plug-in provisioner without
coupling core SDK code to LiteLLM.
