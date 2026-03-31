# Unraid-Sidegrade

An unofficial Unraid OS updater. Paste a download URL, verify the checksum, reboot. Bypasses the official update flow and installs any OS version directly to the boot device.

## Features

- Install any Unraid OS version from a direct download URL
- MD5 checksum verification before touching the boot device
- Automatic backup of current boot files to `/boot/previous`

## Requirements

- Unraid 7.0.0 or later

## Installation

**Via Plugin URL**

1. In the Unraid UI go to **Plugins > Install Plugin**
2. Paste the `.plg` URL from the [latest release](https://raw.githubusercontent.com/wandercone/Updater/refs/heads/main/plugin/updater.plg)
3. Click **Install**

After installation the plugin is available at **System Information > Unraid Updater**.

## Usage

1. Visit the [Unraid version history](https://docs.unraid.net/unraid-os/download_list/) and copy the download URL and MD5 checksum for the version you want
2. Paste the URL and checksum into the plugin
3. Click Download & Install
4. Reboot

## How It Works

The install process follows the [official manual upgrade steps](https://docs.unraid.net/unraid-os/system-administration/maintain-and-update/upgrading-unraid/)