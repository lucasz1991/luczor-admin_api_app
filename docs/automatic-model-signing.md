# Automatic model signing

The admin Local Models page and `GET /api/v1/local-model/signing-key` expose
the signing status and public RSA key. The endpoint is public, rate limited,
and returns no private key, filesystem path, provider credentials or model policy.
Manifest access still requires `settings.read`.

Existing configured keys and fingerprint checks take precedence. When neither
an inline key nor a key file is configured, the backend creates one RSA-3072 key
under `LUCZOR_LOCAL_MODEL_KEY_DIRECTORY` (default: `.luczor-secrets` next to the
checkout). The directory must be outside the checkout, writable by PHP, and
private (0700). The private key is stored as 0600. Creation is locked and atomic;
invalid existing keys are never replaced automatically.

Use a persistent shared secret directory for deployments with multiple releases
or workers. Back up this directory; do not delete it during deploys. Plesk's
open_basedir must permit it. An unwritable or disallowed directory produces a
503 and an admin status error. Disable generation with
`LUCZOR_LOCAL_MODEL_AUTO_GENERATE_KEY=false` when provisioning secrets externally.
After changing deployment environment configuration, rebuild Laravel's config cache.

Updated desktops without a compiled model key retrieve the public key directly
from `https://luczor.follow-flow.de/api/v1/local-model/signing-key` using native
HTTPS with certificate/hostname verification, no redirects and bounded responses.
This explicitly delegates key lifecycle authority to that HTTPS server. The
endpoint is queried at each manifest verification, so server key changes take
effect automatically. The response is never accepted from a URL supplied by the
WebView or a manifest. A self-hosted server still requires its public key and ID
in the desktop build. Compiled keys remain pinned and are never silently replaced.
Signature, schema, expiry, account binding and version rollback checks still apply.

Deploy this backend before updating/restarting the desktop. This repository change
does not deploy itself to the production server. The separate Voice signing key
and artifact metadata are not generated or modified by this model-key workflow.
