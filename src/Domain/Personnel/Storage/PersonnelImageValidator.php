<?php

declare(strict_types=1);

namespace App\Domain\Personnel\Storage;

final class PersonnelImageValidator
{
    private const MAX_DIMENSION = 10000;
    private const MAX_PIXELS = 12000000;
    private const MAX_JPEG_SCANS = 64;
    private const PNG_CHUNKS = ['IHDR', 'PLTE', 'IDAT', 'IEND', 'tRNS', 'pHYs', 'gAMA', 'cHRM', 'sRGB', 'sBIT'];
    private const PNG_CHANNELS = [0 => 1, 2 => 3, 3 => 1, 4 => 2, 6 => 4];

    /** @param resource $file */
    public static function validate($file, string $path, string $mime, int $deadline): void
    {
        [$width, $height] = $mime === 'image/png' ? self::png($file, $deadline) : self::jpeg($file, $deadline);
        self::checkTime($deadline);
        $jpegWarnings = ini_get('gd.jpeg_ignore_warning');
        if ($mime === 'image/jpeg' && $jpegWarnings !== false) {
            ini_set('gd.jpeg_ignore_warning', '0');
        }
        set_error_handler(static fn () => throw PersonnelFileValidator::rejected());
        try {
            $image = $mime === 'image/png' ? imagecreatefrompng($path) : imagecreatefromjpeg($path);
            if (!$image instanceof \GdImage || imagesx($image) !== $width || imagesy($image) !== $height) {
                throw PersonnelFileValidator::rejected();
            }
        } finally {
            restore_error_handler();
            if ($mime === 'image/jpeg' && $jpegWarnings !== false) {
                ini_set('gd.jpeg_ignore_warning', $jpegWarnings);
            }
        }
        self::checkTime($deadline);
    }

    /** @param resource $file @return array{int,int} */
    private static function png($file, int $deadline): array
    {
        if (self::readExact($file, 8) !== "\x89PNG\r\n\x1a\n") {
            throw PersonnelFileValidator::rejected();
        }
        $read = [];
        $idatFinished = false;
        $decoded = 0;
        $decoder = @inflate_init(ZLIB_ENCODING_DEFLATE);
        if ($decoder === false) {
            throw PersonnelFileValidator::rejected();
        }
        while (true) {
            self::checkTime($deadline);
            $length = unpack('Nvalue', self::readExact($file, 4))['value'];
            $type = self::readExact($file, 4);
            if (!in_array($type, self::PNG_CHUNKS, true) || ($read === [] && $type !== 'IHDR')
                || ($type !== 'IDAT' && isset($read[$type])) || ($type === 'IDAT' && $idatFinished)
                || (isset($read['IDAT']) && !in_array($type, ['IDAT', 'IEND'], true))) {
                throw PersonnelFileValidator::rejected();
            }

            $crc = hash_init('crc32b');
            hash_update($crc, $type);
            if ($type === 'IHDR') {
                if ($length !== 13) {
                    throw PersonnelFileValidator::rejected();
                }
                $payload = self::readExact($file, 13);
                hash_update($crc, $payload);
                $header = unpack('Nwidth/Nheight/Cdepth/Ccolor/Ccompression/Cfilter/Cinterlace', $payload);
                $width = $header['width'];
                $height = $header['height'];
                self::requireDimensions($width, $height);
                $depth = $header['depth'];
                $color = $header['color'];
                if (!isset(self::PNG_CHANNELS[$color]) || !in_array($depth, [1, 2, 4, 8, 16], true)
                    || $header['compression'] !== 0 || $header['filter'] !== 0 || $header['interlace'] !== 0) {
                    throw PersonnelFileValidator::rejected();
                }
                $maxDecoded = $height * (1 + intdiv($width * self::PNG_CHANNELS[$color] * $depth + 7, 8));
            } elseif ($type === 'IDAT') {
                if ($length > 33554432 || ($color === 3 && !isset($read['PLTE']))) {
                    throw PersonnelFileValidator::rejected();
                }
                for ($remaining = $length; $remaining > 0; $remaining -= strlen($compressed)) {
                    self::checkTime($deadline);
                    if (inflate_get_status($decoder) === ZLIB_STREAM_END) {
                        throw PersonnelFileValidator::rejected();
                    }
                    $compressed = self::readExact($file, min(4096, $remaining));
                    hash_update($crc, $compressed);
                    $priorCount = inflate_get_read_len($decoder);
                    $plain = @inflate_add($decoder, $compressed, ZLIB_SYNC_FLUSH);
                    if ($plain === false || inflate_get_read_len($decoder) - $priorCount !== strlen($compressed)) {
                        throw PersonnelFileValidator::rejected();
                    }
                    $decoded += strlen($plain);
                    if ($decoded > $maxDecoded) {
                        throw PersonnelFileValidator::rejected();
                    }
                }
            } elseif ($type === 'IEND') {
                if ($length !== 0 || !isset($read['IDAT']) || $decoded !== $maxDecoded || inflate_get_status($decoder) !== ZLIB_STREAM_END) {
                    throw PersonnelFileValidator::rejected();
                }
                $idatFinished = true;
            } else {
                if (!self::validPngAuxiliaryLength($type, $length, $color, $read)) {
                    throw PersonnelFileValidator::rejected();
                }
                hash_update($crc, self::readExact($file, $length));
            }

            if (!hash_equals(hash_final($crc, true), self::readExact($file, 4))) {
                throw PersonnelFileValidator::rejected();
            }
            if ($idatFinished) {
                self::requireEof($file);

                return [$width, $height];
            }
            $read[$type] = $length;
        }
    }

