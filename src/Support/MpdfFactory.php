<?php

declare(strict_types=1);

namespace App\Support;

use Mpdf\Mpdf;

final class MpdfFactory
{
    public static function create(array $config = []): Mpdf
    {
        $hadScriptName = array_key_exists('SCRIPT_NAME', $_SERVER);
        $previousScriptName = $_SERVER['SCRIPT_NAME'] ?? null;

        if (!is_string($previousScriptName) || trim($previousScriptName) === '') {
            $_SERVER['SCRIPT_NAME'] = '/index.php';
        }

        try {
            return new Mpdf($config);
        } finally {
            if ($hadScriptName) {
                $_SERVER['SCRIPT_NAME'] = $previousScriptName;
            } else {
                unset($_SERVER['SCRIPT_NAME']);
            }
        }
    }
}
