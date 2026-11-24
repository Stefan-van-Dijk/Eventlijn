<?php
declare(strict_types=1);

/** Private JSON store. The configured directory must be outside the document root. */
final class EventStore {
    private string $directory;
    public function __construct(string $directory, string $documentRoot) {
        $root = realpath($documentRoot);
        $dir = realpath($directory);
        if ($root === false || $dir === false || !is_dir($dir) || !is_writable($dir)
            || (fileperms($dir) & 0077) !== 0 || $dir === $root || str_starts_with($dir, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)) {
            throw new RuntimeException('Private storage unavailable');
        }
        $this->directory = $dir;
    }
    public static function id(): string { return bin2hex(random_bytes(16)); }
    public function transaction(string $organization, callable $change): mixed {
        if (!preg_match('/\A[a-f0-9]{32}\z/', $organization)) {
            throw new InvalidArgumentException('Invalid organization identifier');
        }
        $path = $this->directory.DIRECTORY_SEPARATOR.$organization.'.json';
        if (is_link($path) || is_link($path.'.lock')) throw new RuntimeException('Invalid storage');
        $lock = fopen($path.'.lock', 'c');
        if ($lock === false) throw new RuntimeException('Lock unavailable');
        $tmp = null;
        try {
            if (!chmod($path.'.lock', 0600) || !flock($lock, LOCK_EX)) throw new RuntimeException('Lock unavailable');
            $exists = is_file($path);
            $state = $exists ? json_decode((string)file_get_contents($path), true, 128, JSON_THROW_ON_ERROR)
                : ['schema'=>1,'organization'=>$organization,'members'=>[],'events'=>[],'requests'=>[]];
            if (!is_array($state) || ($state['schema'] ?? null)!==1 || ($state['organization'] ?? null)!==$organization
                || !is_array($state['members'] ?? null) || !is_array($state['events'] ?? null) || !is_array($state['requests'] ?? null)) {
                throw new RuntimeException('Invalid organization document');
            }
            $before = $state;
            $result = $change($state);
            if ($state !== $before) {
                if (($state['organization'] ?? null)!==$organization || ($state['schema'] ?? null)!==1) throw new RuntimeException('Invalid mutation');
                $tmp = tempnam($this->directory, '.eventlijn-');
                if ($tmp === false) throw new RuntimeException('Temporary file unavailable');
                $json = json_encode($state, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                if (!chmod($tmp, 0600) || file_put_contents($tmp, $json)!==strlen($json) || !rename($tmp, $path)) {
                    throw new RuntimeException('Commit failed');
                }
                $tmp = null;
            }
            return $result;
        } finally {
            if (is_string($tmp) && is_file($tmp)) unlink($tmp);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