    private static function validPngAuxiliaryLength(string $type, int $length, int $color, array $read): bool
    {
        return match ($type) {
            'PLTE' => !in_array($color, [0, 4], true) && $length >= 3 && $length <= 768 && $length % 3 === 0,
            'tRNS' => match ($color) {
                0 => $length === 2,
                2 => $length === 6,
                3 => isset($read['PLTE']) && $length >= 1 && $length <= intdiv($read['PLTE'], 3),
                default => false,
            },
            'pHYs' => $length === 9,
            'gAMA' => $length === 4,
            'cHRM' => $length === 32,
            'sRGB' => $length === 1,
            'sBIT' => $length === ($color === 3 ? 3 : self::PNG_CHANNELS[$color]),
            default => false,
        };
    }

    /** @param resource $file @return array{int,int} */
    private static function jpeg($file, int $deadline): array
    {
        if (self::readExact($file, 2) !== "\xff\xd8") {
            throw PersonnelFileValidator::rejected();
        }
        $scan = false;
        $scanCount = 0;
        $dimensions = null;
        $apps = [];
        while (true) {
            self::checkTime($deadline);
            $marker = self::jpegMarker($file, $deadline, $scan);
            $scan = false;
            if ($marker === 0xd9) {
                if ($dimensions === null || $scanCount === 0) {
                    throw PersonnelFileValidator::rejected();
                }
                self::requireEof($file);

                return $dimensions;
            }
            if (!in_array($marker, [0xc0, 0xc1, 0xc2, 0xc4, 0xda, 0xdb, 0xdd, 0xe0, 0xee, 0xfe], true)) {
                throw PersonnelFileValidator::rejected();
            }
            $length = unpack('nvalue', self::readExact($file, 2))['value'] - 2;
            if ($length < 1) {
                throw PersonnelFileValidator::rejected();
            }
            $segment = self::readExact($file, $length);
            if (in_array($marker, [0xc0, 0xc1, 0xc2], true)) {
                if ($dimensions !== null || $length < 9) {
                    throw PersonnelFileValidator::rejected();
                }
                $frame = unpack('Cprecision/nheight/nwidth/Ccomponents', $segment);
                if ($frame['precision'] !== 8 || !in_array($frame['components'], [1, 3, 4], true) || $length !== 6 + 3 * $frame['components']) {
                    throw PersonnelFileValidator::rejected();
                }
                self::requireDimensions($frame['width'], $frame['height']);
                $dimensions = [$frame['width'], $frame['height']];
            } elseif ($marker === 0xda) {
                if ($dimensions === null || $length !== 4 + 2 * ord($segment[0]) || ++$scanCount > self::MAX_JPEG_SCANS) {
                    throw PersonnelFileValidator::rejected();
                }
                $scan = true;
            } elseif ($marker === 0xe0 || $marker === 0xee || $marker === 0xfe) {
                $valid = match ($marker) {
                    0xe0 => $length === 14 && str_starts_with($segment, "JFIF\0") && substr($segment, -2) === "\0\0",
                    0xee => $length === 12 && str_starts_with($segment, 'Adobe'),
                    0xfe => preg_match('~\A[a-zA-Z0-9 .,()_=:/+%\-]{1,255}(?:\r?\n)?\z~', $segment) === 1,
                };
                if (isset($apps[$marker]) || $dimensions !== null || !$valid) {
                    throw PersonnelFileValidator::rejected();
                }
                $apps[$marker] = true;
            }
        }
    }

    /** @param resource $file */
    private static function jpegMarker($file, int $deadline, bool $inScan): int
    {
        $read = 0;
        while (($byte = fgetc($file)) !== false) {
            if ((++$read & 16383) === 0) {
                self::checkTime($deadline);
            }
            if ($byte !== "\xff") {
                if (!$inScan) {
                    break;
                }
                continue;
            }
            do {
                $suffix = fgetc($file);
                if ((++$read & 16383) === 0) {
                    self::checkTime($deadline);
                }
            } while ($suffix === "\xff");
            if ($suffix === false) {
                break;
            }
            $marker = ord($suffix);
            if ($inScan && ($marker === 0 || ($marker >= 0xd0 && $marker <= 0xd7))) {
                continue;
            }

            return $marker;
        }

        throw PersonnelFileValidator::rejected();
    }

    private static function requireDimensions(int $width, int $height): void
    {
        if ($width < 1 || $height < 1 || $width > self::MAX_DIMENSION || $height > self::MAX_DIMENSION || $width * $height > self::MAX_PIXELS) {
            throw PersonnelFileValidator::rejected();
        }
    }

    private static function checkTime(int $deadline): void
    {
        if (hrtime(true) > $deadline) {
            throw PersonnelFileValidator::rejected();
        }
    }

    /** @param resource $file */
    private static function requireEof($file): void
    {
        if (fgetc($file) !== false || !feof($file)) {
            throw PersonnelFileValidator::rejected();
        }
    }

    /** @param resource $file */
    private static function readExact($file, int $length): string
    {
        $result = '';
        while (strlen($result) < $length) {
            $chunk = @fread($file, $length - strlen($result));
            if ($chunk === false || $chunk === '') {
                throw PersonnelFileValidator::rejected();
            }
            $result .= $chunk;
        }

        return $result;
    }
}
