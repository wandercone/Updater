<?php

declare(strict_types=1);

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

function postStr(string $key, string $default = ''): string
{
    $v = $_POST[$key] ?? null;
    return is_string($v) ? $v : $default;
}

function jsonResponse(bool $success, string $message): never
{
    echo json_encode(['success' => $success, 'message' => $message]);
    exit;
}

const LOG_FILE    = '/tmp/updater.log';
const SCRIPT_FILE = '/tmp/updater-install.sh';
const STAGED_DIR  = '/boot/config/plugins/updater/staged';

const ALLOWED_HOST_SUFFIX = '.unraid.net';

require_once __DIR__ . '/Versions.php';
require_once __DIR__ . '/Settings.php';

use UnraidUpdater\Settings;
use UnraidUpdater\Versions;

$action = postStr('action');

switch ($action) {
    case 'get_settings':
        try {
            $state = Settings::fullState();
            $state['installed_version'] = Settings::installedVersion();
            echo json_encode(['success' => true, 'state' => $state]);
        } catch (\Throwable $e) {
            jsonResponse(false, $e->getMessage());
        }
        exit;

    case 'save_settings':
        try {
            $raw = json_decode(postStr('settings'), true);
            if (!is_array($raw)) {
                jsonResponse(false, 'Invalid settings payload.');
            }

            $validated = validateSettings($raw);
            Settings::save($validated);
            echo json_encode(['success' => true, 'message' => 'Settings saved.']);
        } catch (\Throwable $e) {
            jsonResponse(false, $e->getMessage());
        }
        exit;

    case 'apply_staged':
        try {
            $state = Settings::fullState();
            $staged = $state['staged'] ?? null;
            if (!is_array($staged) || !is_string($staged['file'] ?? null) || !is_file($staged['file'])) {
                jsonResponse(false, 'No staged update is available.');
            }

            $file = $staged['file'];

            file_put_contents(LOG_FILE, '');
            file_put_contents(SCRIPT_FILE, buildManualInstallScript($file));
            chmod(SCRIPT_FILE, 0o700);

            $scriptEsc = escapeshellarg(SCRIPT_FILE);
            $fileEsc   = escapeshellarg($file);
            $logEsc    = escapeshellarg(LOG_FILE);

            exec("nohup bash {$scriptEsc} {$fileEsc} > {$logEsc} 2>&1 & echo $!", $pidOut);
            $pid = (int)($pidOut[0] ?? 0);

            if ($pid === 0) {
                jsonResponse(false, 'Failed to start install process.');
            }

            jsonResponse(true, "Install started (PID: {$pid}).");
        } catch (\Throwable $e) {
            jsonResponse(false, $e->getMessage());
        }

    case 'check_now':
        try {
            $php = Settings::phpBinary();
            $script = escapeshellarg('/usr/local/emhttp/plugins/updater/include/cron.php');
            $out = [];
            $rc = 0;
            exec("{$php} {$script} check 2>&1", $out, $rc);
            $state = Settings::fullState();
            $output = implode("\n", array_filter($out, static fn (string $line): bool => $line !== ''));

            if ($rc !== 0) {
                $error = $output !== '' ? $output : 'Check script exited with code ' . $rc;
                echo json_encode([
                    'success' => false,
                    'message' => $error,
                    'state'   => $state,
                ]);
                exit;
            }

            echo json_encode([
                'success' => true,
                'message' => $output !== '' ? $output : 'Check complete.',
                'state'   => $state,
            ]);
        } catch (\Throwable $e) {
            jsonResponse(false, $e->getMessage());
        }
        exit;
    case 'start_update':
        $url = postStr('url');

        if ( ! preg_match('#^https://#i', $url)) {
            jsonResponse(false, "URL must use HTTPS.");
        }
        $host = parse_url($url, PHP_URL_HOST);
        if ( ! is_string($host) || $host === '') {
            jsonResponse(false, "Invalid URL.");
        }
        if ( ! str_ends_with($host, ALLOWED_HOST_SUFFIX)) {
            jsonResponse(false, "URL host '{$host}' is not an authorized Unraid download server.");
        }

        $md5Raw = postStr('md5');
        $md5    = strtolower(preg_replace('/[^0-9a-fA-F]/', '', $md5Raw) ?? '');
        if ($md5Raw !== '' && strlen($md5) !== 32) {
            jsonResponse(false, "MD5 checksum must be exactly 32 hex characters.");
        }

        $installScript = <<<'BASH'
#!/bin/bash
# Unraid OS manual install script

URL="$1"
MD5="$2"
WORKDIR="/tmp/updater"

set -o pipefail
mkdir -p "$WORKDIR"

echo "=== Unraid OS Update ==="
date
echo "Target: $URL"
echo ""

# Download
echo "[1/5] Downloading OS package"
rm -f "$WORKDIR/unraid.zip"
wget -O "$WORKDIR/unraid.zip" "$URL"
DL_RC=$?
echo ""
if [[ $DL_RC -ne 0 ]] || [[ ! -s "$WORKDIR/unraid.zip" ]]; then
    echo "ERROR: Download failed (exit code $DL_RC) or file is empty."
    echo "FAILED"
    exit 1
fi
echo "Download complete."
echo ""

# Verify checksum
if [[ -n "$MD5" ]]; then
    echo "[2/5] Verifying MD5 checksum"
    ACTUAL=$(md5sum "$WORKDIR/unraid.zip" | awk '{print $1}')
    if [[ "${ACTUAL,,}" != "${MD5,,}" ]]; then
        echo "ERROR: Checksum mismatch!"
        echo "  Expected: $MD5"
        echo "  Actual:   $ACTUAL"
        echo "FAILED"
        exit 1
    fi
    echo "Checksum verified."
else
    echo "[2/5] No checksum provided — skipping verification."
fi
echo ""

# Extract
echo "[3/5] Extracting package"
rm -rf "$WORKDIR/unraid_install"
unzip -o -d "$WORKDIR/unraid_install" "$WORKDIR/unraid.zip"
UNZIP_RC=$?
if [[ $UNZIP_RC -ne 0 ]] || [[ ! -s "$WORKDIR/unraid_install/bzroot" ]]; then
    echo "ERROR: Extraction failed (exit code $UNZIP_RC) or bzroot not found."
    echo "FAILED"
    exit 1
fi
echo "Extraction complete."
echo ""

# Back up existing boot files
echo "[4/5] Backing up current boot files to /boot/previous"
[[ ! -d /boot/previous ]] && mkdir -p /boot/previous
for f in /boot/bz*; do
    if [[ -f "$f" ]]; then
        mv -f "$f" /boot/previous/
        echo "  -> previous/$(basename "$f")"
    fi
done
if [[ -f /boot/changes.txt ]]; then
    mv -f /boot/changes.txt /boot/previous/
    echo "  -> previous/changes.txt"
fi
echo ""

# Install new boot files
echo "[5/5] Installing new boot files to /boot"
for f in "$WORKDIR/unraid_install/bz"*; do
    if [[ -f "$f" ]]; then
        cp -f "$f" /boot/
        echo "  + $(basename "$f")"
    fi
done
if [[ -f "$WORKDIR/unraid_install/changes.txt" ]]; then
    cp -f "$WORKDIR/unraid_install/changes.txt" /boot/
fi
echo ""

echo "Syncing boot device..."
sync -f /boot
echo ""

echo "--- Update Complete ---"
echo "Reboot the system to apply the new Unraid OS version."
echo ""
echo "DONE"
BASH;

        file_put_contents(SCRIPT_FILE, $installScript);
        chmod(SCRIPT_FILE, 0700);
        file_put_contents(LOG_FILE, '');

        $scriptEsc = escapeshellarg(SCRIPT_FILE);
        $urlEsc    = escapeshellarg($url);
        $md5Esc    = escapeshellarg($md5);
        $logEsc    = escapeshellarg(LOG_FILE);

        exec("nohup bash {$scriptEsc} {$urlEsc} {$md5Esc} > {$logEsc} 2>&1 & echo $!", $pidOut);
        $pid = (int)($pidOut[0] ?? 0);

        if ($pid === 0) {
            jsonResponse(false, "Failed to start update process.");
        }

        jsonResponse(true, "Update started (PID: {$pid}).");

    case 'poll_log':
        $offset = max(0, (int)postStr('offset'));
        if ( ! file_exists(LOG_FILE)) {
            echo json_encode(['success' => true, 'content' => '', 'offset' => 0, 'done' => false, 'failed' => false]);
            exit;
        }
        $full = file_get_contents(LOG_FILE) ?: '';
        echo json_encode([
            'success' => true,
            'content' => substr($full, $offset),
            'offset'  => strlen($full),
            'done'    => str_contains($full, "\nDONE"),
            'failed'  => str_contains($full, "\nFAILED"),
        ]);
        exit;

    default:
        jsonResponse(false, "Unknown action.");
}

