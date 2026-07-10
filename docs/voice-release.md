# Voice Release

End users never configure Whisper.cpp, Piper, model paths, or provider keys. The desktop app downloads a signed release manifest from `/api/v1/voice/manifest` on first voice use.

## Windows bootstrap (recommended)

The repository includes a complete bootstrap for Windows x64. It downloads the official whisper.cpp runtime, Piper runtime, a multilingual Whisper base model, and the German Thorsten Piper voice. It creates the signing key once, writes a signed manifest, configures the local Laravel `.env`, and generates the Tauri **public-key-only** file `app/.env.voice`.

```powershell
cd E:\projekte\luczor\admin_api_app
.\scripts\bootstrap-voice-release.ps1
cd ..\app
pnpm.cmd tauri dev
```

For a localhost-only development server, use `-BaseUrl http://127.0.0.1:8000`; debug Tauri builds accept loopback HTTP only. Production releases require HTTPS and should use the default `https://luczor.follow-flow.de` base URL. The private signing key remains under `storage/app/keys` and must never be copied to `app/.env.voice` or the Tauri app.

1. Build or obtain the four vetted artifacts for each platform: `whisper-cli`, the Whisper GGML model, `piper`, and the Piper ONNX model. Windows binary runtimes can be ZIP archives; Luczor extracts only signed, hash-verified archives and retains their DLL dependencies.
2. Publish those immutable files below an HTTPS release directory.
3. Run the generator in the release environment, which has the production `LUCZOR_JOB_PRIVATE_KEY_FILE`:

```sh
php artisan luczor:voice-release \
  --release-version=2026.07.09 \
  --base-url=https://releases.example/luczor/voice/2026.07.09 \
  --stt-binary=artifacts/whisper-cli.exe \
  --stt-model=artifacts/ggml-small.de.bin \
  --tts-binary=artifacts/piper.exe \
  --tts-model=artifacts/de_DE-thorsten-medium.onnx \
  --manifest-output=storage/app/voice-release.json
```

4. Prefer `LUCZOR_VOICE_MANIFEST_FILE` pointing to the generated envelope. `LUCZOR_VOICE_MANIFEST_JSON` remains supported for deployments that manage configuration entirely through environment variables.
5. Build the Tauri release with the printed `LUCZOR_VOICE_MANIFEST_PUBLIC_KEY_B64` value.

The private key remains a Docker secret. Artifact hashes are checked before an atomic installation into the Tauri app-data directory; a mismatched hash leaves the existing runtime untouched.
