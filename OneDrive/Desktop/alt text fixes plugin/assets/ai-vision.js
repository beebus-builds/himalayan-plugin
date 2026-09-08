import {
	Florence2ForConditionalGeneration,
	AutoProcessor,
	load_image,
} from 'https://cdn.jsdelivr.net/npm/@huggingface/transformers@3.8.1';

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
	start.disabled = true;
	progress.style.display = 'block';
	setStatus('Loading Florence-2 locally… the first run may download the model.');

	try {
		const model = await Florence2ForConditionalGeneration.from_pretrained(MODEL_ID, {
			dtype: {
				embed_tokens: 'fp16',
				vision_encoder: 'fp16',
				encoder_model: 'q4',
				decoder_model_merged: 'q4',
			},
			device: 'webgpu',
		});
		const processor = await AutoProcessor.from_pretrained(MODEL_ID);
		setStatus('Florence-2 ready. Images are being processed in this browser.');

		let processed = 0;
		while (true) {
			const next = await post('atf_ai_next');
			if (!next.success || next.data.finished) {
				setProgress(processed, processed);
				setStatus('Finished — all eligible images have been processed.');
				break;
			}

			const id = next.data.id;
			const imageResponse = await post('atf_ai_image', { id });
			if (!imageResponse.success) {
				processed++;
				setProgress(processed, processed + 1);
				continue;
			}

			try {
				const image = await load_image(imageResponse.data.url);
				const task = '<MORE_DETAILED_CAPTION>';
				const prompts = processor.construct_prompts(task);
				const inputs = await processor(image, prompts);
				const generatedIds = await model.generate({ ...inputs, max_new_tokens: 80 });
				const generatedText = processor.batch_decode(generatedIds, { skip_special_tokens: false })[0];
				const result = processor.post_process_generation(generatedText, task, image.size);
				const alt = cleanAlt(result && result[task] ? result[task] : generatedText);

				if (alt) {
					await post('atf_ai_save', { id, alt });
				}
			} catch (error) {
				console.warn('Himalayan AI image failed:', id, error);
			}

			processed++;
			setProgress(processed, processed + 1);
		}
	} catch (error) {
		console.error('Himalayan AI Vision:', error);
		setStatus('AI could not start: ' + (error && error.message ? error.message : 'unknown error') + '. WebGPU may be unavailable in this browser.');
	} finally {
		start.disabled = false;
	}
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

function cleanAlt(value) {
	let output = String(value || '')
		.replace(/<[^>]+>/g, ' ')
		.replace(/[\r\n\t]+/g, ' ')
		.replace(/^(?:alt\s*text\s*:\s*)/i, '')
		.replace(/^(?:the\s+image\s+(?:shows|depicts)\s+)/i, '')
		.trim();
	if (!output) return '';
	if (output.length > 125) {
		output = output.slice(0, 122).replace(/\s+\S*$/, '') + '...';
	}
	return output;
}

function setStatus(value) {
	if (status) status.textContent = value;
}

function setProgress(done, total) {
	const pct = total ? Math.min(100, Math.round((done / total) * 100)) : 0;
	if (fill) fill.style.width = pct + '%';
	if (text) text.textContent = done + ' processed' + (total ? ' (' + pct + '%)' : '');
}
