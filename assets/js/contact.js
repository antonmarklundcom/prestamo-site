/* engine/assets/js/contact.js — progressive enhancement for the /contacto/ form.
   Runs only on that page. The server re-checks everything (consent included),
   so this script is a convenience, never the control. */
(function () {
  'use strict';

  var root = document.querySelector('[data-contactform]');
  if (!root) return;

  var form = root.querySelector('[data-contact-form]');
  var radios = root.querySelectorAll('input[name="tipo"]');
  var consumer = root.querySelector('[data-contact-consumer]');
  var fields = root.querySelector('[data-contact-fields]');
  var check = root.querySelector('[data-consent-check]');
  var submit = root.querySelector('[data-contact-submit]');
  var error = root.querySelector('[data-consent-error]');
  var box = root.querySelector('[data-consent-box]');
  if (!form || !check || !submit) return;

  function selectedType() {
    for (var i = 0; i < radios.length; i += 1) if (radios[i].checked) return radios[i].value;
    return '';
  }

  /* "Persona que busca un préstamo": no field is shown, so no data can be sent. */
  function syncType() {
    var isConsumer = selectedType() === 'persona';
    if (consumer) consumer.hidden = !isConsumer;
    if (fields) fields.hidden = isConsumer;
    var org = form.querySelector('input[name="organizacion"]');
    if (org) org.required = selectedType() === 'alianza';
  }

  /* The send button stays disabled until the consent box is ticked. */
  function syncConsent() {
    submit.disabled = !check.checked;
    if (check.checked) {
      if (error) error.hidden = true;
      if (box) box.classList.remove('is-missing');
    }
  }

  for (var i = 0; i < radios.length; i += 1) radios[i].addEventListener('change', syncType);
  check.addEventListener('change', syncConsent);

  form.addEventListener('submit', function (event) {
    if (selectedType() === 'persona' || !check.checked) {
      event.preventDefault();
      if (!check.checked && error) {
        error.hidden = false;
        if (box) box.classList.add('is-missing');
      }
    }
  });

  syncType();
  syncConsent();
})();
