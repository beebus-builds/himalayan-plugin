import { HimalayanAIVision } from './ai-vision-core.js';

const config = window.ATF_AI || {};
const start = document.getElementById('atf-ai-start');
const status = document.getElementById('atf-ai-status');
const progress = document.getElementById('atf-ai-progress');
const fill = document.getElementById('atf-ai-progress-fill');
const text = document.getElementById('atf-ai-progress-text');
const MODEL_ID = config.model || 'onnx-community/Florence-2-base';

if (start) {
	start.addEventListener('click', run);
}

async function run() {
	if (!start) return;
	start.disabled = true;
	if (progress) progress.style.display = 'block';

	try {
		const ai = new HimalayanAIVision({
			modelId: MODEL_ID,
			onStatus: setStatus,
			onProgress: setProgress,
		});

		await ai.load();
		await ai.process(createWordPressAdapter());
	} catch (error) {
		console.error('Himalayan AI Vision:', error);
		setStatus('AI could not start: ' + (error && error.message ? error.message : 'unknown error'));
	} finally {
		start.disabled = false;
	}
}

function createWordPressAdapter() {
	return {
		async next() {
			const response = await post('atf_ai_next');
			if (!response.success || response.data.finished) return null;
			return response.data;
		},

		async getImage(item) {
			const response = await post('atf_ai_image', { id: item.id });
			if (!response.success) {
				throw new Error(response.data || 'Image unavailable');
			}
			return response.data.url;
		},

		async saveAlt(item, alt) {
			const response = await post('atf_ai_save', { id: item.id, alt });
			if (!response.success) {
				throw new Error(response.data || 'Could not save ALT text');
			}
		},

		onError(item, error) {
			console.warn('WordPress image skipped:', item && item.id, error);
		},
	};
}

async function post(action, data = {}) {
	const body = new URLSearchParams({ action, nonce: config.nonce });
	Object.keys(data).forEach((key) => body.append(key, data[key]));
	const response = await fetch(config.ajaxurl, {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
		body,
		credentials: 'same-origin',
	});
	return response.json();
}

function setStatus(value) {
	if (status) status.textContent = value;
}

function setProgress(done, total) {
	const pct = total ? Math.min(100, Math.round((done / total) * 100)) : 0;
	if (fill) fill.style.width = pct + '%';
	if (text) text.textContent = done + ' processed' + (total ? ' (' + pct + '%)' : '');
}
