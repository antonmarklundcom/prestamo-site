<?php
/* _includes/contact-form.inc.php — rendered inside /contacto/ (contacto/index.php).
   While the form is off it prints only the "próximamente" notice: no field, no
   data collection. */
declare(strict_types=1);
require_once __DIR__ . '/common.inc.php';

ini_set('display_errors', '0');

if (!pm_form_enabled()) {
    echo '<p class="notice notice--soon" data-contact-soon role="status"><strong>Formulario disponible próximamente.</strong> Mientras tanto, este sitio no recoge ningún dato.</p>';
    return;
}

$titular = pm_h(trim((string)pm_cfg('titular_name')));
$ts = (string)time();
$sig = pm_sign($ts);
$errors = [
    'consent' => 'Para enviar el mensaje necesitamos tu autorización.',
    'campos'  => 'Revisá los campos marcados como obligatorios y el teléfono.',
    'largo'   => 'El mensaje supera los 1.000 caracteres.',
];
$e = (string)($_GET['e'] ?? '');
?>
<div class="contactform" data-contactform>
  <?php if ($e === 'persona') : ?>
    <p class="notice" role="alert">Este sitio es informativo y no tramita solicitudes. Para comparar opciones leé nuestras guías y verificá siempre que la entidad esté registrada en el BCP.</p>
  <?php elseif (isset($errors[$e])) : ?>
    <p class="form-error" role="alert"><?= pm_h($errors[$e]) ?></p>
  <?php endif; ?>

  <form method="post" action="/contacto-alianzas.php" data-contact-form novalidate>
    <fieldset class="field-group">
      <legend>Tipo de mensaje</legend>
      <?php foreach (PM_TYPES as $value => $label) : ?>
        <label class="radio"><input type="radio" name="tipo" value="<?= pm_h($value) ?>" required<?= $value === 'alianza' ? ' checked' : '' ?>> <span><?= pm_h($label) ?></span></label>
      <?php endforeach; ?>
    </fieldset>

    <div class="contactform__consumer" data-contact-consumer hidden>
      <p class="notice" role="note">Este sitio es informativo y no tramita solicitudes. Para comparar opciones leé nuestras guías y verificá siempre que la entidad esté registrada en el BCP.</p>
      <ul class="list list--links">
        <li><a href="/prestamos/requisitos/">Requisitos habituales de un préstamo</a></li>
        <li><a href="/tasas-bcp/">Tasas y topes del BCP</a></li>
        <li><a href="/seguridad/">Cómo verificar una entidad y reconocer fraudes</a></li>
        <li><a href="/prestamos/personales/">Guía de préstamos personales</a></li>
      </ul>
    </div>

    <div class="contactform__fields" data-contact-fields>
      <label class="field"><span>Nombre y apellido</span>
        <input type="text" name="nombre" maxlength="120" autocomplete="name" required></label>
      <label class="field"><span>Organización <small>(obligatoria solo para alianzas o prensa)</small></span>
        <input type="text" name="organizacion" maxlength="120" autocomplete="organization"></label>
      <label class="field"><span>Teléfono o WhatsApp</span>
        <input type="tel" name="telefono" maxlength="30" autocomplete="tel" inputmode="tel" placeholder="0981 123 456" required></label>
      <label class="field"><span>Mensaje <small>(máximo 1.000 caracteres)</small></span>
        <textarea name="mensaje" rows="6" maxlength="1000" required></textarea>
        <small>No incluyas datos personales de terceros, números de cédula, datos bancarios ni información sobre deudas.</small></label>

      <div class="hp" aria-hidden="true">
        <label>No completar este campo <input type="text" name="sitio_web" tabindex="-1" autocomplete="off"></label>
      </div>
      <input type="hidden" name="ts" value="<?= pm_h($ts) ?>">
      <input type="hidden" name="sig" value="<?= pm_h($sig) ?>">

      <div class="consent-box" data-consent-box>
        <p class="consent-box__title"><strong>Qué hacemos con tus datos</strong></p>
        <ul>
          <li>Los usamos solo para responder a este mensaje.</li>
          <li>Los ve <?= $titular ?> y nuestro proveedor de CRM (VenderCRM).</li>
          <li>No los vendemos ni los compartimos con bancos, financieras ni aseguradoras.</li>
          <li>Podés pedir que los borremos cuando quieras, usando este mismo formulario (tipo de mensaje: Solicitud sobre mis datos).</li>
        </ul>
        <label class="consent-box__label"><input type="checkbox" name="consentimiento" value="1" required data-consent-check>
          <span>Sí, acepto que <?= $titular ?> guarde estos datos y me contacte por teléfono o WhatsApp <strong>solo para responder a este mensaje</strong>.</span></label>
        <p class="consent-box__error" data-consent-error role="alert" hidden>Para enviar el mensaje necesitamos tu autorización.</p>
        <p class="consent-box__meta">Leé la <a href="/politica-de-privacidad/">Política de privacidad</a>. <span>(Texto <?= pm_h(PM_CONSENT_VERSION) ?>)</span></p>
      </div>

      <button type="submit" class="btn btn--primary" data-contact-submit>Enviar mensaje</button>
    </div>
  </form>
  <noscript><p class="notice">Para enviar el mensaje necesitás tener JavaScript activado en el navegador.</p></noscript>
</div>
