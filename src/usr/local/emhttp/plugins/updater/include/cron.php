<?php

declare(strict_types=1);
require_once __DIR__ . '/Versions.php';
require_once __DIR__ . '/Settings.php';

use UnraidUpdater\Settings;
use UnraidUpdater\Versions;

const RELEASES_URL        = 'https://releases.unraid.net/json';
const ALLOWED_HOST_SUFFIX = '.unraid.net';
const LOCK_FILE           = '/tmp/updater-cron.lock';

function stagingDir(): string
{
    return Settings::configDir() . '/staged';
}

function logFile(): string
{
    return Settings::configDir() . '/cron.log';
}

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$command = $argv[1] ?? '';
if (!in_array($command, ['check', 'install', '--install-cron'], true)) {
    fwrite(STDERR, "Usage: cron.php check|install|--install-cron\n");
    exit(1);
}

if ($command === '--install-cron') {
    Settings::installCron();
    exit(0);
}

$settings = Settings::load();
if (($settings['enabled'] ?? false) === false) {
    logMessage('Auto-updater is disabled; exiting.');
    exit(0);
}

$lock = acquireLock();
if (!$lock) {
    logMessage('Another updater process is already running; exiting.');
    exit(0);
}

try {
    if ($command === 'check') {
        runCheck($settings);
    } else {
        runInstall($settings);
    }
} finally {
    releaseLock($lock);
}

/** @param array<string,mixed> $settings */
function runCheck(array $settings): void
{
    $installed = Settings::installedVersion();
    if ($installed === '') {
        logMessage('ERROR: Could not determine installed Unraid version.');
        Settings::updateState(['last_check' => date('c'), 'last_check_error' => 'Could not determine installed version']);
        exit(1);
    }

    $autoMode = Settings::string($settings, 'auto_mode', 'check_only');

    logMessage("Checking for updates. Installed: {$installed}, mode: {$autoMode}");

    $json = fetchReleases();
    if ($json === null) {
        logMessage('ERROR: Failed to fetch releases JSON.');
        Settings::updateState(['last_check' => date('c'), 'last_check_error' => 'Failed to fetch releases']);
        exit(1);
    }

    $releases = json_decode($json, true);
    if (!is_array($releases)) {
        logMessage('ERROR: Releases JSON is not an array.');
        Settings::updateState(['last_check' => date('c'), 'last_check_error' => 'Invalid releases JSON']);
        exit(1);
    }

    $match = Versions::findNewestMatchingPatch($releases, $installed);

    if ($match === null) {
        logMessage('No new patch release available on branch ' . branchLabel($installed) . '.');
        Settings::updateState([
            'last_check'       => date('c'),
            'last_check_error' => null,
            'available'        => null,
        ]);
        exit(0);
    }

    $version = Settings::string($match, 'version', '');
    $url     = Settings::string($match, 'url', '');
    $md5     = Settings::string($match, 'md5', '');
    $sha256  = Settings::string($match, 'sha256', '');

    logMessage("Found update: {$version} at {$url}");

    $stateUpdate = [
        'last_check'       => date('c'),
        'last_check_error' => null,
        'available'        => [
            'version'  => $version,
            'url'      => $url,
            'md5'      => $md5,
            'sha256'   => $sha256,
        ],
    ];

    if ($autoMode === 'check_only') {
        Settings::updateState($stateUpdate);
        logMessage('Notify-only mode: update recorded, not downloaded.');
        exit(0);
    }

    $stageResult = stageUpdate($version, $url, $md5, $sha256);
    if ($stageResult === null) {
        $stateUpdate['last_check_error'] = 'Download or verification failed';
        Settings::updateState($stateUpdate);
        exit(1);
    }

    $stateUpdate['staged'] = [
        'version'   => $version,
        'file'      => $stageResult,
        'md5'       => $md5,
        'sha256'    => $sha256,
        'staged_at' => date('c'),
    ];

    Settings::updateState($stateUpdate);

    if ($autoMode === 'install') {
        logMessage('Update staged; install will run at the configured window.');
    } else {
        logMessage('Update staged; user must apply from UI or install window.');
    }

    exit(0);
}

