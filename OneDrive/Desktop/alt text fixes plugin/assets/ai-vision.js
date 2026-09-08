import { pipeline, env } from 'https://cdn.jsdelivr.net/npm/@huggingface/transformers@3.8.1';

env.allowLocalModels = false;
env.useBrowserCache = true;

a = window.ATF_AI || {};
const start = document.getElementById('atf-ai-start');
const status = document.getElementById('atf-ai-status');
const progress = document.getElementById('atf-ai-progress');
const fill = document.getElementById('atf-ai-progress-fill');
const text = document.getElementById('atf-ai-progress-text');

if (start) {
	start.addEventListener('click', run);
}

async function run() {
	start.disabled = true;
	progress.style.display = 'block';
	setStatus('Loading local vision model… the first run may take a while.');
	try {
		const captioner = await pipeline('image-to-text', a.model || 'onnx-community/Florence-2-base', {
			device: 'webgpu',
			dtype: 'q4',
		});
		setStatus('Model ready. Processing images locally in your browser.');
		let processed = 0;
		while (true) {
			const next = await post('atf_ai_next');
			if (!next.success || next.data.finished) {
				setProgress(processed, processed);
				setStatus('Finished — all eligible images have been processed.');
				break;
			}
			const id = next.data.id;
			const image = await post('atf_ai_image', { id });
			if (!image.success) {
				processed++;
				setProgress(processed, processed + 1);
				continue;
			}
			const result = await captioner(image.data.url, { max_new_tokens: 64 });
			const raw = extractText(result);
			const alt = cleanAlt(raw);
			if (alt) {
				await post('atf_ai_save', { id, alt });
			}
			processed++;
			setProgress(processed, processed + 1);
		}
	} catch (error) {
		console.error('Himalayan AI Vision:', error);
		setStatus('AI could not start: ' + (error && error.message ? error.message : 'unknown error') + '. Try a browser with WebGPU support or reload and retry.');
	} finally {
		start.disabled = false;
	}
}

async function post(action, data) {
	const body = new URLSearchParams({ action, nonce: a.nonce });
	Object.keys(data || {}).forEach((key) => body.append(key, data[key]));
	const response = await fetch(a.ajaxurl, {
		method: 'POST',
		headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
		body,
		credentials: 'same-origin',
	});
	return response.json();
}

function extractText(result) {
	if (Array.isArray(result) && result[0]) {
		return result[0].generated_text || result[0].text || '';
	}
	return result && (result.generated_text || result.text) ? (result.generated_text || result.text) : '';
}

function cleanAlt(value) {
	let value2 = String(value || '').replace(/<[^>]+>/g, ' ').replace(/[\r\n\t]+/g, ' ').trim();
	value2 = value2.replace(/^(?:alt\s*text\s*:\s*)/i, '').replace(/^(?:the\s+image\s+(?:shows|depicts)\s+)/i, '').trim();
	if (!value2) return '';
	if (value2.length > 125) {
		value2 = value2.slice(0, 122).replace(/\s+\S*$/, '') + '...';
	}
	return value2;
}

function setStatus(value) {
	if (status) status.textContent = value;
}

function setProgress(done, total) {
	const pct = total ? Math.min(100, Math.round((done / total) * 100)) : 0;
	if (fill) fill.style.width = pct + '%';
	if (text) text.textContent = done + ' processed' + (total ? ' (' + pct + '%)' : '');
}
