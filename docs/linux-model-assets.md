# Linux model resources

The desktop now provisions missing resources automatically on first local model
preparation when no explicit runtime/model paths have been configured. This happens
after authenticated catalog verification, not in the system package post-install
script (which has neither a user's device key nor their model policy).

Deployment steps:

1. Supply the tested Linux `llama-server` executable and its required `.so` files
   for the client architecture. These must be actual Linux builds, not Windows EXEs.
2. Run `php artisan luczor:stage-model-runtime /path/to/llama-server --library=/path/to/libggml.so`
   once per required library option. The command stages files by SHA-256 under
   `LUCZOR_LOCAL_MODEL_ASSET_DIRECTORY` (default `storage/app/local-model-assets`).
   Keep this directory persistent across releases and readable by PHP.
3. Use the printed executable hash and support-file hashes in the admin model
   catalog. Preserve the tested backend, version, capacity and context limits;
   increment the catalog/policy version and publish the signed catalog normally.
   A catalog with a Windows executable hash cannot provision a Linux client.
4. Deploy both backend and desktop changes. The public transport endpoint is
   `/api/v1/local-model/assets/{sha256}` and only exposes runtime hashes belonging
   to enabled catalog entries. Private signing keys are never served.

The desktop downloads the runtime first, checks SHA-256 and the Linux ELF machine
type, then supporting libraries and the GGUF from its signed HTTPS URL. Files are
streamed into temporary paths, checked, and published without overwriting existing
files. Identical model hashes share one GGUF. Installed files remain subject to the
existing readiness/benchmark checks before CPU/GPU operation is reported as ready.
Interrupted temporary downloads are removed; retries currently restart the download.
Model installation requires network access and sufficient free fixed storage.

This does not build or publish a Linux runtime automatically, rewrite a production
catalog, sign untested assets, or enable speech packages. Mixed Windows/Linux fleets
need the corresponding signed runtime catalog for each platform; the existing
catalog currently selects one runtime per model, not a per-platform runtime map.
