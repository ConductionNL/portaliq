<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<div class="pq-file-upload" data-testid="file-upload">
		<input
			:id="id"
			ref="input"
			:key="inputKey"
			type="file"
			class="pq-file-upload__input"
			:multiple="multiple"
			:accept="accept || undefined"
			:disabled="disabled"
			:aria-required="required ? 'true' : undefined"
			:aria-invalid="invalid ? 'true' : undefined"
			:aria-labelledby="`${labelledBy} ${id}-button`.trim()"
			:aria-describedby="describedByAll"
			@change="pick($event.target.files)" />
		<label
			:id="`${id}-button`"
			:for="id"
			class="utrecht-button utrecht-button--secondary-action pq-file-upload__button"
			data-testid="file-upload-button">
			{{ buttonLabel }}
		</label>
		<p :id="`${id}-limit`" class="utrecht-form-field-description">
			{{ limitText }}
		</p>
		<ul
			:id="`${id}-picked`"
			class="pq-file-upload__list"
			aria-live="polite"
			data-testid="file-upload-list">
			<li
				v-for="(file, index) in files"
				:key="`${file.name}-${index}`"
				class="pq-file-upload__item">
				<span class="pq-file-upload__name">{{ file.name }}</span>
				<button
					type="button"
					class="utrecht-button utrecht-button--subtle pq-file-upload__remove"
					:disabled="disabled"
					:data-testid="`file-upload-remove-${index}`"
					@click="remove(index)">
					{{ removeText(file) }}
				</button>
			</li>
		</ul>
	</div>
</template>

<script>
import '@utrecht/button-css/dist/index.css'

/**
 * The NL Design System "File Input": a real file input, visually hidden but
 * reachable by keyboard, behind a label styled as a secondary button. The
 * size limit stands under it, and each chosen file is listed by name with a
 * button that removes it. The list is a polite live region, so a screen
 * reader hears the chosen file. After a removal, focus returns to the input.
 *
 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-file-field-on-an-action-must-look-like-a-button-and-list-the-chosen-file-req-smf-004
 */
export default {
	name: 'FileUpload',

	props: {
		/** The input's id. */
		id: { type: String, required: true },
		/** The chosen files. */
		files: { type: Array, default: () => [] },
		/** Whether several files may be chosen. */
		multiple: { type: Boolean, default: false },
		/** The accepted types, comma separated, '' for any. */
		accept: { type: String, default: '' },
		/** Whether a file is required. */
		required: { type: Boolean, default: false },
		/** Whether the field has an error. */
		invalid: { type: Boolean, default: false },
		/** Whether the input is disabled. */
		disabled: { type: Boolean, default: false },
		/** The id of the field's own label, read before the button's words. */
		labelledBy: { type: String, default: '' },
		/** The ids of the field's description and error. */
		describedBy: { type: String, default: '' },
		/** The button's words. */
		buttonLabel: { type: String, default: 'Bestand of foto kiezen' },
		/** The size limit, as a sentence. */
		limitText: { type: String, default: '' },
		/** The remove button's words, with `{file}`. */
		removeLabel: { type: String, default: '{file} verwijderen' },
		/** Bumped by the form to clear the input after a send. */
		fileKey: { type: Number, default: 0 },
	},

	emits: ['pick'],

	data() {
		return { own: 0 }
	},

	computed: {
		/**
		 * A key that renews the input after a send or a removal, so the same
		 * file can be chosen again.
		 *
		 * @return {string} The key.
		 */
		inputKey() {
			return `${this.fileKey}-${this.own}`
		},

		/**
		 * The input's descriptions: the field's, the limit and the list.
		 *
		 * @return {string} The ids.
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-file-field-on-an-action-must-look-like-a-button-and-list-the-chosen-file-req-smf-004
		 */
		describedByAll() {
			const ids = [this.describedBy, `${this.id}-limit`]
			if (this.files.length > 0) {
				ids.push(`${this.id}-picked`)
			}
			return ids.filter((part) => part !== '').join(' ')
		},
	},

	methods: {
		/**
		 * Hand the chosen files to the form.
		 *
		 * @param {FileList|Array|null} picked The chosen files.
		 * @return {void}
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-file-field-on-an-action-must-look-like-a-button-and-list-the-chosen-file-req-smf-004
		 */
		pick(picked) {
			this.$emit('pick', Array.from(picked || []))
		},

		/**
		 * Remove one chosen file and put focus back on the input.
		 *
		 * @param {number} index The file's place in the list.
		 * @return {void}
		 *
		 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-file-field-on-an-action-must-look-like-a-button-and-list-the-chosen-file-req-smf-004
		 */
		remove(index) {
			this.$emit(
				'pick',
				this.files.filter((file, at) => at !== index),
			)
			this.own++
			this.$nextTick(() => {
				if (this.$refs.input) {
					this.$refs.input.focus()
				}
			})
		},

		/**
		 * The remove button's words for one file.
		 *
		 * @param {object} file The file.
		 * @return {string} The words.
		 */
		removeText(file) {
			return this.removeLabel.split('{file}').join(file.name)
		},
	},
}
</script>

<style scoped>
.pq-file-upload {
	display: flex;
	flex-direction: column;
	gap: var(--utrecht-space-block-xs, 0.5rem);
	align-items: flex-start;
}

/* Visually hidden, still focusable and announced. */
.pq-file-upload__input {
	block-size: 1px;
	clip-path: inset(50%);
	inline-size: 1px;
	overflow: hidden;
	position: absolute;
	white-space: nowrap;
}

.pq-file-upload__button {
	cursor: pointer;
}

.pq-file-upload__input:focus-visible + .pq-file-upload__button {
	outline: var(--utrecht-focus-outline-width, 2px)
		var(--utrecht-focus-outline-style, solid)
		var(--utrecht-focus-outline-color, currentColor);
	outline-offset: 2px;
}

.pq-file-upload__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.pq-file-upload__item {
	align-items: center;
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}

.pq-file-upload__name {
	font-weight: var(--utrecht-typography-weight-scale-bold, bold);
}
</style>
