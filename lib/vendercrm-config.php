<?php
declare(strict_types=1);

namespace VenderCRM;

/** PHP 7.4+. Configuration only: never writes files or sends requests. */
final class Config
{
    private string $url;
    private string $key;
    private array $sources;

    private function __construct(string $url, string $key, array $sources)
    {
        $this->url = $url;
        $this->key = $key;
        $this->sources = $sources;
    }

    /**
     * Migration entry point: canonical settings override the old site loader.
     * Return null only when no canonical source was configured, allowing the
     * caller to keep its existing effective legacy configuration unchanged.
     * A partial pair or explicit missing/unsafe path still fails in load().
     */
    public static function optional(string $publicRoot, ?array $environment = null): ?self
    {
        $root = realpath($publicRoot);
        if ($root === false || !is_dir($root)) {
            throw new \RuntimeException('VenderCRM document root is invalid.');
        }
        $env = $environment ?? [
            'VENDERCRM_URL' => getenv('VENDERCRM_URL'),
            'VENDERCRM_API_KEY' => getenv('VENDERCRM_API_KEY'),
            'VENDERCRM_CONFIG_FILE' => getenv('VENDERCRM_CONFIG_FILE'),
        ];
        foreach (['VENDERCRM_URL', 'VENDERCRM_API_KEY', 'VENDERCRM_CONFIG_FILE'] as $name) {
            if (isset($env[$name]) && $env[$name] !== false && $env[$name] !== '') {
                return self::load($root, $env);
            }
        }
        $path = dirname($root) . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'vendercrm.php';
        return file_exists($path) || is_link($path) ? self::load($root, $env) : null;
    }

    /**
     * Explicitly pass the trusted, existing document root. Environment defaults
     * to getenv; pass an array for controlled environments/tests. Legacy inputs
     * are explicitly mapped canonical pairs, never auto-discovered files.
     * Precedence: environment > private file > legacy; every populated source
     * must agree after validation. No partial pairs are permitted.
     */
    public static function load(string $publicRoot, ?array $environment = null, array $legacy = [], bool $allowLocalTest = false): self
    {
        $root = realpath($publicRoot);
        if ($root === false || !is_dir($root)) {
            throw new \RuntimeException('VenderCRM document root is invalid.');
        }
        $env = $environment ?? [
            'VENDERCRM_URL' => getenv('VENDERCRM_URL'),
            'VENDERCRM_API_KEY' => getenv('VENDERCRM_API_KEY'),
            'VENDERCRM_CONFIG_FILE' => getenv('VENDERCRM_CONFIG_FILE'),
        ];
        $override = $env['VENDERCRM_CONFIG_FILE'] ?? null;
        if ($override !== null && $override !== false && $override !== '') {
            if (!is_string($override) || !preg_match('~^(?:/|[A-Za-z]:[\\\\/]|\\\\\\\\)~', $override)) {
                throw new \RuntimeException('VenderCRM config override must be absolute.');
            }
            $path = $override;
        } else {
            $path = dirname($root) . DIRECTORY_SEPARATOR . 'private' . DIRECTORY_SEPARATOR . 'vendercrm.php';
        }
        $file = [];
        if (file_exists($path) || is_link($path)) {
            $real = realpath($path);
            if ($real === false || !is_file($real) || self::inside($real, $root)) {
                throw new \RuntimeException('VenderCRM config must be a file physically outside the document root.');
            }
            // PHP config is trusted executable server code. Suppress its output
            // and sanitize all loading failures so credentials cannot escape.
            ob_start();
            try {
                $file = (static function ($configPath) { return require $configPath; })($real);
            } catch (\Throwable $error) {
                throw new \RuntimeException('VenderCRM private config could not be loaded.');
            } finally {
                ob_end_clean();
            }
            if (!is_array($file)) {
                throw new \RuntimeException('VenderCRM private config must return an array.');
            }
        } elseif ($override !== null && $override !== false && $override !== '') {
            throw new \RuntimeException('VenderCRM explicit private config is missing.');
        }
        $pairs = [];
        foreach (['environment' => $env, 'private-file' => $file, 'legacy-adapter' => $legacy] as $label => $values) {
            $u = $values['VENDERCRM_URL'] ?? null;
            $k = $values['VENDERCRM_API_KEY'] ?? null;
            $hasU = $u !== null && $u !== false && $u !== '';
            $hasK = $k !== null && $k !== false && $k !== '';
            if (!$hasU && !$hasK) { continue; }
            if (!$hasU || !$hasK || !is_string($u) || !is_string($k)) {
                throw new \RuntimeException('VenderCRM source requires a complete canonical URL and key pair.');
            }
            if (trim($k) === '' || preg_match('/[\x00-\x1F\x7F]/', $k)) {
                throw new \RuntimeException('VenderCRM API key must be nonempty and header-safe.');
            }
            $pairs[$label] = [self::origin($u, $label === 'legacy-adapter', $allowLocalTest), $k];
        }
        if (!$pairs) {
            throw new \RuntimeException('VenderCRM is unconfigured: provide a private config outside the document root or the canonical environment pair.');
        }
        $first = reset($pairs);
        foreach ($pairs as $pair) {
            if ($first[0] !== $pair[0] || !hash_equals($first[1], $pair[1])) {
                throw new \RuntimeException('VenderCRM configuration sources conflict.');
            }
        }
        return new self($first[0], $first[1], array_keys($pairs));
    }

