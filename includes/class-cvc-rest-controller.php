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
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'create_job'],
                'permission_callback' => [$this, 'check_permission'],
            ],
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'list_jobs'],
                'permission_callback' => [$this, 'check_permission'],
            ],
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

    public function create_job(WP_REST_Request $request)
    {
        if ($this->repo->find_active()) {
            return new WP_Error('cvc_job_active', __('Já existe uma compressão em curso. Aguarde que termine.', 'carmo-video-compressor'), ['status' => 409]);
        }

        $files = $request->get_file_params();
        if (empty($files['video']) || !isset($files['video']['tmp_name'])) {
            return new WP_Error('cvc_no_file', __('Nenhum ficheiro de vídeo foi enviado.', 'carmo-video-compressor'), ['status' => 400]);
        }

        $file = $files['video'];

        if (!empty($file['error'])) {
            return new WP_Error('cvc_upload_error', __('Ocorreu um erro no envio do ficheiro.', 'carmo-video-compressor'), ['status' => 400]);
        }

        $filetype = wp_check_filetype_and_ext($file['tmp_name'], $file['name'], self::ALLOWED_TYPES);
        if (empty($filetype['ext']) || !isset(self::ALLOWED_TYPES[$filetype['ext']])) {
            return new WP_Error('cvc_invalid_type', __('Tipo de ficheiro não suportado. Use mp4, mov, mkv, avi ou webm.', 'carmo-video-compressor'), ['status' => 400]);
        }

        $base    = cvc_upload_base_dir();
        $tmp_dir = trailingslashit($base) . 'tmp';
        wp_mkdir_p($tmp_dir);

        $original_filename = sanitize_file_name($file['name']);
        $ext               = $filetype['ext'];

        $job_id = $this->repo->create([
            'original_filename' => $original_filename,
            'status'            => 'uploading',
        ]);

        $input_path    = trailingslashit($tmp_dir) . $job_id . '-input.' . $ext;
        $progress_path = trailingslashit($tmp_dir) . $job_id . '-progress.txt';
        $log_path      = trailingslashit($tmp_dir) . $job_id . '-ffmpeg.log';
        $done_path     = trailingslashit($tmp_dir) . $job_id . '-done';
        $output_path   = trailingslashit($base) . 'compressed/' . $job_id . '-' . pathinfo($original_filename, PATHINFO_FILENAME) . '.mp4';

        if (!move_uploaded_file($file['tmp_name'], $input_path)) {
            $this->repo->delete($job_id);

            return new WP_Error('cvc_move_failed', __('Não foi possível guardar o ficheiro enviado.', 'carmo-video-compressor'), ['status' => 500]);
        }

        wp_mkdir_p(trailingslashit($base) . 'compressed');

        $duration = $this->compressor->probe_duration($input_path);

        $pid = $this->compressor->start($input_path, $output_path, $progress_path, $log_path, $done_path);

        $this->repo->update($job_id, [
            'status'           => 'processing',
            'duration_seconds' => $duration,
            'pid'              => $pid,
            'progress_file'    => $progress_path,
            'log_file'         => $log_path,
            'done_file'        => $done_path,
            'output_filename'  => basename($output_path),
        ]);

        return new WP_REST_Response(['id' => $job_id, 'status' => 'processing'], 201);
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

        if (!file_exists($job['done_file'])) {
            $duration = $job['duration_seconds'] !== null ? (float) $job['duration_seconds'] : null;
            $progress = $this->compressor->read_progress($job['progress_file'], $duration);

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
            $log = file_exists($job['log_file'])
                ? $this->tail_file($job['log_file'], 4000)
                : __('Sem detalhes do erro.', 'carmo-video-compressor');

            if (file_exists($output_path)) {
                unlink($output_path);
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
        $size = filesize($path);
        $fh   = fopen($path, 'r');
        if ($fh === false) {
            return '';
        }

        $offset = max(0, $size - $bytes);
        fseek($fh, $offset);
        $content = (string) fread($fh, $size - $offset);
        fclose($fh);

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
            unlink($scratch_file);
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
                unlink($output_path);
            }
        }

        $this->repo->delete($id);

        return new WP_REST_Response(['deleted' => true], 200);
    }
}
