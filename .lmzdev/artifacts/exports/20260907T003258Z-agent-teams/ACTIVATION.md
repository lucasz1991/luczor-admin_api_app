# Reviewable agent-team release

No deployment, database change or provider request has been performed by this package.

The overlay contains 19 feature source files plus the Vite manifest and its two assets. The review folder contains the feature documentation and tests. `manifest.json` lists every delivered SHA-256 and, where available, the baseline Git blob's SHA-256. Baseline blobs use repository line endings; target comparison may need an explicit line-ending-aware review.

`feature.patch` is scoped to this feature against baseline a00d2cbbf3bb39c7a09df77750c05239ec605700. Compare the actual server files first. The complete route files in the overlay contain pre-existing Voice/TTS and other unrelated routes from the current checkout. Applying those whole files blindly could overwrite target-specific or unreleased work. Prefer the feature's additive route hunks after comparison; never replace a mismatched target file without reviewing its differences. The same baseline check applies to other shared files.

After an explicitly authorized deployment with backup, preserve server secrets and data; install the reviewed source/assets, rebuild or clear the Laravel config/route/view caches according to the target's existing process. No migration, global DatabaseSeeder, new provider credential or environment change is required for this feature.

## Activation after deployment

Verified route: GET /admin/{page}, page=agents; on the configured public Luczor host this is https://luczor.follow-flow.de/admin/agents. This path is verified from local Laravel routes, not claimed as already deployed.

1. Sign in as an existing admin. Open Agenten & Ereignisse.
2. Click OpenRouter-Katalog neu prüfen. It performs only a public GET of metadata for reviewed candidates.
3. Select the existing active OpenRouter Chat Completions credential by label; click Teams ergänzen. No key is shown or re-entered.
4. Keep Standardteam=Lokale Planung + Free-Spezialisten (id free), max_parallel=2, enabled=true. The optional paid-planning team has id budget.
5. Verify authenticated GET /api/v1/agent-team-policy returns version=1, a 64-character revision, enabled=true and populated free-role candidates. Verify unauthenticated discovery=401, executable tool contracts=422, stale policy revision=409, and that native local execution remains local. Real provider/model acceptance is still a separate smoke, not covered by this package.

Exact idempotent service operation, if a reviewed server-side operator flow is preferred: `app(App\Services\AgentTeamDefaultsService::class)->prepare($existingOpenRouterCredentialId)`. Its prerequisite is an active same-provider credential with request_format=chat_completions. It returns models_created/roles_created/entries_created, preserves custom or disabled existing entries, and never calls DatabaseSeeder. Research refresh is `app(App\Services\AgentTeamDefaultsService::class)->refreshResearch()`.

Reserved records: AgentProfile key luczor.agent-team-policy, type team_policy, config {default_preset: free, max_parallel: 2}; research metadata key luczor.agent-model-catalog, type model_catalog. Roles are agent.planning/research/coding/review, mapped to independent agent-* ModelUseCase slugs. Free network policy agent.free is capped at 0 USD and two attempts. Optional agent.planning policy is capped at 0.05 USD and one attempt. Initial output limit is 4096 tokens. Research price records expire after 14 days and must then be refreshed.

Rollback: restore the backed-up source/assets and retain both new and old asset filenames during cutover. Disable the reserved team-policy profile if necessary; do not delete or reseed pre-existing profiles, routes, credentials or user data.

Verification: full Laravel suite 525 tests / 3546 assertions; final focused suite 49 tests / 316 assertions after the last catalog-outage handling change, including 18 agent-team tests. Full Pint and PHPStan passed, with final focused checks on the last metadata service change; Vite build and diff whitespace checks passed. Synthetic local admin browser preview passed desktop/390px layout and pointer/keyboard form checks. No production or real-model validation has been performed.
