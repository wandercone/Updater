<?php

declare(strict_types=1);

namespace UnraidUpdater;

final class Settings
{
    private const CONFIG_DIR = '/boot/config/plugins/updater';
    private const STATE_FILE = '/state.json';

    /** @var array<string,mixed> */
    private const DEFAULTS = [
        'enabled'             => false,
        'auto_mode'           => 'check_only', // check_only | stage | install
        'auto_reboot'         => false,
        'check_frequency'     => 'daily',      // daily | weekly | monthly | custom
        'check_time'          => '02:00',
        'check_day'           => 0,            // 0=Sunday ... 6=Saturday
        'check_cron'          => '0 2 * * *',
        'install_frequency'   => 'weekly',     // daily | weekly | custom
        'install_window_day'  => 0,
        'install_window_time' => '03:00',
        'install_cron'        => '0 3 * * 0',
    ];

    /** @var array<string,mixed>|null */
    private static ?array $cache = null;

    /** @return array<string,mixed> */
    public static function load(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        self::$cache = self::loadSettings();
        return self::$cache;
    }

    /** @param array<string,mixed> $settings */
    public static function save(array $settings): void
    {
        self::ensureDir();

        $state             = self::loadFullState();
        $state['settings'] = array_merge(self::loadSettings(), $settings);
        self::$cache       = $state['settings'];

        $encoded = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('Failed to encode updater state.');
        }

        $stateFile = self::statePath();
        $tmp       = $stateFile . '.tmp';
        if (file_put_contents($tmp, $encoded) === false) {
            throw new \RuntimeException('Failed to write updater state.');
        }
        rename($tmp, $stateFile);

