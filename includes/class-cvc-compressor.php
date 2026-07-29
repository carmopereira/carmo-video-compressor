<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class CVC_Compressor
{
    /** @var array<string, string> */
    private static array $resolved_binaries = [];

    /**
     * Probe a video's duration in seconds using ffprobe.
     * Returns null if ffprobe isn't available or the duration can't be determined.
     */
    public function probe_duration(string $input_path): ?float
    {
        $ffprobe = $this->resolve_binary('ffprobe', 'CVC_FFPROBE_BIN');
        if ($ffprobe === null) {
            return null;
        }

        $command = sprintf(
            '%s -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s 2>/dev/null',
            escapeshellarg($ffprobe),
            escapeshellarg($input_path)
        );

        $output   = (string) shell_exec($command);
        $duration = (float) trim($output);

        return $duration > 0 ? $duration : null;
    }

    /**
     * Launch ffmpeg as a detached background process using the user's fixed
     * command plus CPU-scheduling flags only (nice + thread cap). Returns the
     * PID reported by the shell (informational only, not used to detect
     * completion — see CVC_Rest_Controller::reconcile_job()), or null if
     * ffmpeg itself can't be located on the server.
     */
    public function start(string $input_path, string $output_path, string $progress_path, string $log_path, string $done_path): ?int
    {
        $ffmpeg = $this->resolve_binary('ffmpeg', 'CVC_FFMPEG_BIN');
        if ($ffmpeg === null) {
            file_put_contents($log_path, "ffmpeg não foi encontrado no servidor (nem no PATH, nem nos locais comuns). Define a constante CVC_FFMPEG_BIN no wp-config.php com o caminho absoluto do executável.");
            file_put_contents($done_path, '127');

            return null;
        }

        $threads = $this->thread_count();
        $nice    = $this->resolve_binary('nice', 'CVC_NICE_BIN');
        $nice_prefix = $nice !== null ? escapeshellarg($nice) . ' -n 19 ' : '';

        $ffmpeg_command = sprintf(
            '%s%s -i %s -an -vcodec libx264 -crf 28 -preset slow -pix_fmt yuv420p -threads %d -progress %s -y %s',
            $nice_prefix,
            escapeshellarg($ffmpeg),
            escapeshellarg($input_path),
            $threads,
            escapeshellarg($progress_path),
            escapeshellarg($output_path)
        );

        $wrapped_command = sprintf(
            '( %s > %s 2>&1 ; echo $? > %s ) > /dev/null 2>&1 & echo $!',
            $ffmpeg_command,
            escapeshellarg($log_path),
            escapeshellarg($done_path)
        );

        return (int) trim((string) shell_exec($wrapped_command));
    }

    /**
     * Read the tail of the ffmpeg -progress file and compute a percentage
     * against the known duration. Returns null percent when duration is
     * unknown (caller should show an indeterminate progress state).
     *
     * @return array{percent: ?int, finished: bool}
     */
    public function read_progress(?string $progress_path, ?float $duration): array
    {
        if ($progress_path === null || !file_exists($progress_path)) {
            return ['percent' => $duration !== null ? 0 : null, 'finished' => false];
        }

        $size = filesize($progress_path);
        $fh   = fopen($progress_path, 'r');
        if ($fh === false) {
            return ['percent' => $duration !== null ? 0 : null, 'finished' => false];
        }

        $offset = max(0, $size - 4096);
        fseek($fh, $offset);
        $tail = fread($fh, $size - $offset);
        fclose($fh);

        preg_match_all('/^out_time_ms=(\d+)$/m', (string) $tail, $time_matches);
        preg_match_all('/^progress=(\w+)$/m', (string) $tail, $progress_matches);

        $out_time_ms = $time_matches[1] ? (int) end($time_matches[1]) : 0;
        $is_end      = !empty($progress_matches[1]) && end($progress_matches[1]) === 'end';

        if ($duration === null) {
            return ['percent' => null, 'finished' => $is_end];
        }

        $percent = $is_end
            ? 100
            : min(99, (int) round(($out_time_ms / 1000000) / $duration * 100));

        return ['percent' => $percent, 'finished' => $is_end];
    }

    private function thread_count(): int
    {
        $cores = (int) trim((string) shell_exec('nproc 2>/dev/null'));
        if ($cores < 1) {
            $cores = 2;
        }

        return max(1, (int) floor($cores / 2));
    }

    /**
     * Resolve the absolute path to a binary, in order of preference:
     * 1. A wp-config.php constant override (for hosts/local dev environments
     *    where the binary isn't on PHP's PATH at all, e.g. Local by WP Engine,
     *    whose PHP runs in its own isolated container separate from the Mac's
     *    shell — having the binary on the host does not make it visible here).
     * 2. `command -v $binary` (works when PHP's PATH does include it).
     * 3. A short list of common install locations, in case PATH is minimal
     *    but the binary is actually present on disk.
     *
     * Returns null if the binary can't be found anywhere.
     */
    private function resolve_binary(string $binary, string $constant_name): ?string
    {
        $cache_key = $binary;
        if (isset(self::$resolved_binaries[$cache_key])) {
            return self::$resolved_binaries[$cache_key] ?: null;
        }

        if (defined($constant_name) && constant($constant_name) !== '' && file_exists((string) constant($constant_name))) {
            return self::$resolved_binaries[$cache_key] = (string) constant($constant_name);
        }

        $found = trim((string) shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null'));
        if ($found !== '') {
            return self::$resolved_binaries[$cache_key] = $found;
        }

        $common_paths = [
            "/usr/local/bin/{$binary}",
            "/opt/homebrew/bin/{$binary}",
            "/usr/bin/{$binary}",
            "/bin/{$binary}",
            "/snap/bin/{$binary}",
        ];

        foreach ($common_paths as $path) {
            if (file_exists($path) && is_executable($path)) {
                return self::$resolved_binaries[$cache_key] = $path;
            }
        }

        self::$resolved_binaries[$cache_key] = '';

        return null;
    }
}
