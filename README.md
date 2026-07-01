# Updater

An unofficial Unraid OS updater. Install any OS version directly to the boot device, either manually or automatically within your current `major.minor` branch.

## Features

- Manual updates from any direct download URL
- Automatic patch updates on your current `major.minor` branch
- MD5 / SHA256 checksum verification
- Automatic backup of current boot files to `/boot/previous`

## Requirements

- Unraid 7.0.0 or later

## Installation

**Via Plugin URL**

1. In the Unraid UI go to **Plugins > Install Plugin**
2. Paste the `.plg` URL from the [latest release](https://raw.githubusercontent.com/wandercone/Updater/refs/heads/main/plugin/updater.plg)
3. Click **Install**

After installation the plugin is available at **Tools > Updater**.

## Usage

### Manual update

1. Visit the [Unraid version history](https://docs.unraid.net/unraid-os/download_list/) and copy the download URL and MD5 checksum.
2. Paste the URL and checksum into the **Manual Update** section.
3. Click **Download & Install**.
4. Reboot.

### Automatic updates
1. Enable Auto-Updates 
    - **Auto-Update Mode**:
      - Check and notify only; show available updates, do not download.
      - Stage updates automatically; download and verify the zip, then hold it for manual install.
      - Install updates automatically; download, verify, and write the update to `/boot` at the configured window.
    - **After Automatic Install**:
      - Notify and wait for manual reboot (default).
      - Stop the array and reboot automatically; stop the array with `emcmd 'cmdStop=Stop'`, then reboot.
    - **Check Frequency**: how often to query `releases.unraid.net/json`.
    - **Install Window**: when unattended installs are allowed.
    - **Pre-Release Updates**: include RC/beta releases.
2. Click **Save Settings**.
3. Click **Check Now** to test the flow.

Only patch releases on your current `major.minor` branch are handled automatically. Cross-minor or cross-major updates must still be requested manually.

## How It Works

The install process follows the [official manual upgrade steps](https://docs.unraid.net/unraid-os/system-administration/maintain-and-update/upgrading-unraid/).

State and staged downloads are kept in `/boot/config/plugins/updater/`.