        self::writeCronFile($state['settings']);
        self::installCron();
    }

    /** @param array<string,mixed> $updates */
    public static function updateState(array $updates): void
    {
        self::ensureDir();

        $state = self::loadFullState();
        foreach ($updates as $k => $v) {
            $state[$k] = $v;
        }

        $encoded = json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('Failed to encode updater state.');
        }

        $stateFile = self::statePath();
        $tmp       = $stateFile . '.tmp';
        if (file_put_contents($tmp, $encoded) === false) {
            throw new \RuntimeException('Failed to write updater state.');
        }
        rename($tmp, $stateFile);

        self::$cache = null;
    }

    /** @return array<string,mixed> */
    public static function fullState(): array
    {
        return self::loadFullState();
    }

    public static function installedVersion(): string
    {
        $env = getenv('UPDATER_INSTALLED_VERSION');
        if (is_string($env) && $env !== '') {
            return $env;
        }

        $version = '';

        if (is_file('/var/local/emhttp/var.ini')) {
            $var = @parse_ini_file('/var/local/emhttp/var.ini');
            if (is_array($var) && is_string($var['version'] ?? null)) {
                $version = trim($var['version']);
            }
        }

        if ($version === '' && is_file('/etc/unraid-version')) {
            $var = @parse_ini_file('/etc/unraid-version');
            if (is_array($var) && is_string($var['version'] ?? null)) {
                $version = trim($var['version']);
            }
        }

        return $version;
    }

    public static function ensureDir(): void
    {
        $dir = self::configDir();
        if ( ! is_dir($dir)) {
            @mkdir($dir, 0o755, true);
        }
    }

    public static function installCron(): void
    {
        self::writeSystemCron();
    }

    public static function removeCron(): void
    {
        $systemCron = self::systemCronFile();
        if (is_file($systemCron)) {
            @unlink($systemCron);
        }

        // Sync removal into the running cron state.
        @exec('/usr/local/sbin/update_cron >/dev/null 2>&1');
    }

    public static function cronFile(): string
    {
        return self::systemCronFile();
    }

    public static function stateFile(): string
    {
        return self::statePath();
    }

    public static function systemCronFile(): string
    {
        return '/boot/config/plugins/updater/updater.cron';
    }

    public static function phpBinary(): string
    {
        return '/usr/bin/php';
    }

    public static function validateCron(string $expression): bool
    {
        $fields = preg_split('/\s+/', trim($expression));
        if ( ! is_array($fields) || count($fields) !== 5) {
            return false;
        }

        $patterns = [
            '/^(\*|[\*\/\-,0-9]+)$/',
            '/^(\*|[\*\/\-,0-9]+)$/',
            '/^(\*|[\*\/\-,?LW0-9]+)$/i',
            '/^(\*|[\*\/\-,A-Z0-9]+)$/i',
            '/^(\*|[\*\/\-,A-Z0-9]+)$/i',
        ];

        foreach ($fields as $i => $field) {
            if ( ! preg_match($patterns[$i], (string)$field)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<mixed> $settings
     * @return array{check:string,install:string}
     */
    public static function buildCronExpression(array $settings): array
    {
        $parts     = self::parseTime(self::string($settings, 'check_time', '02:00'));
        $checkFreq = self::string($settings, 'check_frequency', 'daily');
        $checkDay  = max(0, min(6, self::int($settings, 'check_day', 0)));

        $checkCron = match ($checkFreq) {
            'daily'   => "{$parts['m']} {$parts['h']} * * *",
            'weekly'  => "{$parts['m']} {$parts['h']} * * {$checkDay}",
            'monthly' => "{$parts['m']} {$parts['h']} 1 * *",
            default   => self::string($settings, 'check_cron', '0 2 * * *'),
        };

        $installParts = self::parseTime(self::string($settings, 'install_window_time', '03:00'));
        $installFreq  = self::string($settings, 'install_frequency', 'weekly');
        $day          = max(0, min(6, self::int($settings, 'install_window_day', 0)));

        $installCron = match ($installFreq) {
            'daily'  => "{$installParts['m']} {$installParts['h']} * * *",
            'weekly' => "{$installParts['m']} {$installParts['h']} * * {$day}",
            default  => self::string($settings, 'install_cron', '0 3 * * 0'),
        };

        return ['check' => $checkCron, 'install' => $installCron];
    }

    /** @param array<mixed> $array */
    public static function string(array $array, string $key, string $default): string
    {
        $value = $array[$key] ?? $default;
        return is_string($value) ? $value : (is_int($value) ? (string)$value : $default);
    }

    /** @param array<mixed> $array */
    public static function int(array $array, string $key, int $default): int
    {
        $value = $array[$key] ?? $default;
        return is_int($value) ? $value : (is_numeric($value) ? (int)$value : $default);
    }

    public static function configDir(): string
    {
        $env = getenv('UPDATER_CONFIG_DIR');
        if (is_string($env) && $env !== '') {
            return $env;
        }
        return self::CONFIG_DIR;
    }

    private static function statePath(): string
    {
        return self::configDir() . self::STATE_FILE;
    }

    /** @return array<string,mixed> */
    private static function loadSettings(): array
    {
        $saved = self::toArray(self::readRaw()['settings'] ?? []);
        return array_merge(self::DEFAULTS, $saved);
    }

    /** @return array<string,mixed> */
    private static function loadFullState(): array
    {
        $raw             = self::readRaw();
        $saved           = self::toArray($raw['settings'] ?? []);
        $raw['settings'] = array_merge(self::DEFAULTS, $saved);
        return $raw;
    }

    /** @return array<string,mixed> */
    private static function readRaw(): array
    {
        $stateFile = self::statePath();
        if ( ! is_file($stateFile)) {
            return [];
        }

        $contents = file_get_contents($stateFile);
        if ($contents === false || $contents === '') {
            return [];
        }

        $decoded = json_decode($contents, true);
        if ( ! is_array($decoded)) {
            return [];
        }

        return self::toArray($decoded);
    }

    /** @param array<string,mixed> $settings */
    /** @param array<mixed> $settings */
    private static function writeCronFile(array $settings): void
    {
        self::ensureDir();

        $enabled  = (bool)($settings['enabled'] ?? false);
        $autoMode = self::string($settings, 'auto_mode', 'check_only');
        $cronExpr = self::buildCronExpression($settings);

        $php           = self::phpBinary();
        $checkScript   = '/usr/local/emhttp/plugins/updater/include/cron.php';
        $installScript = '/usr/local/emhttp/plugins/updater/include/cron.php install';

        $lines = [
            '# Generated Updater plugin scheduled tasks',
        ];

        if ($enabled) {
            // Plugin-owned .cron files are synced by /usr/local/sbin/update_cron
            $lines[] = "{$cronExpr['check']} {$php} {$checkScript} check >/dev/null 2>&1";

            if ($autoMode === 'install') {
                $lines[] = "{$cronExpr['install']} {$php} {$installScript} >/dev/null 2>&1";
            }
        }

        $content  = implode("\n", $lines) . "\n\n";
        $cronFile = self::systemCronFile();
        $tmp      = $cronFile . '.tmp';
        file_put_contents($tmp, $content);
        rename($tmp, $cronFile);
        @chmod($cronFile, 0o644);

        @exec('/usr/local/sbin/update_cron >/dev/null 2>&1');
    }

    private static function writeSystemCron(): void
    {
        $state = self::loadFullState();
        self::writeCronFile(self::toArray($state['settings'] ?? []));
    }

    /** @return array{h:string,m:string} */
    private static function parseTime(string $time): array
    {
        $t = trim($time);

        if ( ! preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $t, $m)) {
            return ['h' => '2', 'm' => '00'];
        }

        $h   = (int)$m[1];
        $min = (int)$m[2];
        if ($h < 0 || $h > 23 || $min < 0 || $min > 59) {
            return ['h' => '2', 'm' => '00'];
        }

        return ['h' => (string)$h, 'm' => sprintf('%02d', $min)];
    }

    /** @return array<string,mixed> */
    private static function toArray(mixed $value): array
    {
        if ( ! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $k => $v) {
            $result[(string)$k] = $v;
        }
        return $result;
    }
}