    private static function inside(string $path, string $root): bool
    {
        $path = str_replace('\\', '/', $path);
        $root = rtrim(str_replace('\\', '/', $root), '/');
        if (DIRECTORY_SEPARATOR === '\\') { $path = strtolower($path); $root = strtolower($root); }
        return $path === $root || strpos($path, $root . '/') === 0;
    }

    private static function origin(string $url, bool $legacy, bool $local): string
    {
        $p = parse_url($url);
        if ($p === false || preg_match('/[\x00-\x20\x7F\\\\]/', $url) || strtolower($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['user']) || isset($p['pass']) || isset($p['query']) || isset($p['fragment']) || (isset($p['port']) && ($p['port'] < 1 || $p['port'] > 65535))) {
            throw new \RuntimeException('VenderCRM URL must be a clean HTTPS origin.');
        }
        $path = $p['path'] ?? '';
        if ($path !== '' && $path !== '/' && !($legacy && $path === '/api/v1/leads')) {
            throw new \RuntimeException('VenderCRM canonical URL must not contain an API route or other path.');
        }
        $host = strtolower($p['host']);
        $ip = trim($host, '[]');
        $isIp = filter_var($ip, FILTER_VALIDATE_IP) !== false;
        if (!$local && ((!$isIp && (strpos($host, '.') === false || preg_match('/^[0-9.]+$/', $host))) || preg_match('/(?:^|\.)(?:localhost|local|internal|test|invalid)$/', $host) || ($isIp && (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) || stripos($ip, '::ffff:') === 0)))) {
            throw new \RuntimeException('VenderCRM URL must use a public endpoint.');
        }
        if (!filter_var($ip, FILTER_VALIDATE_IP) && !preg_match('/^(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $host)) {
            throw new \RuntimeException('VenderCRM URL hostname is invalid.');
        }
        return 'https://' . $host . (isset($p['port']) && $p['port'] !== 443 ? ':' . $p['port'] : '');
    }

    public function endpoint(): string { return $this->url . '/api/v1/leads'; }
    /** Server-side transport use only; never serialize this value. */
    public function apiKey(): string { return $this->key; }
    public function doctor(): array { return ['configured' => true, 'url' => $this->url, 'endpoint' => $this->endpoint(), 'sources' => $this->sources]; }
    public function __debugInfo(): array { return $this->doctor(); }
}
