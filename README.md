
## Persistent VenderCRM setup

Requires PHP 7.4+. New CRM setup uses only `VENDERCRM_URL` (HTTPS origin,
without `/api/v1/leads`) and `VENDERCRM_API_KEY`. Copy
`vendercrm.private.example.php` manually to `../private/vendercrm.php` outside
this site's document/deployment root, fill both values once on the server and
restrict permissions to the PHP account. Keep the sibling private directory
outside every redeploy replacement; never overwrite/reset that file or commit it.
Alternatively configure both canonical variables in PHP's process environment.
For another hosting layout, use an absolute `VENDERCRM_CONFIG_FILE` outside
the actual document root. Verify the actual root and deployed branch first.
Uploading a `.env` file alone does not populate PHP's environment.

Canonical settings win over old CRM values; canonical environment and private
file must agree when both are present. Partial canonical pairs and unsafe
paths fail without blending old credentials. With no canonical setup, existing
legacy settings and inactive behavior remain available. Non-CRM settings,
notification requirements, form enablement, storage, transport and recovery
keep their existing loaders and gates. DNS names must resolve to public addresses.
Run `php tests/vendercrm-config.php` for offline synthetic configuration checks.
# prestamo-site
