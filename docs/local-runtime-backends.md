# Signierte lokale Runtime und GPU-Bibliotheken

Laravel führt das lokale Modell nicht aus. Der veröffentlichte Modellkatalog enthält pro Modell die freigegebene llama.cpp-Runtime; Auswahl, Hardwareprüfung und Start gehören zum Desktop. Ein GPU-Backend benötigt einen dafür gebauten llama.cpp-Server und die passenden Treiber/Bibliotheken.

Der bestehende Runtime-Vertrag (`id`, `version`, `sha256`, `min_context_tokens`, `max_context_tokens`) hat zwei optionale Erweiterungen:

| Feld | Vertrag |
| --- | --- |
| `backend` | `auto`, `cpu`, `cuda`, `vulkan` oder `metal` |
| `files` | Liste mit höchstens 128 Begleitbibliotheken, jeweils `name` und `sha256` |

`name` ist ein ASCII-Dateiname mit höchstens 160 Zeichen, beginnt alphanumerisch und enthält danach nur Buchstaben, Ziffern, Punkt, Unterstrich, Plus oder Bindestrich. Er endet auf `.dll`, `.so` oder `.dylib`. Pfade, Laufwerke, Streams und doppelte Dateinamen (ohne Beachtung der Großschreibung) werden abgewiesen. SHA-256 besteht aus genau 64 hexadezimalen Kleinbuchstaben. EXE- und Bibliothekshashes müssen aus den tatsächlich geprüften lokalen Dateien stammen.

Fehlende optionale Felder bleiben beim Signieren weggelassen. Der bestehende gemeinsame PHP/TypeScript/Rust-Testkatalog und seine kanonischen Signaturbytes ändern sich dadurch nicht. Sind die Felder angegeben, werden sie vollständig mit signiert; eine Änderung des Backends oder eines Bibliothekshashes bricht die Signaturprüfung. Ältere Desktops ohne Unterstützung für diese Felder müssen zuerst aktualisiert werden.

Die fünf Stufen lassen sich im Admin unter **Lokale Modelle** über **Modelldatei, Runtime, Lizenz und Prüfnachweise bearbeiten** pflegen. Entwurf speichern verändert die aktive Richtlinie nicht. Erst die vorhandene Aktion **Prüfen, signieren und aktivieren** veröffentlicht sie. Diese Codeerweiterung aktiviert weder einen neuen Katalog noch eine GPU- oder Provider-Runtime automatisch.

Der signierte Backend-Wunsch ist kein Nachweis für tatsächlich ausgelagerte Modellschichten oder hinreichenden VRAM. Dafür zeigt der Desktop seine Laufzeitdiagnose. Bei Teil-Offload bleiben Modellanteile beziehungsweise Kontextdaten im RAM; derselbe große Modell-Download wird durch fünf Profilnamen nicht kleiner.
