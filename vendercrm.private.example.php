<?php
// Copy manually to dirname($publicRoot)/private/vendercrm.php, OUTSIDE the
// document/deployment root. Set once on the server; never deploy or overwrite
// the resulting file. Replace both placeholders before loading.
return [
    'VENDERCRM_URL' => '', // HTTPS origin only, e.g. https://crm.example.com
    'VENDERCRM_API_KEY' => '', // Real server-side key, entered once.
];
