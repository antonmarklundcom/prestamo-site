<?php
/* _includes/common.inc.php — shared helpers for the contact form.
   No secrets live in this file. Secrets come from config.php, which the owner
   uploads by hand and which is never part of the repository or the zip. */
declare(strict_types=1);

const PM_CONSENT_VERSION = 'v1.0';
const PM_MESSAGE_MAX = 1000;
const PM_TIMEZONE = 'America/Asuncion';

/** Message types. The last one deflects consumers: it never shows or accepts a field. */
const PM_TYPES = [
    'alianza'    => 'Alianza o prensa',
    'correccion' => 'Corrección de contenido',
    'datos'      => 'Solicitud sobre mis datos',
    'otro'       => 'Otro',
    'persona'    => 'Persona que busca un préstamo',
];

/** Where config.php may live. One level above the web root is preferred. */
function pm_config_paths(): array
{
    $paths = [];
    $override = getenv('PRESTAMO_CONFIG');
    if (is_string($override) && $override !== '') {
        $paths[] = $override;
    }
    $docroot = rtrim((string)($_SERVER['DOCUMENT_ROOT'] ?? ''), '/\\');
    if ($docroot !== '') {
        $paths[] = dirname($docroot) . DIRECTORY_SEPARATOR . 'config.php';
    }
    $paths[] = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'config.php';
    return $paths;
}

/** @return array{cfg: array<string,mixed>, path: string} */
function pm_load_config(): array
{
    static $loaded = null;
    if ($loaded !== null) {
        return $loaded;
    }
    $defaults = [
        'contact_form_enabled' => false,   // stays off until the owner flips it
        'titular_name'         => '',      // razón social or the owner's legal name
        'vcrm_endpoint'        => 'https://crm.clientes.com.py/api/v1/leads',
        'vcrm_api_key'         => '',
        'notify_email'         => '',      // optional; never shown on the site
        'from_email'           => '',
        'form_secret'          => '',
        'storage_dir'          => '',
    ];
    foreach (pm_config_paths() as $path) {
        if (is_file($path)) {
            $loadedCfg = require $path;
            if (is_array($loadedCfg)) {
                $loaded = ['cfg' => array_merge($defaults, $loadedCfg), 'path' => $path];
                return $loaded;
            }
        }
    }
    $loaded = ['cfg' => $defaults, 'path' => ''];
    return $loaded;
}

function pm_cfg(string $key)
{
    return pm_load_config()['cfg'][$key] ?? null;
}

/** The form is live only with the flag on AND a titular name set. */
function pm_form_enabled(): bool
{
    return pm_cfg('contact_form_enabled') === true && trim((string)pm_cfg('titular_name')) !== '';
}

function pm_secret(): string
{
    $secret = (string)pm_cfg('form_secret');
    return $secret !== '' ? $secret : 'prestamo-contacto-default';
}

function pm_sign(string $ts): string
{
    return hash_hmac('sha256', $ts, pm_secret());
}

function pm_storage_dir(): string
{
    $dir = (string)pm_cfg('storage_dir');
    if ($dir === '') {
        $path = pm_load_config()['path'];
        $base = $path !== '' ? dirname($path) : dirname(__DIR__);
        $dir = $base . DIRECTORY_SEPARATOR . 'storage';
    }
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
        @file_put_contents($dir . DIRECTORY_SEPARATOR . '.htaccess', "Require all denied\n");
    }
    return $dir;
}

function pm_h(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Normalize a Paraguayan phone to +595XXXXXXXXX, or '' when it does not look like one. */
function pm_normalize_phone(string $raw): string
{
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if ($digits === '') {
        return '';
    }
    if (strncmp($digits, '595', 3) === 0) {
        $digits = substr($digits, 3);
    }
    $digits = ltrim($digits, '0');
    if (strlen($digits) < 8 || strlen($digits) > 10) {
        return '';
    }
    return '+595' . $digits;
}

function pm_uuid(): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    $h = bin2hex($b);
    return sprintf('%s-%s-%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20, 12));
}