/** @param array<string,mixed> $settings */
function runInstall(array $settings): void
{
    $state = Settings::fullState();
    $staged = $state['staged'] ?? null;

    if (!is_array($staged)) {
        logMessage('No staged update available; nothing to install.');
        exit(0);
    }

    $version = Settings::string($staged, 'version', '');
    $file    = Settings::string($staged, 'file', '');

    if ($version === '' || $file === '' || !is_file($file)) {
        logMessage('Staged update record is invalid or file is missing.');
        Settings::updateState(['staged' => null, 'last_install_error' => 'Staged file missing']);
        exit(1);
    }

    $installed = Settings::installedVersion();
    if ($installed !== '' && !Versions::isNewer($version, $installed)) {
        logMessage("Installed version {$installed} is already up to date; clearing staged {$version}.");
        Settings::updateState(['staged' => null]);
        exit(0);
    }

    logMessage("Installing staged update {$version} from {$file}");

    $rc = installStagedFile($file, $version);
    if ($rc !== 0) {
        logMessage("ERROR: Install script exited with code {$rc}.");
        Settings::updateState(['last_install_error' => "Install script exited with code {$rc}"]);
        exit(1);
    }

    $autoReboot = (bool)($settings['auto_reboot'] ?? false);

    Settings::updateState([
        'staged'         => null,
        'available'      => null,
        'last_install'   => ['version' => $version, 'installed_at' => date('c')],
        'last_install_error' => null,
        'pending_reboot' => $autoReboot,
    ]);

    logMessage("Update {$version} installed successfully.");

    if ($autoReboot) {
        $rebootRc = stopArrayAndReboot();
        if ($rebootRc !== 0) {
            logMessage('ERROR: Failed to schedule array stop/reboot; please reboot manually.');
            Settings::updateState(['pending_reboot' => false, 'last_install_error' => 'Auto-reboot scheduling failed']);
            exit(1);
        }
        logMessage('Array stop/reboot scheduled. The server will reboot shortly.');
    } else {
        logMessage('Reboot manually to apply the update.');
    }

    exit(0);
}

function stageUpdate(string $version, string $url, string $md5, string $sha256): ?string
{
    if (!preg_match('#^https://#i', $url)) {
        logMessage('ERROR: Release URL is not HTTPS.');
        return null;
    }

    $host = parse_url($url, PHP_URL_HOST);
    if (!is_string($host) || $host === '' || !str_ends_with($host, ALLOWED_HOST_SUFFIX)) {
        logMessage("ERROR: Release URL host '{$host}' is not an authorized Unraid download server.");
        return null;
    }

    $staging = stagingDir();
    @mkdir($staging, 0o755, true);
    $stageFile = $staging . '/unraid-' . preg_replace('/[^A-Za-z0-9.\-_]/', '', $version) . '.zip';

    if (is_file($stageFile) && isVerified($stageFile, $md5, $sha256)) {
        logMessage("Using already-staged file {$stageFile}.");
        return $stageFile;
    }

    $tmpFile = $stageFile . '.tmp';
    @unlink($tmpFile);

    logMessage("Downloading to {$tmpFile}");
    $cmd = sprintf('wget -q -O %s %s', escapeshellarg($tmpFile), escapeshellarg($url));
    exec($cmd, $out, $rc);
    if ($rc !== 0 || !is_file($tmpFile) || filesize($tmpFile) === 0) {
        logMessage('ERROR: Download failed or file is empty.');
        @unlink($tmpFile);
        return null;
    }

    if (!isVerified($tmpFile, $md5, $sha256)) {
        logMessage('ERROR: Checksum verification failed.');
        @unlink($tmpFile);
        return null;
    }

    rename($tmpFile, $stageFile);
    logMessage('Download and verification complete.');
    return $stageFile;
}

function isVerified(string $file, string $md5, string $sha256): bool
{
    if ($sha256 !== '' && is_executable('/usr/bin/sha256sum')) {
        $actual = trim((string)shell_exec('sha256sum ' . escapeshellarg($file) . ' 2>/dev/null | awk \'{print $1}\''));
        if (strcasecmp($actual, $sha256) !== 0) {
            logMessage("SHA256 mismatch: expected {$sha256}, got {$actual}");
            return false;
        }
        return true;
    }

    if ($md5 !== '') {
        $actual = trim((string)shell_exec('md5sum ' . escapeshellarg($file) . ' 2>/dev/null | awk \'{print $1}\''));
        if (strcasecmp($actual, $md5) !== 0) {
            logMessage("MD5 mismatch: expected {$md5}, got {$actual}");
            return false;
        }
        return true;
    }

    logMessage('WARNING: No checksum available; skipping verification.');
    logMessage('ERROR: No checksum available; rejecting file.');
    return false;
}

