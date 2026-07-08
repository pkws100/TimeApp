# Empfohlene ESP32-Pinbelegung

Diese Belegung ist ein Startpunkt fuer die Firmware. Vor finalem PCB/Gehause bitte mit dem konkreten ESP32-Board pruefen.

## RC522 ueber SPI

| RC522     | ESP32 |
| ---       | --- |
| SDA/SS    | GPIO 5 |
| SCK       | GPIO 18 |
| MOSI      | GPIO 23 |
| MISO      | GPIO 19 |
| RST       | GPIO 27 |
| 3.3V      | 3V3 |
| GND       | GND |

## LCD 20x4 ueber I2C

| LCD I2C   | ESP32    |
| ---       | ---      |
| SDA       | GPIO 21  |
| SCL       | GPIO 22  |
| VCC       | 5V oder 3.3V nach Modul |
| GND       | GND      |

## Signale

| Bauteil       | ESP32   |
| ---           |    ---  |
| Gruene LED    | GPIO 25 |
| Rote LED      | GPIO 26 |
| Gelb LED      | GPIO 33 |
| KY-012 Buzzer | GPIO 32 |
| Setup-Taster  | GPIO 13 |

## Firmware-Konstanten

- `TERMINAL_ID`: entspricht `terminal_identifier` im Admin.
- `TERMINAL_TOKEN`: einmaliger Token aus dem Admin.
- `API_BASE_URL`: interne TimeApp-URL, zum Beispiel `http://192.168.1.10`.
- `CONFIG_PATH`: `/api/v1/terminal/config`.
- `SCAN_PATH`: `/api/v1/terminal/scan`.
