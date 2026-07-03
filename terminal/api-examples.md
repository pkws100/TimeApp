# Terminal API Beispiele

## Authentifizierung

Jeder Request sendet:

```http
X-Terminal-ID: terminal-empfang
Authorization: Bearer 012345...
Content-Type: application/json
```

## Config abrufen

```http
GET /api/v1/terminal/config
```

```json
{
  "ok": true,
  "terminal": {
    "terminal_identifier": "terminal-empfang",
    "name": "Empfang",
    "welcome_text": "Willkommen",
    "settings": {}
  },
  "display": {
    "lines": ["Willkommen", "Tag vorhalten", "Bereit", "03.07.2026 08:15"],
    "hold_ms": 3000
  },
  "signal": {"led": "green", "beep": "ready"},
  "server_time": "2026-07-03T08:15:22+02:00"
}
```

## NFC-Scan

```http
POST /api/v1/terminal/scan
```

```json
{
  "request_id": "b9f4b8d7-92ec-45e8-b0e3-f7a8d81c6a01",
  "nfc_uid": "04:A1:B2:C3:D4",
  "device_time": "2026-07-03T08:15:00+02:00",
  "firmware_version": "esp32-terminal-v1"
}
```

```json
{
  "ok": true,
  "action": "check_in",
  "message": "Willkommen Max",
  "display": {
    "lines": ["Hallo Max", "Arbeitsbeginn", "08:15:22", "Soll 160:00"],
    "hold_ms": 15000
  },
  "signal": {"led": "green", "beep": "success"},
  "server_time": "2026-07-03T08:15:22+02:00"
}
```

## Fehler

Alle Fehler enthalten ebenfalls vier LCD-Zeilen:

```json
{
  "ok": false,
  "code": "unknown_tag",
  "message": "NFC-Tag unbekannt.",
  "display": {
    "lines": ["Fehler", "NFC-Tag unbekannt.", "Bitte Admin", "informieren"],
    "hold_ms": 15000
  },
  "signal": {"led": "red", "beep": "error"},
  "server_time": "2026-07-03T08:15:22+02:00"
}
```

## LCD-Regeln

- Immer genau vier Zeilen verarbeiten.
- Jede Zeile maximal 20 Zeichen anzeigen.
- Zu lange Zeilen linksbuendig kuerzen.
- Nach `hold_ms` zurueck zum Willkommen-Bildschirm.