function installStagedFile(string $file, string $version): int
{
    $bootDir = getenv('UPDATER_BOOT_DIR');
    $bootDir = is_string($bootDir) && $bootDir !== '' ? $bootDir : '/boot';

    $script = <<<'BASH'
#!/bin/bash
set -o pipefail
ZIP="$1"
WORKDIR="/tmp/updater-install"
BOOT_DIR="${UPDATER_BOOT_DIR:-/boot}"
PREVIOUS_DIR="$BOOT_DIR/previous"

rm -rf "$WORKDIR"
mkdir -p "$WORKDIR"

echo "=== Unraid OS Auto-Update ==="
date
echo "Source: $ZIP"
echo "Target boot directory: $BOOT_DIR"
echo ""

echo "[1/4] Extracting package"
unzip -o -d "$WORKDIR/unraid_install" "$ZIP"
if [[ $? -ne 0 ]] || [[ ! -s "$WORKDIR/unraid_install/bzroot" ]]; then
    echo "ERROR: Extraction failed or bzroot not found."
    echo "FAILED"
    exit 1
fi
echo ""

echo "[2/4] Backing up current boot files to $PREVIOUS_DIR"
mkdir -p "$PREVIOUS_DIR"
for f in "$BOOT_DIR"/bz*; do
    [[ -f "$f" ]] && mv -f "$f" "$PREVIOUS_DIR/"
done
[[ -f "$BOOT_DIR/changes.txt" ]] && mv -f "$BOOT_DIR/changes.txt" "$PREVIOUS_DIR/"
echo ""

echo "[3/4] Installing new boot files"
for f in "$WORKDIR/unraid_install/bz"*; do
    [[ -f "$f" ]] && cp -f "$f" "$BOOT_DIR/"
done
[[ -f "$WORKDIR/unraid_install/changes.txt" ]] && cp -f "$WORKDIR/unraid_install/changes.txt" "$BOOT_DIR/"
echo ""

echo "[4/4] Syncing boot device"
sync -f "$BOOT_DIR"
echo ""

echo "--- Update Complete ---"
echo "Reboot to apply the new Unraid OS version."
echo "DONE"
BASH;

    $scriptFile = '/tmp/updater-auto-install.sh';
    file_put_contents($scriptFile, $script);
    chmod($scriptFile, 0o700);

    $logFile = '/tmp/updater-auto-install.log';
    $envExport = escapeshellarg($bootDir);
    $cmd = sprintf(
        'UPDATER_BOOT_DIR=%s bash %s %s > %s 2>&1',
        $envExport,
        escapeshellarg($scriptFile),
        escapeshellarg($file),
        escapeshellarg($logFile)
    );
    exec($cmd, $out, $rc);

    $logTail = is_file($logFile) ? (string)file_get_contents($logFile) : '';
    if ($logTail !== '') {
        logMessage($logTail);
    }

    return $rc;
}

function fetchReleases(): ?string
{
    $opts = [
        'http' => [
            'method'  => 'GET',
            'timeout' => 60,
            'header'  => "User-Agent: UnraidUpdater/1.0\r\nAccept: application/json\r\n",
        ],
    ];

    $ctx = stream_context_create($opts);
    $result = @file_get_contents(RELEASES_URL, false, $ctx);

    if ($result === false || $result === '') {
        return null;
    }

    return $result;
}

function logMessage(string $message): void
{
    Settings::ensureDir();
    $line = '[' . date('Y-m-d H:i:s') . '] ' . trim($message) . "\n";
    file_put_contents(logFile(), $line, FILE_APPEND | LOCK_EX);
}

function branchLabel(string $version): string
{
    $parts = Versions::parse($version);
    if ($parts === null) {
        return $version;
    }
    return "{$parts[0]}.{$parts[1]}";
}

function stopArrayAndReboot(): int
{
    $script = <<<'BASH'
#!/bin/bash
set -o pipefail

VAR_INI="/var/local/emhttp/var.ini"
MAX_WAIT=600

stop_array() {
    if [[ -x /usr/local/sbin/emcmd ]]; then
        /usr/local/sbin/emcmd 'cmdStop=Stop' &>/dev/null
        return 0
    fi
    return 1
}

wait_for_array_stop() {
    local waited=0
    while [[ $waited -lt $MAX_WAIT ]]; do
        if [[ -f "$VAR_INI" ]]; then
            STATE=$(awk -F'=' '/^mdState=/ {gsub(/[" ]/, "", $2); print $2}' "$VAR_INI")
            if [[ "$STATE" == "STOPPED" ]]; then
                echo "Array is stopped."
                return 0
            fi
        fi
        sleep 5
        waited=$((waited + 5))
        echo "Waiting for array to stop... ($waited seconds)"
    done
    return 1
}

echo "Stopping array..."
if ! stop_array; then
    echo "ERROR: Could not initiate array stop."
    exit 1
fi

if ! wait_for_array_stop; then
    echo "WARNING: Array did not report STOPPED within ${MAX_WAIT}s; proceeding with reboot."
fi

echo "Rebooting server..."
sync
/sbin/reboot
BASH;

    $scriptFile = '/tmp/updater-reboot.sh';
    file_put_contents($scriptFile, $script);
    chmod($scriptFile, 0o700);

    $logFile = '/tmp/updater-reboot.log';
    $cmd = sprintf('nohup bash %s > %s 2>&1 &', escapeshellarg($scriptFile), escapeshellarg($logFile));
    exec($cmd, $out, $rc);

    return $rc;
}

/** @return resource|false */
function acquireLock(): mixed
{
    $fp = @fopen(LOCK_FILE, 'c');
    if (!is_resource($fp)) {
        return false;
    }
    if (!@flock($fp, LOCK_EX | LOCK_NB)) {
        fclose($fp);
        return false;
    }
    return $fp;
}

/** @param resource|false $lock */
function releaseLock(mixed $lock): void
{
    if (is_resource($lock)) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    @unlink(LOCK_FILE);
}