/**
 * @param array<mixed> $input
 * @return array<string,mixed>
 */
function validateSettings(array $input): array
{
    $validModes          = ['check_only', 'stage', 'install'];
    $validCheckFreqs     = ['daily', 'weekly', 'monthly', 'custom'];
    $validInstallFreqs   = ['daily', 'weekly', 'custom'];

    $autoMode = Settings::string($input, 'auto_mode', 'check_only');
    if (!in_array($autoMode, $validModes, true)) {
        $autoMode = 'check_only';
    }

    $checkFreq = Settings::string($input, 'check_frequency', 'daily');
    if (!in_array($checkFreq, $validCheckFreqs, true)) {
        $checkFreq = 'daily';
    }

    $installFreq = Settings::string($input, 'install_frequency', 'weekly');
    if (!in_array($installFreq, $validInstallFreqs, true)) {
        $installFreq = 'weekly';
    }

    $settings = [];
    $settings['enabled']             = filter_var($input['enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $settings['auto_mode']           = $autoMode;
    $settings['auto_reboot']         = filter_var($input['auto_reboot'] ?? false, FILTER_VALIDATE_BOOLEAN);
    $settings['check_frequency']     = $checkFreq;
    $settings['check_time']          = sanitizeTime(Settings::string($input, 'check_time', '02:00'));
    $settings['check_day']           = max(0, min(6, Settings::int($input, 'check_day', 0)));
    $settings['check_cron']          = sanitizeCron(Settings::string($input, 'check_cron', '0 2 * * *'));
    $settings['install_frequency']   = $installFreq;
    $settings['install_window_day']  = max(0, min(6, Settings::int($input, 'install_window_day', 0)));
    $settings['install_window_time'] = sanitizeTime(Settings::string($input, 'install_window_time', '03:00'));
    $settings['install_cron']        = sanitizeCron(Settings::string($input, 'install_cron', '0 3 * * 0'));

    return $settings;
}

function sanitizeTime(string $time): string
{
    $t = trim($time);

    if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $t, $m)) {
        return '02:00';
    }

    $h   = (int)$m[1];
    $min = (int)$m[2];
    if ($h < 0 || $h > 23 || $min < 0 || $min > 59) {
        return '02:00';
    }

    return sprintf('%02d:%02d', $h, $min);
}

