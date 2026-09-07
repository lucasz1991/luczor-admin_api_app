# Luczor agent-team recovery overlay

This archive is a review artifact. It has not been deployed and contains no credentials, environment files, dependencies, caches, logs, or user data.

The overlay adds deterministic idempotency and exact verification for task, conversation, and project creates. It also prevents an unknown project identifier from silently clearing an existing task assignment. The `review` directory contains the focused Laravel tests and is not part of the production overlay.

Before any deployment, compare every target file with `manifest.json` and `feature.patch`, back up the current server files, and preserve all target-specific routes and configuration. Apply only reviewed hunks if a target file differs from the baseline. No database migration or environment change is required.

After an authorized deployment, clear the Laravel route/config cache according to the server's established process. Then verify authenticated create/replay/lookup requests for projects, tasks, and conversations under one test principal, plus a negative unknown-project task update. Do not use production user data for that acceptance.

Local verification for this package: full Laravel suite 534 tests / 3626 assertions; focused idempotency suite 8 tests / 75 assertions; Pint, PHPStan, and Git diff whitespace checks passed. These checks do not prove that the package is deployed or accepted by the production server.

Rollback: restore the backed-up controller and route files, clear the same caches, and repeat the target smoke checks. The API additions require no data rollback.
