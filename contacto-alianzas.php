<?php
/* contacto-alianzas.php — handler of the /contacto/ form.

   Not a loan application: the form is for entidades, medios, socios and for
   data-subject requests. Flow (docs/PARTNER-FORM.md):
     1. POST only; refuse while the form is off (flag + titular name in config.php)
     2. spam control: honeypot, minimum 3 s since the page was rendered, 5 per IP per hour
     3. server-side validation, including the consent box
     4. server-to-server POST to VenderCRM (10 s timeout, key from config.php)
     5. optional owner notification email (only if config.php sets notify_email)
     6. ALWAYS redirect to /gracias-contacto/, whatever the CRM answered
   The key, the endpoint and any error are never shown to the visitor. */
declare(strict_types=1);

require_once __DIR__ . '/_includes/common.inc.php';

ini_set('display_errors', '0');
ini_set('log_errors', '1');
@ini_set('error_log', pm_storage_dir() . DIRECTORY_SEPARATOR . 'php-errors.log');
date_default_timezone_set(PM_TIMEZONE);

const PM_MIN_SECONDS = 3;
const PM_MAX_PER_HOUR = 5;

function pm_go(string $path): void
{
    header('Location: ' . $path, true, 303);
    exit;
}

function pm_thanks(): void
{
    pm_go('/gracias-contacto/');
}

function pm_back(string $code): void
{
    pm_go('/contacto/?e=' . rawurlencode($code) . '#formulario');
}

/** True when this IP is over the hourly limit; otherwise records the hit. */
function pm_rate_limited(string $ip): bool
{
    $file = pm_storage_dir() . DIRECTORY_SEPARATOR . 'rl-' . hash('sha256', $ip . '|' . pm_secret()) . '.json';
    $now = time();
    $hits = [];
    if (is_file($file)) {
        $decoded = json_decode((string)file_get_contents($file), true);
        if (is_array($decoded)) {
            $hits = array_values(array_filter($decoded, static fn($t) => is_int($t) && $t > $now - 3600));
        }
    }
    if (count($hits) >= PM_MAX_PER_HOUR) {
        return true;
    }
    $hits[] = $now;
    @file_put_contents($file, json_encode($hits), LOCK_EX);
    return false;
}

/** One line, no control characters, capped length. */
function pm_clean(mixed $value, int $max): string
{
    $text = is_string($value) ? $value : '';
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? '';
    return mb_substr(trim($text), 0, $max);
}

function pm_post_crm(string $phone, string $name, string $message): void
{
    $endpoint = (string)pm_cfg('vcrm_endpoint');
    $key = (string)pm_cfg('vcrm_api_key');
    if ($endpoint === '' || $key === '' || stripos($key, 'PASTE') !== false) {
        error_log('[prestamo-contacto] CRM not configured: lead not sent');
        return;
    }
    $payload = json_encode([
        'phone'           => $phone,
        'name'            => $name,
        'message'         => $message,
        'source'          => 'prestamo-contacto',
        'idempotency_key' => pm_uuid(),
    ], JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        error_log('[prestamo-contacto] could not encode the CRM payload');
        return;
    }
    try {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'X-Api-Key: ' . $key],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT        => 10,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false || $status < 200 || $status >= 300) {
            error_log('[prestamo-contacto] CRM status ' . $status . ' ' . $err . ' ' . mb_substr((string)$body, 0, 300));
        }
    } catch (Throwable $e) {
        error_log('[prestamo-contacto] CRM exception: ' . $e->getMessage());
    }
}

/* ---------------------------------------------------------------- flow */

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    pm_go('/contacto/');
}
if (!pm_form_enabled()) {
    // Off: nothing is read, nothing is stored, nothing is sent.
    pm_go('/contacto/');
}

$tipo = pm_clean($_POST['tipo'] ?? '', 20);
if (!array_key_exists($tipo, PM_TYPES)) {
    pm_back('campos');
}
if ($tipo === 'persona') {
    pm_back('persona'); // consumers are deflected; no field is processed
}

// Spam control: all silent, the visitor sees the thank-you page.
if (pm_clean($_POST['sitio_web'] ?? '', 200) !== '') {
    pm_thanks();
}
$ts = pm_clean($_POST['ts'] ?? '', 20);
$sig = pm_clean($_POST['sig'] ?? '', 100);
if (!ctype_digit($ts) || !hash_equals(pm_sign($ts), $sig) || (time() - (int)$ts) < PM_MIN_SECONDS) {
    pm_thanks();
}
if (pm_rate_limited((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'))) {
    pm_thanks();
}

// Server-side consent check: the box must be ticked.
if (($_POST['consentimiento'] ?? '') !== '1') {
    pm_back('consent');
}

$nombre = pm_clean($_POST['nombre'] ?? '', 120);
$org = pm_clean($_POST['organizacion'] ?? '', 120);
$mensajeRaw = is_string($_POST['mensaje'] ?? null) ? trim($_POST['mensaje']) : '';
if (mb_strlen($mensajeRaw) > PM_MESSAGE_MAX) {
    pm_back('largo');
}
$mensaje = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $mensajeRaw) ?? '';
$phone = pm_normalize_phone(pm_clean($_POST['telefono'] ?? '', 30));

if ($nombre === '' || $mensaje === '' || $phone === '' || ($tipo === 'alianza' && $org === '')) {
    pm_back('campos');
}

$consentLine = 'Consentimiento ' . PM_CONSENT_VERSION . ' aceptado el ' . date('Y-m-d H:i') . ' (' . PM_TIMEZONE . ')';
$label = PM_TYPES[$tipo];
$crmName = $org !== '' ? $nombre . ' (' . $org . ')' : $nombre;
$crmMessage = '[Contacto web · ' . $label . '] ' . $mensaje . ' — ' . $consentLine;

pm_post_crm($phone, $crmName, $crmMessage);

// Optional owner notification: only when config.php sets notify_email.
$notify = trim((string)pm_cfg('notify_email'));
if ($notify !== '' && filter_var($notify, FILTER_VALIDATE_EMAIL) !== false) {
    $from = trim((string)pm_cfg('from_email'));
    $headers = [];
    if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL) !== false) {
        $headers[] = 'From: ' . str_replace(["\r", "\n"], '', $from);
    }
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $subject = '=?UTF-8?B?' . base64_encode('Nuevo mensaje de contacto: ' . $label) . '?=';
    $body = "Tipo: {$label}\nNombre: {$nombre}\nOrganización: {$org}\nTeléfono: {$phone}\n\n{$mensaje}\n\n{$consentLine}\n";
    if (!@mail($notify, $subject, $body, implode("\r\n", $headers))) {
        error_log('[prestamo-contacto] notification email failed');
    }
}

pm_thanks();
