<?php
declare(strict_types=1);

final class AppError extends RuntimeException {}

final class Config
{
    public readonly string $directory;
    public readonly string $baseUrl;
    public readonly string $mailFrom;
    public readonly string $mailMode;
    public readonly string $sharonUrl;
    public readonly bool $local;

    public function __construct()
    {
        $path = __DIR__ . '/config.local.php';
        $settings = is_file($path) ? require $path : [];
        $read = static fn(string $key): string => (string)(getenv('EVENTLIJN_' . $key) ?: ($settings[$key] ?? ''));
        $this->directory = $read('DATA_DIR');
        $this->baseUrl = rtrim($read('BASE_URL'), '/') . '/';
        $this->mailFrom = $read('MAIL_FROM');
        $this->mailMode = $read('MAIL_MODE') ?: 'sendmail';
        $this->sharonUrl = $read('SHARON_URL');
        $url = parse_url($this->baseUrl);
        $this->local = $this->mailMode === 'file' && in_array($url['host'] ?? '', ['127.0.0.1', 'localhost', '[::1]'], true);
        if (!$this->directory || !filter_var($this->mailFrom, FILTER_VALIDATE_EMAIL)
            || !in_array($this->mailMode, ['sendmail', 'file'], true)
            || ($this->mailMode === 'file' && !$this->local)
            || (($url['scheme'] ?? '') !== 'https' && !($this->local && ($url['scheme'] ?? '') === 'http'))
            || empty($url['host']) || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment'])) {
            throw new RuntimeException('Ongeldige configuratie');
        }
    }

    public function cookiePath(): string
    {
        return parse_url($this->baseUrl, PHP_URL_PATH) ?: '/';
    }
}
