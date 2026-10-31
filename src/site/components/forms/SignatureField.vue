<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A signature field (resident-identity-in-forms, board FormulierKaart). The
	resident draws in a box with mouse, finger or pen, or types their name
	instead: dragging is not possible for everyone, so the typed name is drawn
	as the signature image in a plain font (WCAG 2.2 SC 2.5.7). The value is a
	PNG data address, or '' while nothing is drawn.
-->
<template>
	<div class="pq-signature" :data-testid="testid">
		<template v-if="mode === 'draw'">
			<p class="utrecht-paragraph" data-testid="signature-hint">
				{{ words.drawHint }}
			</p>
			<canvas
				ref="canvas"
				class="pq-signature__box"
				:class="{ 'pq-signature__box--invalid': invalid }"
				width="600"
				height="200"
				role="img"
				:aria-label="words.boxLabel"
				data-testid="signature-canvas"
				@pointerdown="start"
				@pointermove="move"
				@pointerup="stop"
				@pointerleave="stop"
				@pointercancel="stop" />
			<div class="pq-signature__actions">
				<button
					type="button"
					class="utrecht-button utrecht-button--secondary-action"
					data-testid="signature-clear"
					@click="clear">
					{{ words.drawAgain }}
				</button>
				<button
					type="button"
					class="utrecht-link utrecht-link--button pq-signature__switch"
					data-testid="signature-type"
					@click="mode = 'type'">
					{{ words.typeInstead }}
				</button>
			</div>
		</template>
		<template v-else>
			<label class="utrecht-form-label" :for="`${testid}-name`">
				{{ words.typedLabel }}
			</label>
			<input
				:id="`${testid}-name`"
				v-model="typed"
				class="utrecht-textbox"
				type="text"
				autocomplete="name"
				maxlength="80"
				data-testid="signature-name"
				@input="typedChanged" />
			<div class="pq-signature__actions">
				<button
					type="button"
					class="utrecht-link utrecht-link--button pq-signature__switch"
					data-testid="signature-draw"
					@click="switchToDraw">
					{{ words.drawInstead }}
				</button>
			</div>
		</template>
	</div>
</template>

<script>
import { pageLocale } from '../../pages/inbox/translate.js'
import { identityWords, typedSignatureName } from './identityWords.js'

/**
 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t02
 */
export default {
	name: 'SignatureField',

	props: {
		/** The signature as a PNG data address, or '' when empty. */
		modelValue: { type: String, default: '' },
		/** Whether the field shows an error. */
		invalid: { type: Boolean, default: false },
		/** The test id of the field. */
		testid: { type: String, default: 'signature-field' },
		/** The reader's locale; the page language when empty. */
		locale: { type: String, default: '' },
	},

	emits: ['update:modelValue'],

	data() {
		return { mode: 'draw', typed: '', drawing: false, inked: false }
	},

	computed: {
		/**
		 * @return {Record<string, string>} The words in the reader's language.
		 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t02
		 */
		words() {
			return identityWords(pageLocale(this.locale))
		},
	},

	methods: {
		/**
		 * The canvas's context, set up for a pen line.
		 *
		 * @return {CanvasRenderingContext2D|null} The context, or null without a canvas.
		 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t02
		 */
		pen() {
			const context = this.$refs.canvas?.getContext?.('2d') ?? null
			if (context) {
				context.lineWidth = 3
				context.lineCap = 'round'
				context.strokeStyle = '#000'
			}
			return context
		},

		/**
		 * The point a pointer event touches, in canvas pixels.
		 *
		 * @param {PointerEvent} event The event.
		 * @return {{x: number, y: number}} The point.
		 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t02
		 */
		point(event) {
			const box = this.$refs.canvas.getBoundingClientRect()
			const scale = box.width > 0 ? this.$refs.canvas.width / box.width : 1
			return {
				x: (event.clientX - box.left) * scale,
				y: (event.clientY - box.top) * scale,
			}
		},

		/**
		 * Start a stroke.
		 *
		 * @param {PointerEvent} event The event.
		 * @return {void}
		 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t02
		 */
		start(event) {
			const context = this.pen()
			if (!context) {
				return
			}
			this.drawing = true
			const at = this.point(event)
			context.beginPath()
			context.moveTo(at.x, at.y)
			context.lineTo(at.x + 0.1, at.y + 0.1)
			context.stroke()
			this.inked = true
		},

		/**
		 * Continue a stroke.
		 *
		 * @param {PointerEvent} event The event.
		 * @return {void}
		 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t02
		 */
		move(event) {
			if (!this.drawing) {
				return
			}
			const context = this.pen()
			const at = this.point(event)
			context.lineTo(at.x, at.y)
			context.stroke()
		},

		/**
		 * End a stroke and hand the picture on.
		 *
		 * @return {void}
		 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t02
		 */
		stop() {
			if (!this.drawing) {
				return
			}
			this.drawing = false
			this.$emit(
				'update:modelValue',
				this.inked ? this.$refs.canvas.toDataURL('image/png') : '',
			)
		},

		/**
		 * Wipe the box and the value.
		 *
		 * @return {void}
		 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t02
		 */
		clear() {
			const canvas = this.$refs.canvas
			canvas?.getContext?.('2d')?.clearRect(0, 0, canvas.width, canvas.height)
			this.inked = false
			this.$emit('update:modelValue', '')
		},

		/**
		 * The typed name, drawn as the signature in a plain font.
		 *
		 * @return {void}
		 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t02
		 */
		typedChanged() {
			const name = typedSignatureName(this.typed)
			if (name === '') {
				this.$emit('update:modelValue', '')
				return
			}
			const canvas = document.createElement('canvas')
			canvas.width = 600
			canvas.height = 200
			const context = canvas.getContext('2d')
			context.fillStyle = '#000'
			context.font = 'italic 56px Georgia, "Times New Roman", serif'
			context.textBaseline = 'middle'
			context.fillText(name, 20, 100, 560)
			this.$emit('update:modelValue', canvas.toDataURL('image/png'))
		},

		/**
		 * Go back to the box; a typed signature is dropped, so the value is what the box shows.
		 *
		 * @return {void}
		 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t02
		 */
		switchToDraw() {
			this.mode = 'draw'
			this.typed = ''
			this.inked = false
			this.$emit('update:modelValue', '')
		},
	},
}
</script>

<style scoped>
.pq-signature > * + * {
	margin-block-start: var(--utrecht-space-block-sm, 0.5rem);
}

.pq-signature__box {
	display: block;
	inline-size: 100%;
	max-inline-size: 600px;
	block-size: auto;
	aspect-ratio: 3 / 1;
	touch-action: none;
	cursor: crosshair;
	background: var(--utrecht-document-background-color, Canvas);
	border: var(--utrecht-border-width-sm, 1px) solid
		var(--utrecht-color-grey-80, currentcolor);
}

.pq-signature__box--invalid {
	border-color: var(--utrecht-feedback-danger-color, currentcolor);
}

.pq-signature__actions {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--utrecht-space-inline-md, 1rem);
}

.pq-signature__switch {
	background: none;
	border: 0;
	padding: 0;
	cursor: pointer;
	text-decoration: underline;
}
</style>
