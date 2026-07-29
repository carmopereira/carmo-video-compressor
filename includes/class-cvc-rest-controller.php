<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class CVC_Rest_Controller
{
    private const NAMESPACE = 'carmo-video-compressor/v1';

    private CVC_Jobs_Repository $repo;
    private CVC_Compressor $compressor;

    public function __construct()
    {
        $this->repo       = new CVC_Jobs_Repository();
        $this->compressor = new CVC_Compressor();

        add_action('rest_api_init', [$this, 'register_routes']);
    }

    public function register_routes(): void
    {
        register_rest_route(self::NAMESPACE, '/jobs', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'list_jobs'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/jobs/init', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'init_upload'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/jobs/(?P<id>\d+)/chunk', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'upload_chunk'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/jobs/(?P<id>\d+)/status', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'get_status'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/jobs/(?P<id>\d+)/download', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'download_job'],
            'permission_callback' => [$this, 'check_permission'],
        ]);

        register_rest_route(self::NAMESPACE, '/jobs/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => [$this, 'delete_job'],
            'permission_callback' => [$this, 'check_permission'],
        ]);
    }

    public function check_permission(): bool
    {
        return current_user_can('manage_options');
    }

    private const ALLOWED_TYPES = [
        'mp4'  => 'video/mp4',
        'mov'  => 'video/quicktime',
        'mkv'  => 'video/x-matroska',
        'avi'  => 'video/x-msvideo',
        'webm' => 'video/webm',
    ];

    /** Chunks are uploaded client-side at ~50MB; this is a server-side sanity ceiling, not the target size. */
    private const MAX_CHUNK_BYTES = 200 * 1024 * 1024;

    /** An "uploading" job with no chunk activity for this long is considered abandoned and freed up. */
    private const UPLOAD_STALE_MINUTES = 15;

    /**
     * Starts a new chunked upload: creates the job row and the metadata
     * sidecar file that tracks how many chunks have been received so far.
     */
    public function init_upload(WP_REST_Request $request)
    {
        try {
            return $this->do_init_upload($request);
        } catch (\Throwable $e) {
            return $this->upload_error_response('init_upload', $e);
        }
    }

    private function do_init_upload(WP_REST_Request $request)
    {
        $this->reconcile_stale_uploads();

        if ($this->repo->find_active()) {
            return new WP_Error('cvc_job_active', __('Já existe uma compressão em curso. Aguarde que termine.', 'carmo-video-compressor'), ['status' => 409]);
        }

        $filename     = sanitize_file_name((string) $request->get_param('filename'));
        $total_size   = (int) $request->get_param('total_size');
        $total_chunks = (int) $request->get_param('total_chunks');

        if ($filename === '' || $total_size <= 0 || $total_chunks <= 0) {
            return new WP_Error('cvc_invalid_upload', __('Dados de upload inválidos.', 'carmo-video-compressor'), ['status' => 400]);
        }

        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        if (!isset(self::ALLOWED_TYPES[$ext])) {
            return new WP_Error('cvc_invalid_type', __('Tipo de ficheiro não suportado. Use mp4, mov, mkv, avi ou webm.', 'carmo-video-compressor'), ['status' => 400]);
        }

        $base    = cvc_upload_base_dir();
        $tmp_dir = trailingslashit($base) . 'tmp';
        wp_mkdir_p($tmp_dir);

        $job_id = $this->repo->create([
            'original_filename' => $filename,
            'status'            => 'uploading',
        ]);

        $this->write_upload_meta($job_id, [
            'ext'          => $ext,
            'total_size'   => $total_size,
            'total_chunks' => $total_chunks,
            'received'     => 0,
        ]);

        return new WP_REST_Response(['id' => $job_id], 201);
    }

    /**
     * Receives one chunk, appends it to the file being reconstructed on
     * disk, and, once every chunk has arrived, kicks off the compression
     * job exactly like the old single-request upload used to.
     */
    public function upload_chunk(WP_REST_Request $request)
    {
        try {
            return $this->do_upload_chunk($request);
        } catch (\Throwable $e) {
            return $this->upload_error_response('upload_chunk', $e);
        }
    }

    private function do_upload_chunk(WP_REST_Request $request)
    {
        $job_id = (int) $request->get_param('id');
        $job    = $this->repo->find($job_id);

        if (!$job || $job['status'] !== 'uploading') {
            return new WP_Error('cvc_not_found', __('Job não encontrado ou já não aceita mais dados.', 'carmo-video-compressor'), ['status' => 404]);
        }

        $meta = $this->read_upload_meta($job_id);
        if (!$meta) {
            return new WP_Error('cvc_not_found', __('Sessão de upload não encontrada.', 'carmo-video-compressor'), ['status' => 404]);
        }

        $files = $request->get_file_params();
        if (empty($files['chunk']) || !isset($files['chunk']['tmp_name']) || !is_uploaded_file($files['chunk']['tmp_name'])) {
            return new WP_Error('cvc_no_file', __('Pedaço de ficheiro em falta.', 'carmo-video-compressor'), ['status' => 400]);
        }

        $index = (int) $request->get_param('index');
        if ($index < 0 || $index >= $meta['total_chunks']) {
            return new WP_Error('cvc_invalid_chunk', __('Índice de pedaço inválido.', 'carmo-video-compressor'), ['status' => 400]);
        }

        $chunk_path = $files['chunk']['tmp_name'];
        if (filesize($chunk_path) > self::MAX_CHUNK_BYTES) {
            return new WP_Error('cvc_chunk_too_large', __('Pedaço de ficheiro demasiado grande.', 'carmo-video-compressor'), ['status' => 413]);
        }

        $base      = cvc_upload_base_dir();
        $tmp_dir   = trailingslashit($base) . 'tmp';
        $part_path = trailingslashit($tmp_dir) . $job_id . '-input.' . $meta['ext'] . '.part';

        // Chunk already applied (client retried after a lost response): acknowledge without re-appending.
        if ($index < $meta['received']) {
            return new WP_REST_Response(['received' => $meta['received'], 'total' => $meta['total_chunks']], 200);
        }

        if ($index > $meta['received']) {
            return new WP_Error('cvc_out_of_order', __('Pedaços de ficheiro fora de ordem.', 'carmo-video-compressor'), ['status' => 409]);
        }

        $this->append_chunk($part_path, $chunk_path);

        $meta['received']++;
        $this->write_upload_meta($job_id, $meta);

        $this->repo->update($job_id, [
            'progress_percent' => (int) round(($meta['received'] / $meta['total_chunks']) * 100),
        ]);

        if ($meta['received'] < $meta['total_chunks']) {
            return new WP_REST_Response(['received' => $meta['received'], 'total' => $meta['total_chunks']], 200);
        }

        return $this->finalize_upload($job_id, $job, $meta, $part_path);
    }

    /**
     * Streams the newly received chunk onto the end of the file being
     * reconstructed. WP_Filesystem has no append mode, and reading the
     * whole (potentially multi-GB) file back into memory on every chunk
     * would not scale, so this uses direct, streamed filesystem calls
     * on purpose (same trade-off already accepted for readfile() on the
     * download endpoint).
     */
    private function append_chunk(string $part_path, string $chunk_tmp_path): void
    {
        $out = fopen($part_path, 'ab');
        if ($out === false) {
            throw new \RuntimeException('Não foi possível abrir o ficheiro de destino.');
        }

        $in = fopen($chunk_tmp_path, 'rb');
        if ($in === false) {
            fclose($out);
            throw new \RuntimeException('Não foi possível ler o pedaço enviado.');
        }

        stream_copy_to_stream($in, $out);

        fclose($in);
        fclose($out);
    }

    /**
     * @param array<string, mixed> $job
     * @param array<string, mixed> $meta
     */
    private function finalize_upload(int $job_id, array $job, array $meta, string $part_path)
    {
        $base    = cvc_upload_base_dir();
        $tmp_dir = trailingslashit($base) . 'tmp';

        if (!file_exists($part_path) || filesize($part_path) !== $meta['total_size']) {
            $this->cleanup_upload($job_id, $part_path);
            $this->repo->delete($job_id);

            return new WP_Error('cvc_upload_incomplete', __('O upload terminou incompleto. Tenta novamente.', 'carmo-video-compressor'), ['status' => 400]);
        }

        $input_path = trailingslashit($tmp_dir) . $job_id . '-input.' . $meta['ext'];

        require_once ABSPATH . 'wp-admin/includes/file.php';
        global $wp_filesystem;

        if (!WP_Filesystem() || !$wp_filesystem || !$wp_filesystem->move($part_path, $input_path, true)) {
            $this->cleanup_upload($job_id, $part_path);
            $this->repo->delete($job_id);

            return new WP_Error('cvc_move_failed', __('Não foi possível guardar o ficheiro enviado.', 'carmo-video-compressor'), ['status' => 500]);
        }

        $filetype = wp_check_filetype_and_ext($input_path, $job['original_filename'], self::ALLOWED_TYPES);
        if (empty($filetype['ext']) || !isset(self::ALLOWED_TYPES[$filetype['ext']])) {
            wp_delete_file($input_path);
            $this->delete_upload_meta($job_id);
            $this->repo->delete($job_id);

            return new WP_Error('cvc_invalid_type', __('Tipo de ficheiro não suportado. Use mp4, mov, mkv, avi ou webm.', 'carmo-video-compressor'), ['status' => 400]);
        }

        $this->delete_upload_meta($job_id);

        $progress_path = trailingslashit($tmp_dir) . $job_id . '-progress.txt';
        $log_path      = trailingslashit($tmp_dir) . $job_id . '-ffmpeg.log';
        $done_path     = trailingslashit($tmp_dir) . $job_id . '-done';
        $output_path   = trailingslashit($base) . 'compressed/' . $job_id . '-' . pathinfo($job['original_filename'], PATHINFO_FILENAME) . '.mp4';

        wp_mkdir_p(trailingslashit($base) . 'compressed');

        $duration = $this->compressor->probe_duration($input_path);
        $pid      = $this->compressor->start($input_path, $output_path, $progress_path, $log_path, $done_path);

        $this->repo->update($job_id, [
            'status'           => 'processing',
            'progress_percent' => 0,
            'duration_seconds' => $duration,
            'pid'              => $pid,
            'progress_file'    => $progress_path,
            'log_file'         => $log_path,
            'done_file'        => $done_path,
            'output_filename'  => basename($output_path),
        ]);

        return new WP_REST_Response(['id' => $job_id, 'status' => 'processing'], 201);
    }

    private function upload_error_response(string $context, \Throwable $e): WP_Error
    {
        error_log(sprintf('[carmo-video-compressor] %s failed: %s in %s:%d', $context, $e->getMessage(), $e->getFile(), $e->getLine()));

        return new WP_Error(
            'cvc_upload_error',
            sprintf(
                /* translators: %s: real error message, only shown to administrators. */
                __('Ocorreu um erro ao enviar o ficheiro: %s', 'carmo-video-compressor'),
                $e->getMessage()
            ),
            ['status' => 500]
        );
    }

    /**
     * If a chunked upload is abandoned (tab closed, network gone for good),
     * the job would otherwise sit in "uploading" forever and permanently
     * block find_active(). Anything with no chunk activity for a while is
     * treated as failed and cleaned up so a new upload can start.
     */
    private function reconcile_stale_uploads(): void
    {
        $job = $this->repo->find_active();
        if (!$job || $job['status'] !== 'uploading') {
            return;
        }

        $updated_at = strtotime((string) $job['updated_at']);
        if ($updated_at !== false && $updated_at > time() - self::UPLOAD_STALE_MINUTES * MINUTE_IN_SECONDS) {
            return;
        }

        $job_id = (int) $job['id'];
        $meta   = $this->read_upload_meta($job_id);

        if ($meta) {
            $base      = cvc_upload_base_dir();
            $tmp_dir   = trailingslashit($base) . 'tmp';
            $part_path = trailingslashit($tmp_dir) . $job_id . '-input.' . $meta['ext'] . '.part';
            $this->cleanup_upload($job_id, $part_path);
        }

        $this->repo->delete($job_id);
    }

    private function cleanup_upload(int $job_id, string $part_path): void
    {
        if (file_exists($part_path)) {
            wp_delete_file($part_path);
        }

        $this->delete_upload_meta($job_id);
    }

    private function upload_meta_path(int $job_id): string
    {
        $base = cvc_upload_base_dir();

        return trailingslashit($base) . 'tmp/' . $job_id . '-upload.json';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function read_upload_meta(int $job_id): ?array
    {
        $path = $this->upload_meta_path($job_id);
        if (!file_exists($path)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function write_upload_meta(int $job_id, array $meta): void
    {
        file_put_contents($this->upload_meta_path($job_id), wp_json_encode($meta));
    }

    private function delete_upload_meta(int $job_id): void
    {
        $path = $this->upload_meta_path($job_id);
        if (file_exists($path)) {
            wp_delete_file($path);
        }
    }

    public function list_jobs(): WP_REST_Response
    {
        return new WP_REST_Response($this->repo->all(), 200);
    }

    public function get_status(WP_REST_Request $request)
    {
        $id  = (int) $request->get_param('id');
        $job = $this->repo->find($id);

        if (!$job) {
            return new WP_Error('cvc_not_found', __('Job não encontrado.', 'carmo-video-compressor'), ['status' => 404]);
        }

        if ($job['status'] === 'processing') {
            $job = $this->reconcile_job($job);
        }

        return new WP_REST_Response([
            'status'           => $job['status'],
            'progress_percent' => $job['progress_percent'],
            'error_message'    => $job['error_message'],
        ], 200);
    }

    /**
     * @param array<string, mixed> $job
     * @return array<string, mixed>
     */
    private function reconcile_job(array $job): array
    {
        $id = (int) $job['id'];

        if (empty($job['done_file'])) {
            // The job never got its worker files recorded (e.g. the process
            // failed to launch at all). There's nothing to poll for, so fail
            // it outright instead of looping forever.
            $error = __('A compressão não foi iniciada corretamente.', 'carmo-video-compressor');
            $this->repo->update($id, ['status' => 'error', 'error_message' => $error]);
            $job['status']        = 'error';
            $job['error_message'] = $error;
            $this->cleanup_job_scratch_files($job);

            return $job;
        }

        if (!file_exists($job['done_file'])) {
            $duration = $job['duration_seconds'] !== null ? (float) $job['duration_seconds'] : null;
            $progress = $this->compressor->read_progress($job['progress_file'] ?: null, $duration);

            if ($progress['percent'] !== null && $progress['percent'] !== (int) $job['progress_percent']) {
                $this->repo->update($id, ['progress_percent' => $progress['percent']]);
                $job['progress_percent'] = $progress['percent'];
            }

            return $job;
        }

        $exit_code = trim((string) file_get_contents($job['done_file']));
        $base      = cvc_upload_base_dir();
        $output_path = trailingslashit($base) . 'compressed/' . $job['output_filename'];

        if ($exit_code === '0' && file_exists($output_path)) {
            $this->repo->update($id, [
                'status'            => 'done',
                'progress_percent'  => 100,
                'filesize'          => filesize($output_path),
            ]);
            $job['status']            = 'done';
            $job['progress_percent']  = 100;
        } else {
            $log = !empty($job['log_file']) && file_exists($job['log_file'])
                ? $this->tail_file($job['log_file'], 4000)
                : __('Sem detalhes do erro.', 'carmo-video-compressor');

            if (file_exists($output_path)) {
                wp_delete_file($output_path);
            }

            $this->repo->update($id, [
                'status'        => 'error',
                'error_message' => $log,
            ]);
            $job['status']        = 'error';
            $job['error_message'] = $log;
        }

        $this->cleanup_job_scratch_files($job);

        return $job;
    }

    private function tail_file(string $path, int $bytes): string
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        global $wp_filesystem;

        if (!WP_Filesystem() || !$wp_filesystem) {
            return '';
        }

        $content = $wp_filesystem->get_contents($path);
        if ($content === false) {
            return '';
        }

        if (strlen($content) > $bytes) {
            $content = substr($content, -$bytes);
        }

        return trim($content);
    }

    /**
     * @param array<string, mixed> $job
     */
    private function cleanup_job_scratch_files(array $job): void
    {
        $base    = cvc_upload_base_dir();
        $tmp_dir = trailingslashit($base) . 'tmp';
        $id      = (int) $job['id'];

        foreach (glob(trailingslashit($tmp_dir) . $id . '-*') ?: [] as $scratch_file) {
            wp_delete_file($scratch_file);
        }
    }

    public function download_job(WP_REST_Request $request)
    {
        $id  = (int) $request->get_param('id');
        $job = $this->repo->find($id);

        if (!$job || $job['status'] !== 'done') {
            return new WP_Error('cvc_not_ready', __('O ficheiro ainda não está disponível.', 'carmo-video-compressor'), ['status' => 404]);
        }

        $base        = cvc_upload_base_dir();
        $output_path = trailingslashit($base) . 'compressed/' . $job['output_filename'];

        if (!file_exists($output_path)) {
            return new WP_Error('cvc_missing_file', __('Ficheiro não encontrado no servidor.', 'carmo-video-compressor'), ['status' => 404]);
        }

        $download_name = pathinfo($job['original_filename'], PATHINFO_FILENAME) . '-comprimido.mp4';

        nocache_headers();
        header('Content-Type: video/mp4');
        header('Content-Disposition: attachment; filename="' . $download_name . '"');
        header('Content-Length: ' . filesize($output_path));
        readfile($output_path);
        exit;
    }

    public function delete_job(WP_REST_Request $request)
    {
        $id  = (int) $request->get_param('id');
        $job = $this->repo->find($id);

        if (!$job) {
            return new WP_Error('cvc_not_found', __('Job não encontrado.', 'carmo-video-compressor'), ['status' => 404]);
        }

        if (in_array($job['status'], ['uploading', 'processing'], true)) {
            return new WP_Error('cvc_still_active', __('Não é possível apagar um job em curso.', 'carmo-video-compressor'), ['status' => 409]);
        }

        if (!empty($job['output_filename'])) {
            $base        = cvc_upload_base_dir();
            $output_path = trailingslashit($base) . 'compressed/' . $job['output_filename'];
            if (file_exists($output_path)) {
                wp_delete_file($output_path);
            }
        }

        $this->repo->delete($id);

        return new WP_REST_Response(['deleted' => true], 200);
    }
}
