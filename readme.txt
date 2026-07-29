=== Carmo Video Compressor ===
Contributors: carmopereira
Tags: video, compression, ffmpeg, media, upload
Requires at least: 6.2
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 1.0.8
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Compress videos on the server with a fixed ffmpeg pipeline, using a drag-and-drop admin tool under Tools.

== Description ==

Carmo Video Compressor is a WordPress plugin that compresses videos directly on the server, using a fixed `ffmpeg` pipeline:

`ffmpeg -i input.mp4 -an -vcodec libx264 -crf 28 -preset slow -pix_fmt yuv420p output.mp4`

An admin page under Tools > Video Compressor lets you drag-and-drop (or choose) a video, start the compression, track upload and compression progress, and then download or delete the result in a table.

= Features =

* Drag-and-drop or browse to select the original video.
* Asynchronous background compression (doesn't block the PHP request or hit `max_execution_time`).
* Upload and compression progress bar (via `ffprobe`/`ffmpeg -progress`).
* Automatic CPU throttling: `nice -n 19` plus `-threads` limited to half the server's cores (when available), without changing the requested encoding parameters.
* The original file is never kept, it is deleted as soon as compression finishes (whether it succeeds or fails).
* Table of compressed videos with download and delete actions.
* One job at a time (no queue for multiple simultaneous uploads).
* Access restricted to administrators (`manage_options`).

= Requirements =

* WordPress with REST API support (default).
* `ffmpeg` and `ffprobe` installed on the server and accessible to the PHP process.
* Node.js and npm are only needed for developing or building the assets, not on the production server, since `build/` is already versioned.

== Installation ==

1. Copy (or symlink) this folder into `wp-content/plugins/carmo-video-compressor`.
2. Activate the plugin from the Plugins screen.
3. Go to Tools > Video Compressor.
4. Make sure `ffmpeg` and `ffprobe` are installed on the server and accessible to PHP. See the plugin's README.md for detailed server setup instructions.

== Frequently Asked Questions ==

= Does this plugin work without ffmpeg installed on the server? =

No. `ffmpeg` and `ffprobe` must be installed on the server and accessible to the PHP process. See README.md for installation notes, including how to point the plugin at custom binary paths with the `CVC_FFMPEG_BIN`, `CVC_FFPROBE_BIN`, and `CVC_NICE_BIN` constants.

= Can multiple videos be compressed at the same time? =

No, the plugin processes one job at a time by design.

= I get a "413 Payload Too Large" error when uploading a video =

This is a limit enforced by the web server itself (not by WordPress or this plugin), so it has to be raised at the server level. On OpenLiteSpeed, raise "Max Request Body Size" at both the server and virtual host level, and raise `upload_max_filesize`/`post_max_size` in the `lsphp` `php.ini`. See README.md for step-by-step instructions.

== Changelog ==

= 1.0.7 =
* Surface real upload errors to administrators instead of a generic message.
* Fix a fatal error when WP_Filesystem direct access is unavailable.
* Document how to raise upload size limits on OpenLiteSpeed.

= 1.0.4 =
* Use cached, prepared queries for the custom jobs table.
* Replace direct filesystem calls with WordPress Filesystem API equivalents where possible.

= 1.0.3 =
* Initial public version.

== Upgrade Notice ==

= 1.0.4 =
Internal hardening release, no action required.
