import {
	Florence2ForConditionalGeneration,
	AutoProcessor,
	load_image,
} from 'https://cdn.jsdelivr.net/npm/@huggingface/transformers@3.8.1';

/**
 * CMS-independent browser AI engine.
 *
 * The engine knows how to load Florence-2 and generate alt text. It does not
 * know anything about WordPress, Shopify, Duda, or another CMS. A CMS adapter
 * supplies the image queue and persistence methods.
 */
export class HimalayanAIVision {
	constructor(options = {}) {
		this.modelId = options.modelId || 'onnx-community/Florence-2-base';
		this.onStatus = options.onStatus || (() => {});
		this.onProgress = options.onProgress || (() => {});
		this.model = null;
		this.processor = null;
		this.device = null;
	}

	async load() {
		this.onStatus('Loading Florence-2 locally… the first run may download the model.');

		try {
			this.device = 'webgpu';
			this.model = await Florence2ForConditionalGeneration.from_pretrained(this.modelId, {
				dtype: {
					embed_tokens: 'fp16',
					vision_encoder: 'fp16',
					encoder_model: 'q4',
					decoder_model_merged: 'q4',
				},
				device: 'webgpu',
			});
		} catch (webgpuError) {
			console.warn('Himalayan AI: WebGPU unavailable, falling back to WASM.', webgpuError);
			this.device = 'wasm';
			this.model = await Florence2ForConditionalGeneration.from_pretrained(this.modelId, {
				dtype: 'q8',
				device: 'wasm',
			});
		}

		this.processor = await AutoProcessor.from_pretrained(this.modelId);
		this.onStatus(`Florence-2 ready (${this.device}). Images are processed locally in this browser.`);
		return this;
	}

	async describe(dataUrl) {
		if (!this.model || !this.processor) {
			throw new Error('AI model is not loaded.');
		}

		const image = await load_image(dataUrl);
		const task = '<MORE_DETAILED_CAPTION>';
		const prompts = this.processor.construct_prompts(task);
		const inputs = await this.processor(image, prompts);
		const generatedIds = await this.model.generate({
			...inputs,
			max_new_tokens: 80,
		});
		const generatedText = this.processor.batch_decode(generatedIds, {
			skip_special_tokens: false,
		})[0];
		const result = this.processor.post_process_generation(generatedText, task, image.size);
		return cleanAlt(result && result[task] ? result[task] : generatedText);
	}

	async process(adapter) {
		let processed = 0;

		while (true) {
			const item = await adapter.next();
			if (!item) {
				this.onProgress(processed, processed);
				this.onStatus('Finished — all eligible images have been processed.');
				return processed;
			}

			try {
				const dataUrl = await adapter.getImage(item);
				const alt = await this.describe(dataUrl);
				if (alt) {
					await adapter.saveAlt(item, alt);
				}
			} catch (error) {
				console.warn('Himalayan AI image failed:', item, error);
				if (adapter.onError) {
					await adapter.onError(item, error);
				}
			}

			processed++;
			this.onProgress(processed, processed + 1);
		}
	}
}

export function cleanAlt(value) {
	let output = String(value || '')
		.replace(/<[^>]+>/g, ' ')
		.replace(/[\r\n\t]+/g, ' ')
		.replace(/^(?:alt\s*text\s*:\s*)/i, '')
		.replace(/^(?:the\s+image\s+(?:shows|depicts)\s+)/i, '')
		.trim();

	if (!output) {
		return '';
	}

	if (output.length > 125) {
		output = output.slice(0, 122).replace(/\s+\S*$/, '') + '...';
	}

	return output;
}
