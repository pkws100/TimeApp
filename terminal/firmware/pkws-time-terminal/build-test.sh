#!/usr/bin/env sh
set -eu

# Reproducible review/CI build. The 1.1 environment compiles only the tracked
# non-production test configuration; it never reads TrustConfig.local.h.
root=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd)
pio_cmd=${PIO_CMD:-pio}
dist="$root/dist-test"

rm -rf "$dist"
mkdir -p "$dist"
(cd "$root/1.0" && "$pio_cmd" run)
(cd "$root/1.1" && "$pio_cmd" run -e esp32doit-devkit-v1-test)
(cd "$root/1.1" && "$pio_cmd" test -e native)
cp "$root/1.0/.pio/build/esp32doit-devkit-v1/firmware.bin" "$dist/pkws-time-terminal-1.0.bin"
cp "$root/1.1/.pio/build/esp32doit-devkit-v1-test/firmware.bin" "$dist/pkws-time-terminal-1.1-test.bin"
(cd "$dist" && sha256sum pkws-time-terminal-1.0.bin pkws-time-terminal-1.1-test.bin > SHA256SUMS)
