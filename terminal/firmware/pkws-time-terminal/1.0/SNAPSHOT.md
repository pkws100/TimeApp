# Firmware-1.0-Snapshot

Firmware 1.0 ist der eingefrorene Rückfallstand der bisherigen Terminal-Firmware. Ihr funktionales Verhalten und ihre interne Versionskennung `pkws-time-terminal-v0.1.1` bleiben unverändert.

| Eigenschaft | Wert |
| --- | --- |
| Ursprünglicher Git-Commit | `78519e2af5c1f0fef0403c195b06d2067dd98638` |
| Snapshot-Datum | 2026-07-14 |
| Ursprüngliche Firmware-Version | `pkws-time-terminal-v0.1.1` |
| PlatformIO-Plattform | `espressif32@7.0.1` |
| Arduino-ESP32-Core | `framework-arduinoespressif32@3.20017.241212` (Arduino-ESP32 2.0.17) |
| SHA-256 `src/main.cpp` | `3d9d60a22eae9b5895929c42c892d28dddb912cedfe3515eb0c86cdc6295f325` |

Die Plattform-/Core-Werte wurden durch Auflösen des vorher ungebundenen `espressif32`-Eintrags mit PlatformIO Core 6.1.19 ermittelt und danach festgeschrieben.

## Build und Flash

```bash
cd terminal/firmware/pkws-time-terminal/1.0
pio run
pio run -t upload
pio device monitor
```

Der Arduino-IDE-Wrapper bindet ausschließlich `../../src/main.cpp` dieser Version ein.

## Rollback von 1.1

1. Terminal-Konfiguration und gegebenenfalls noch nicht synchronisierte Offline-Scans aus 1.1 sichern bzw. synchronisieren.
2. In diesen Ordner wechseln und den obigen Build ausführen.
3. `pio run -t upload` auf dem richtigen seriellen Port starten.
4. Nach dem Neustart im lokalen Portal die Firmware-Version `pkws-time-terminal-v0.1.1` und einen erfolgreichen API-Test prüfen.

Ein normaler Firmware-Upload erhält LittleFS üblicherweise, sofern Partitionsschema und Upload-Einstellungen unverändert bleiben und kein vollständiges Löschen gewählt wird. Die 1.1-spezifischen Trust-/Queue-Daten werden von 1.0 dennoch nicht verwendet und müssen vor dem Rollback gesichert bzw. synchronisiert werden.

Die eingefrorene Firmware 1.0 enthält historische Standard-Zugangsdaten für Setup-AP und Portal. Sie ist deshalb ausschließlich als kurzfristiger Rollback in einem isolierten Notfallnetz vorgesehen; anschließend wieder auf 1.1 aktualisieren.