function sanitizeCron(string $expression): string
{
    return \UnraidUpdater\Settings::validateCron($expression)
        ? trim($expression)
        : '0 2 * * *';
}

function buildManualInstallScript(string $zipFile): string
{
    return <<<'BASH'
#!/bin/bash
# Unraid OS install script — generated by updater plugin

ZIP="$1"
WORKDIR="/tmp/updater"
BOOT_DIR="${UPDATER_BOOT_DIR:-/boot}"
PREVIOUS_DIR="$BOOT_DIR/previous"

set -o pipefail
mkdir -p "$WORKDIR"

echo "=== Unraid OS Update ==="
date
echo "Source: $ZIP"
echo "Target boot directory: $BOOT_DIR"
echo ""

# Step 1: Extract
echo "[1/4] Extracting package"
rm -rf "$WORKDIR/unraid_install"
unzip -o -d "$WORKDIR/unraid_install" "$ZIP"
UNZIP_RC=$?
if [[ $UNZIP_RC -ne 0 ]] || [[ ! -s "$WORKDIR/unraid_install/bzroot" ]]; then
    echo "ERROR: Extraction failed (exit code $UNZIP_RC) or bzroot not found."
    echo "FAILED"
    exit 1
fi
echo "Extraction complete."
echo ""

# Step 2: Back up existing boot files
echo "[2/4] Backing up current boot files to $PREVIOUS_DIR"
[[ ! -d "$PREVIOUS_DIR" ]] && mkdir -p "$PREVIOUS_DIR"
for f in "$BOOT_DIR"/bz*; do
    if [[ -f "$f" ]]; then
        mv -f "$f" "$PREVIOUS_DIR/"
        echo "  -> previous/$(basename "$f")"
    fi
done
if [[ -f "$BOOT_DIR/changes.txt" ]]; then
    mv -f "$BOOT_DIR/changes.txt" "$PREVIOUS_DIR/"
    echo "  -> previous/changes.txt"
fi
echo ""

# Step 3: Install new boot files
echo "[3/4] Installing new boot files to $BOOT_DIR"
for f in "$WORKDIR/unraid_install/bz"*; do
    if [[ -f "$f" ]]; then
        cp -f "$f" "$BOOT_DIR/"
        echo "  + $(basename "$f")"
    fi
done
if [[ -f "$WORKDIR/unraid_install/changes.txt" ]]; then
    cp -f "$WORKDIR/unraid_install/changes.txt" "$BOOT_DIR/"
fi
echo ""

# Step 4: Sync
echo "[4/4] Syncing boot device"
sync -f "$BOOT_DIR"
echo ""

echo "--- Update Complete ---"
echo "Reboot the system to apply the new Unraid OS version."
echo ""
echo "DONE"
BASH;
}
