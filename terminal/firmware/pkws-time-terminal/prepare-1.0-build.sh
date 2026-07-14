#!/usr/bin/env sh
set -eu

root=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
pio_cmd=${PIO_CMD:-pio}

# Firmware 1.0 is a frozen rollback source tree whose platformio.ini must stay
# unchanged. Install its historically verified direct dependencies explicitly
# so a future compatible registry release cannot silently change the build.
"$pio_cmd" pkg install --project-dir "$root/1.0" --no-save \
  --library "miguelbalboa/MFRC522@1.4.12" \
  --library "marcoschwartz/LiquidCrystal_I2C@1.1.4" \
  --library "bblanchon/ArduinoJson@6.21.6"
