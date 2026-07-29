# Carmo Video Compressor

WordPress plugin that compresses videos directly on the server, using a fixed `ffmpeg` pipeline:

```
ffmpeg -i input.mp4 -an -vcodec libx264 -crf 28 -preset slow -pix_fmt yuv420p output.mp4
```

An admin page under **Tools → Video Compressor** lets you drag-and-drop (or choose) a video, start the compression, track upload and compression progress, and then download or delete the result in a table.

## Features

- Drag-and-drop or browse to select the original video.
- Asynchronous background compression (doesn't block the PHP request or hit `max_execution_time`).
- Upload and compression progress bar (via `ffprobe`/`ffmpeg -progress`).
- Automatic CPU throttling: `nice -n 19` + `-threads` limited to half the server's cores (when available), without changing the requested encoding parameters.
- The original file is never kept — it's deleted as soon as compression finishes (whether it succeeds or fails).
- Table of compressed videos with download and delete actions.
- One job at a time (no queue for multiple simultaneous uploads).
- Access restricted to administrators (`manage_options`).

## Requirements

- WordPress with REST API support (default).
- `ffmpeg` and `ffprobe` installed on the server and accessible to the PHP process.
- Node.js + npm only for developing/building the assets (not required on the production server — `build/` is already versioned).

## Installing the plugin

1. Copy (or symlink) this folder into `wp-content/plugins/carmo-video-compressor`.
2. Activate the plugin in **Plugins**.
3. Go to **Tools → Video Compressor**.

## Installing ffmpeg on an Ubuntu VPS

`ffmpeg` must be installed and accessible to PHP (it's not enough for it to be installed only on your own computer — in local environments like Local by WP Engine, PHP may run isolated from the rest of the system).

### 1. Install via apt (simplest)

```bash
sudo apt update
sudo apt install -y ffmpeg
```

Confirm the installation and version:

```bash
ffmpeg -version
ffprobe -version
```

Ubuntu's `apt` usually ships a slightly older version of ffmpeg, but it's enough for this pipeline (`libx264` + `yuv420p` are supported by any recent build).

### 2. Confirm that PHP can find the binary

PHP-FPM/Apache runs with its own `PATH`, which may not be the same as your SSH user's. Confirm the absolute path:

```bash
which ffmpeg
which ffprobe
```

This usually returns `/usr/bin/ffmpeg` and `/usr/bin/ffprobe` on an apt install. The plugin already tries to resolve the binaries automatically (`PATH`, then common locations like `/usr/bin`, `/usr/local/bin`, `/opt/homebrew/bin`), but if your server has an atypical setup (e.g. `open_basedir`, PHP-FPM with a very restricted `PATH`, or ffmpeg installed in an unusual path), define the paths explicitly in `wp-config.php`:

```php
define('CVC_FFMPEG_BIN', '/usr/bin/ffmpeg');
define('CVC_FFPROBE_BIN', '/usr/bin/ffprobe');
define('CVC_NICE_BIN', '/usr/bin/nice');
```

### 3. Confirm that `shell_exec` isn't blocked

Some hosts disable shell execution functions for security reasons. Confirm that `shell_exec` isn't in the `disable_functions` list in `php.ini`:

```bash
php -i | grep disable_functions
```

If `shell_exec` shows up in that list, the plugin won't be able to run ffmpeg — it needs to be removed from the list (in `php.ini`, or in the site's PHP-FPM pool configuration) and PHP-FPM restarted.

### 4. (Optional) Build ffmpeg with more optimizations

For most cases, the `apt` package is enough. If you need a newer/more optimized version, the official static builds are an alternative that doesn't require compiling:

```bash
cd /opt
sudo curl -LO https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-amd64-static.tar.xz
sudo tar xf ffmpeg-release-amd64-static.tar.xz
sudo ln -s /opt/ffmpeg-*-amd64-static/ffmpeg /usr/local/bin/ffmpeg
sudo ln -s /opt/ffmpeg-*-amd64-static/ffprobe /usr/local/bin/ffprobe
```

Then define `CVC_FFMPEG_BIN`/`CVC_FFPROBE_BIN` in `wp-config.php` pointing to `/usr/local/bin/ffmpeg` and `/usr/local/bin/ffprobe`, if PHP doesn't find them automatically.

## Increasing the upload size limit (OpenLiteSpeed)

Video files are usually much bigger than the default upload limits. If the browser shows `413 Payload Too Large` (or the network tab shows a `413` response) when starting a compression, the request is being rejected by the web server itself, before it ever reaches WordPress/PHP — no plugin setting can work around this, it has to be raised at the server level.

### 1. Raise the limit in OpenLiteSpeed

1. Open the WebAdmin Console (usually `https://your-server-ip:7080`).
2. Go to **Configuration → Server → Tuning** and check **Max Request Body Size (bytes)**. This is the hard ceiling for the whole server.
3. Go to **Virtual Hosts → (your site) → General**, and set **Max Request Body Size (bytes)** to the size you want to allow (e.g. `2147483648` for 2 GB). This value cannot exceed the server-level ceiling from step 2.
4. If you manage the server through CyberPanel, the equivalent option is usually under **Websites → List Websites → Manage → vHost Conf**, editing the same `maxReqBodySize` directive directly.
5. Save and restart/graceful-restart OpenLiteSpeed for the change to apply:
   ```bash
   sudo systemctl restart lsws
   ```

### 2. Raise the PHP limits too

OpenLiteSpeed still runs PHP through `lsphp`, which has its own `upload_max_filesize` and `post_max_size` limits. Find the `php.ini` used by `lsphp` (commonly under `/usr/local/lsws/lsphpXX/etc/php.ini`) and set:

```ini
upload_max_filesize = 2048M
post_max_size = 2048M
```

Then restart `lsws` again so `lsphp` picks up the new `php.ini`. If only the OpenLiteSpeed limit is raised and the PHP one is left low, uploads will still fail, just with a different error instead of `413`.

## Development

```bash
npm install
npm run start   # watch mode
npm run build   # production build (generates build/index.js, build/index.css, build/index.asset.php)
```

Helper scripts:

- `npm run symlink` — creates a symlink of this folder inside `wp-content/plugins` of a local WordPress site.
- `npm run updateGIT` — interactive commit + push.
- `npm run plugin-zip` — generates a distributable plugin `.zip`.

## License

GPL-2.0-or-later
