require('./index.scss');

(function () {
	'use strict';

	const settings = window.cvcSettings || {};
	const REST_URL = settings.restUrl;
	const NONCE = settings.nonce;

	const POLL_INTERVAL_MS = 1500;
	const CHUNK_SIZE = 50 * 1024 * 1024;
	const CHUNK_RETRY_ATTEMPTS = 3;

	let selectedFile = null;
	let pollTimer = null;

	document.addEventListener('DOMContentLoaded', init);

	function init() {
		const root = document.getElementById('cvc-app');
		if (!root) {
			return;
		}

		root.innerHTML = renderAppShell();

		const dropzone = root.querySelector('.cvc-dropzone');
		const fileInput = root.querySelector('.cvc-file-input');
		const browseButton = root.querySelector('.cvc-browse-button');
		const startButton = root.querySelector('.cvc-start-button');

		browseButton.addEventListener('click', () => fileInput.click());
		fileInput.addEventListener('change', (e) => handleFileSelected(e.target.files[0]));

		['dragover', 'dragenter'].forEach((evt) =>
			dropzone.addEventListener(evt, (e) => {
				e.preventDefault();
				dropzone.classList.add('is-dragover');
			})
		);
		['dragleave', 'dragend'].forEach((evt) =>
			dropzone.addEventListener(evt, () => dropzone.classList.remove('is-dragover'))
		);
		dropzone.addEventListener('drop', (e) => {
			e.preventDefault();
			dropzone.classList.remove('is-dragover');
			if (e.dataTransfer.files && e.dataTransfer.files[0]) {
				handleFileSelected(e.dataTransfer.files[0]);
			}
		});

		startButton.addEventListener('click', startCompression);

		refreshJobsList();
	}

	function renderAppShell() {
		return `
			<div class="cvc-dropzone">
				<p>${escapeHtml('Arrasta um vídeo para aqui, ou')} <button type="button" class="button cvc-browse-button">${escapeHtml('Escolher ficheiro')}</button></p>
				<p class="cvc-selected-file"></p>
				<input type="file" class="cvc-file-input" accept="video/mp4,video/quicktime,video/x-matroska,video/x-msvideo,video/webm" hidden />
			</div>
			<p>
				<button type="button" class="button button-primary cvc-start-button" disabled>${escapeHtml('Iniciar compressão')}</button>
			</p>
			<div class="cvc-progress cvc-progress--upload" hidden>
				<p class="cvc-progress__label">${escapeHtml('A enviar ficheiro…')}</p>
				<div class="cvc-progress__track"><div class="cvc-progress__bar"></div></div>
			</div>
			<div class="cvc-progress cvc-progress--compress" hidden>
				<p class="cvc-progress__label">${escapeHtml('A comprimir…')}</p>
				<div class="cvc-progress__track"><div class="cvc-progress__bar"></div></div>
			</div>
			<p class="cvc-error" hidden></p>
			<h2>${escapeHtml('Vídeos comprimidos')}</h2>
			<table class="widefat striped cvc-jobs-table">
				<thead>
					<tr>
						<th>${escapeHtml('Ficheiro original')}</th>
						<th>${escapeHtml('Tamanho')}</th>
						<th>${escapeHtml('Data')}</th>
						<th>${escapeHtml('Estado')}</th>
						<th>${escapeHtml('Ações')}</th>
					</tr>
				</thead>
				<tbody></tbody>
			</table>
		`;
	}

	function handleFileSelected(file) {
		if (!file) {
			return;
		}
		selectedFile = file;
		document.querySelector('.cvc-selected-file').textContent = file.name;
		document.querySelector('.cvc-start-button').disabled = false;
	}

	function setControlsDisabled(disabled) {
		document.querySelector('.cvc-dropzone').classList.toggle('is-disabled', disabled);
		document.querySelector('.cvc-browse-button').disabled = disabled;
		document.querySelector('.cvc-start-button').disabled = disabled || !selectedFile;
	}

	function showError(message) {
		const el = document.querySelector('.cvc-error');
		el.textContent = message;
		el.hidden = !message;
	}

	function startCompression() {
		if (!selectedFile) {
			return;
		}

		showError('');
		setControlsDisabled(true);

		const file = selectedFile;
		const uploadProgress = document.querySelector('.cvc-progress--upload');
		const uploadBar = uploadProgress.querySelector('.cvc-progress__bar');
		uploadProgress.hidden = false;
		uploadBar.classList.remove('is-indeterminate');
		uploadBar.style.width = '0%';

		const totalChunks = Math.max(1, Math.ceil(file.size / CHUNK_SIZE));

		initUpload(file, totalChunks)
			.then((jobId) => uploadChunks(jobId, file, totalChunks, uploadBar))
			.then((response) => {
				uploadProgress.hidden = true;
				beginPolling(response.id);
			})
			.catch((err) => {
				uploadProgress.hidden = true;
				showError(err.message || 'Ocorreu um erro ao enviar o ficheiro.');
				resetForm();
			});
	}

	function initUpload(file, totalChunks) {
		return fetch(REST_URL + 'jobs/init', {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-WP-Nonce': NONCE,
			},
			body: JSON.stringify({
				filename: file.name,
				total_size: file.size,
				total_chunks: totalChunks,
			}),
		}).then((r) =>
			r.json().then((data) => {
				if (!r.ok) {
					throw new Error(data.message || 'Não foi possível iniciar o envio.');
				}
				return data.id;
			})
		);
	}

	// Uploads chunks one at a time (not in parallel): the server appends each
	// chunk to the end of the file being reconstructed, in order, so chunks
	// must arrive and be acknowledged strictly sequentially.
	function uploadChunks(jobId, file, totalChunks, uploadBar) {
		let index = 0;

		const uploadNext = () => {
			const start = index * CHUNK_SIZE;
			const end = Math.min(start + CHUNK_SIZE, file.size);
			const chunk = file.slice(start, end);

			return uploadChunkWithRetry(jobId, index, chunk, CHUNK_RETRY_ATTEMPTS, (loaded) => {
				const overall = (start + loaded) / file.size;
				uploadBar.style.width = Math.round(overall * 100) + '%';
			}).then((response) => {
				index += 1;
				return index >= totalChunks ? response : uploadNext();
			});
		};

		return uploadNext();
	}

	function uploadChunkWithRetry(jobId, index, chunk, attemptsLeft, onProgress) {
		return uploadChunk(jobId, index, chunk, onProgress).catch((err) => {
			if (attemptsLeft <= 1) {
				throw err;
			}
			return uploadChunkWithRetry(jobId, index, chunk, attemptsLeft - 1, onProgress);
		});
	}

	function uploadChunk(jobId, index, chunk, onProgress) {
		return new Promise((resolve, reject) => {
			const formData = new FormData();
			formData.append('index', String(index));
			formData.append('chunk', chunk);

			const xhr = new XMLHttpRequest();
			xhr.open('POST', REST_URL + 'jobs/' + jobId + '/chunk');
			xhr.setRequestHeader('X-WP-Nonce', NONCE);

			xhr.upload.addEventListener('progress', (e) => {
				if (e.lengthComputable) {
					onProgress(e.loaded);
				}
			});

			xhr.addEventListener('load', () => {
				let response = {};
				try {
					response = JSON.parse(xhr.responseText);
				} catch (e) {
					response = {};
				}

				if (xhr.status >= 200 && xhr.status < 300) {
					onProgress(chunk.size);
					resolve(response);
				} else {
					reject(new Error(response.message || 'Ocorreu um erro ao enviar o ficheiro.'));
				}
			});

			xhr.addEventListener('error', () => {
				reject(new Error('Ocorreu um erro de rede ao enviar o ficheiro.'));
			});

			xhr.send(formData);
		});
	}

	function resetForm() {
		selectedFile = null;
		document.querySelector('.cvc-selected-file').textContent = '';
		document.querySelector('.cvc-file-input').value = '';
		setControlsDisabled(false);
	}

	function beginPolling(jobId) {
		const compressProgress = document.querySelector('.cvc-progress--compress');
		const compressBar = compressProgress.querySelector('.cvc-progress__bar');
		compressProgress.hidden = false;
		compressBar.classList.remove('is-indeterminate');
		compressBar.style.width = '0%';

		if (pollTimer) {
			clearInterval(pollTimer);
		}

		pollTimer = setInterval(() => pollStatus(jobId), POLL_INTERVAL_MS);
		pollStatus(jobId);
	}

	function pollStatus(jobId) {
		fetch(REST_URL + 'jobs/' + jobId + '/status', {
			headers: { 'X-WP-Nonce': NONCE },
		})
			.then((r) => r.json())
			.then((data) => {
				const compressProgress = document.querySelector('.cvc-progress--compress');
				const compressBar = compressProgress.querySelector('.cvc-progress__bar');

				if (data.progress_percent === null || data.progress_percent === undefined) {
					compressBar.classList.add('is-indeterminate');
				} else {
					compressBar.classList.remove('is-indeterminate');
					compressBar.style.width = data.progress_percent + '%';
				}

				if (data.status === 'done' || data.status === 'error') {
					clearInterval(pollTimer);
					pollTimer = null;
					compressProgress.hidden = true;

					if (data.status === 'error') {
						showError(data.error_message || 'A compressão falhou.');
					}

					resetForm();
					refreshJobsList();
				}
			})
			.catch(() => {
				/* transient network error while polling; try again next tick */
			});
	}

	function refreshJobsList() {
		fetch(REST_URL + 'jobs', { headers: { 'X-WP-Nonce': NONCE } })
			.then((r) => r.json())
			.then((jobs) => {
				renderJobsTable(jobs);

				const activeJob = jobs.find((j) => j.status === 'uploading' || j.status === 'processing');
				if (activeJob && !pollTimer) {
					setControlsDisabled(true);
					beginPolling(activeJob.id);
				}
			});
	}

	function renderJobsTable(jobs) {
		const tbody = document.querySelector('.cvc-jobs-table tbody');
		if (!jobs.length) {
			tbody.innerHTML = `<tr><td colspan="5">${escapeHtml('Ainda não há vídeos comprimidos.')}</td></tr>`;
			return;
		}

		tbody.innerHTML = jobs
			.map((job) => {
				const isBusy = job.status === 'uploading' || job.status === 'processing';
				const size = job.filesize ? formatBytes(job.filesize) : '—';
				const date = job.created_at ? new Date(job.created_at.replace(' ', 'T')).toLocaleString() : '—';

				let actions = '';
				if (job.status === 'done') {
					const downloadUrl =
						REST_URL + 'jobs/' + job.id + '/download?_wpnonce=' + encodeURIComponent(NONCE);
					actions += `<a class="button" href="${escapeHtml(downloadUrl)}">${escapeHtml('Download')}</a> `;
				}
				actions += `<button type="button" class="button cvc-delete-button" data-id="${job.id}" ${
					isBusy ? 'disabled' : ''
				}>${escapeHtml('Apagar')}</button>`;

				return `
					<tr>
						<td>${escapeHtml(job.original_filename)}</td>
						<td>${escapeHtml(size)}</td>
						<td>${escapeHtml(date)}</td>
						<td><span class="cvc-status cvc-status--${escapeHtml(job.status)}">${escapeHtml(statusLabel(job.status))}</span></td>
						<td>${actions}</td>
					</tr>
				`;
			})
			.join('');

		tbody.querySelectorAll('.cvc-delete-button').forEach((btn) => {
			btn.addEventListener('click', () => deleteJob(btn.dataset.id));
		});
	}

	function deleteJob(id) {
		if (!window.confirm('Apagar este vídeo comprimido?')) {
			return;
		}

		fetch(REST_URL + 'jobs/' + id, {
			method: 'DELETE',
			headers: { 'X-WP-Nonce': NONCE },
		}).then(() => refreshJobsList());
	}

	function statusLabel(status) {
		switch (status) {
			case 'uploading':
				return 'A enviar';
			case 'processing':
				return 'A comprimir';
			case 'done':
				return 'Concluído';
			case 'error':
				return 'Erro';
			default:
				return status;
		}
	}

	function formatBytes(bytes) {
		if (bytes < 1024) {
			return bytes + ' B';
		}
		const units = ['KB', 'MB', 'GB'];
		let value = bytes / 1024;
		let unitIndex = 0;
		while (value >= 1024 && unitIndex < units.length - 1) {
			value /= 1024;
			unitIndex += 1;
		}
		return value.toFixed(1) + ' ' + units[unitIndex];
	}

	function escapeHtml(str) {
		const div = document.createElement('div');
		div.textContent = str;
		return div.innerHTML;
	}
})();
