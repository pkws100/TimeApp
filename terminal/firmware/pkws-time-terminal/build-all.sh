#!/usr/bin/env sh
set -eu

root=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
pio_cmd=${PIO_CMD:-pio}
dist="$root/dist"

rm -rf "$dist"
mkdir -p "$dist"
PIO_CMD="$pio_cmd" sh "$root/prepare-1.0-build.sh"
for version in 1.0 1.1; do
  (cd "$root/$version" && "$pio_cmd" run)
  cp "$root/$version/.pio/build/esp32doit-devkit-v1/firmware.bin" "$dist/pkws-time-terminal-$version.bin"
done
(cd "$dist" && sha256sum pkws-time-terminal-1.0.bin pkws-time-terminal-1.1.bin > SHA256SUMS)
