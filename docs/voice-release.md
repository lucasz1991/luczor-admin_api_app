# Voice Release

End users never configure Whisper.cpp, Piper, model paths, or provider keys. The desktop app downloads a signed release manifest from `/api/v1/voice/manifest` on first voice use.

1. Build or obtain the four vetted artifacts for each platform: `whisper-cli`, the Whisper GGML model, `piper`, and the Piper ONNX model.
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

4. Put the generated `payload_json` value into `LUCZOR_VOICE_MANIFEST_JSON` for Laravel.
5. Build the Tauri release with the printed `LUCZOR_VOICE_MANIFEST_PUBLIC_KEY_B64` value.

The private key remains a Docker secret. Artifact hashes are checked before an atomic installation into the Tauri app-data directory; a mismatched hash leaves the existing runtime untouched.
