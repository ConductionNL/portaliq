<!--
  SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  SPDX-License-Identifier: EUPL-1.2

  The text of a "Tekst" block, with a toolbar so an editor needs no markdown.

  The buttons write markdown into the textarea, because markdown is what the
  block stores (`props.markdown`) and what every existing page holds. The
  textarea keeps `data-testid="designer-field-markdown"`: the film recorder
  types into it.
-->
<template>
	<div class="markdown-field">
		<label :for="id">{{ t('portaliq', 'Text') }}</label>

		<div
			ref="toolbar"
			class="markdown-field__toolbar"
			role="toolbar"
			:aria-label="t('portaliq', 'Text formatting')"
			:aria-controls="id"
			data-testid="designer-text-toolbar">
			<button
				v-for="(action, index) in actions"
				:key="action.name"
				:tabindex="tabindexes[index]"
				type="button"
				class="markdown-field__button"
				:class="[`markdown-field__button--${action.name}`]"
				:aria-label="action.label"
				:title="action.label"
				:data-testid="`designer-text-${action.name}`"
				@mousedown.prevent
				@click="onAction(action.name, index)"
				@focus="current = index"
				@keydown="onToolbarKeydown($event, index)">
				{{ action.text }}
			</button>
		</div>

		<div
			v-if="linkOpen"
			class="markdown-field__link"
			data-testid="designer-text-link-form">
			<label :for="`${id}-link`">{{ t('portaliq', 'Web address') }}</label>
			<div class="markdown-field__link-row">
				<input
					:id="`${id}-link`"
					ref="url"
					v-model="linkUrl"
					type="url"
					inputmode="url"
					class="markdown-field__input"
					placeholder="https://"
					data-testid="designer-text-link-url"
					@keydown.enter.prevent="insertLink"
					@keydown.esc.prevent="closeLink" />
				<button
					type="button"
					class="markdown-field__button"
					data-testid="designer-text-link-insert"
					@click="insertLink">
					{{ t('portaliq', 'Insert link') }}
				</button>
				<button
					type="button"
					class="markdown-field__button"
					data-testid="designer-text-link-cancel"
					@click="closeLink">
					{{ t('portaliq', 'Cancel') }}
				</button>
			</div>
		</div>

		<textarea
			:id="id"
			ref="textarea"
			class="markdown-field__input"
			rows="8"
			data-testid="designer-field-markdown"
			:value="modelValue"
			@input="$emit('update:modelValue', $event.target.value)"
			@keydown="onKeydown" />
	</div>
</template>

<script>
import { translate } from '@nextcloud/l10n'
import {
	applyBold,
	applyHeading,
	applyItalic,
	applyLink,
	applyList,
	rovingTabindexes,
	shortcutFor,
	toolbarIndexFor,
} from './markdownToolbar.js'

/**
 * The toolbar's transformations by button name. Link is not here: it asks
 * for an address first.
 *
 * @type {Record<string, import('./markdownToolbar.js').Transform>}
 */
const TRANSFORMS = {
	heading: applyHeading,
	bold: applyBold,
	italic: applyItalic,
	list: applyList,
}

export default {
	name: 'MarkdownField',

	props: {
		/** The markdown the block stores. */
		modelValue: {
			type: String,
			default: '',
		},

		/** The textarea's id, which the label points at. */
		id: {
			type: String,
			required: true,
		},
	},

	emits: ['update:modelValue'],

	data() {
		return {
			linkOpen: false,
			linkUrl: '',
			// The selection the link form was opened on: the textarea loses
			// focus to the address input, and with it what was selected.
			linkSelection: null,
			// The toolbar button that is the toolbar's one Tab stop: the
			// last one used or focused, the first one to start with.
			current: 0,
		}
	},

	computed: {
		/**
		 * The roving tabindex: only the current button is a Tab stop.
		 *
		 * @return {Array<number>} One tabindex per button.
		 *
		 * @spec openspec/changes/editor-text-toolbar/specs/portal-page-designer/spec.md#requirement-an-editor-must-be-able-to-shape-a-text-block-without-knowing-markdown
		 */
		tabindexes() {
			return rovingTabindexes(this.current, this.actions.length)
		},

		/**
		 * The buttons, in the order an editor reads them.
		 *
		 * @return {Array<{name: string, text: string, label: string}>} The buttons.
		 *
		 * @spec openspec/changes/editor-text-toolbar/specs/portal-page-designer/spec.md#requirement-an-editor-must-be-able-to-shape-a-text-block-without-knowing-markdown
		 */
		actions() {
			const mac =
				typeof navigator !== 'undefined'
				&& /Mac|iPhone|iPad/.test(navigator.platform || '')
			const mod = mac ? 'Cmd' : 'Ctrl'
			return [
				{
					name: 'heading',
					text: this.t('portaliq', 'Heading'),
					label: this.t('portaliq', 'Heading'),
				},
				{
					name: 'bold',
					text: this.t('portaliq', 'Bold'),
					label: this.t('portaliq', 'Bold ({shortcut})', {
						shortcut: `${mod}+B`,
					}),
				},
				{
					name: 'italic',
					text: this.t('portaliq', 'Italic'),
					label: this.t('portaliq', 'Italic ({shortcut})', {
						shortcut: `${mod}+I`,
					}),
				},
				{
					name: 'list',
					text: this.t('portaliq', 'List'),
					label: this.t('portaliq', 'List'),
				},
				{
					name: 'link',
					text: this.t('portaliq', 'Link'),
					label: this.t('portaliq', 'Link'),
				},
			]
		},
	},

	methods: {
		/**
		 * Arrow keys, Home and End move the focus between the buttons.
		 *
		 * @param {KeyboardEvent} event The key.
		 * @param {number} index The button that has focus.
		 * @return {void}
		 *
		 * @spec openspec/changes/editor-text-toolbar/specs/portal-page-designer/spec.md#requirement-an-editor-must-be-able-to-shape-a-text-block-without-knowing-markdown
		 */
		onToolbarKeydown(event, index) {
			const next = toolbarIndexFor(index, event.key, this.actions.length)
			if (next === null) {
				return
			}
			event.preventDefault()
			this.current = next
			// Read in document order: a ref array inside v-for is not
			// guaranteed to follow the order of the list in Vue 3.
			const buttons = this.$refs.toolbar?.querySelectorAll('button') || []
			buttons[next]?.focus()
		},

		/**
		 * Translate.
		 *
		 * @param {string} app The app id.
		 * @param {string} text The English source string.
		 * @param {object} [vars] Placeholders.
		 * @return {string} The translation.
		 *
		 * @spec openspec/changes/editor-text-toolbar/specs/portal-page-designer/spec.md#requirement-an-editor-must-be-able-to-shape-a-text-block-without-knowing-markdown
		 */
		t(app, text, vars) {
			return translate(app, text, vars)
		},

		/**
		 * The textarea's value and selection right now.
		 *
		 * @return {{value: string, start: number, end: number}} The selection.
		 *
		 * @spec openspec/changes/editor-text-toolbar/specs/portal-page-designer/spec.md#requirement-an-editor-must-be-able-to-shape-a-text-block-without-knowing-markdown
		 */
		currentSelection() {
			const area = this.$refs.textarea
			return {
				value: area ? area.value : this.modelValue,
				start: area ? area.selectionStart : 0,
				end: area ? area.selectionEnd : 0,
			}
		},

		/**
		 * Write a transformation's result and put the selection back.
		 *
		 * @param {{value: string, start: number, end: number}} result The new text and selection.
		 * @return {void}
		 *
		 * @spec openspec/changes/editor-text-toolbar/specs/portal-page-designer/spec.md#requirement-an-editor-must-be-able-to-shape-a-text-block-without-knowing-markdown
		 */
		commit(result) {
			if (result.value !== this.modelValue) {
				this.$emit('update:modelValue', result.value)
			}
			this.$nextTick(() => {
				const area = this.$refs.textarea
				if (!area) {
					return
				}
				// The parent re-renders the value; set it here too so the
				// selection lands on the new text even before it does.
				if (area.value !== result.value) {
					area.value = result.value
				}
				area.focus()
				area.setSelectionRange(result.start, result.end)
			})
		},

		/**
		 * Run one toolbar button.
		 *
		 * @param {string} name The button.
		 * @param {number} [index] Its place in the toolbar, which becomes the Tab stop.
		 * @return {void}
		 *
		 * @spec openspec/changes/editor-text-toolbar/specs/portal-page-designer/spec.md#requirement-an-editor-must-be-able-to-shape-a-text-block-without-knowing-markdown
		 */
		onAction(name, index) {
			if (Number.isInteger(index)) {
				this.current = index
			}
			if (name === 'link') {
				this.openLink()
				return
			}
			this.commit(TRANSFORMS[name](this.currentSelection()))
		},

		/**
		 * Ctrl/Cmd+B and Ctrl/Cmd+I in the textarea.
		 *
		 * @param {KeyboardEvent} event The key.
		 * @return {void}
		 *
		 * @spec openspec/changes/editor-text-toolbar/specs/portal-page-designer/spec.md#requirement-an-editor-must-be-able-to-shape-a-text-block-without-knowing-markdown
		 */
		onKeydown(event) {
			const transform = shortcutFor(event)
			if (!transform) {
				return
			}
			event.preventDefault()
			this.commit(transform(this.currentSelection()))
		},

		/**
		 * Ask for the link's address, inline under the toolbar.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/editor-text-toolbar/specs/portal-page-designer/spec.md#requirement-an-editor-must-be-able-to-shape-a-text-block-without-knowing-markdown
		 */
		openLink() {
			this.linkSelection = this.currentSelection()
			this.linkUrl = ''
			this.linkOpen = true
			this.$nextTick(() => this.$refs.url?.focus())
		},

		/**
		 * Write the link and go back to the text.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/editor-text-toolbar/specs/portal-page-designer/spec.md#requirement-an-editor-must-be-able-to-shape-a-text-block-without-knowing-markdown
		 */
		insertLink() {
			const selection = this.linkSelection || this.currentSelection()
			const result = applyLink(
				{ ...selection, value: this.modelValue },
				this.linkUrl,
			)
			this.linkOpen = false
			this.linkSelection = null
			this.commit(result)
		},

		/**
		 * Close the address form and go back to the text, selection kept.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/editor-text-toolbar/specs/portal-page-designer/spec.md#requirement-an-editor-must-be-able-to-shape-a-text-block-without-knowing-markdown
		 */
		closeLink() {
			const selection = this.linkSelection
			this.linkOpen = false
			this.linkSelection = null
			this.commit(selection || this.currentSelection())
		},
	},
}
</script>

<style scoped>
.markdown-field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}

.markdown-field__toolbar {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
}

.markdown-field__button {
	min-height: 32px;
	padding: 2px 10px;
	border: 1px solid var(--color-border-maxcontrast, var(--color-border));
	border-radius: var(--border-radius);
	background: var(--color-main-background);
	color: var(--color-main-text);
	font: inherit;
	cursor: pointer;
}

.markdown-field__button:hover {
	background: var(--color-background-hover);
}

.markdown-field__button:focus-visible {
	outline: 2px solid var(--color-primary-element);
	outline-offset: 2px;
}

.markdown-field__button--bold {
	font-weight: bold;
}

.markdown-field__button--italic {
	font-style: italic;
}

.markdown-field__button--link {
	text-decoration: underline;
}

.markdown-field__link {
	display: flex;
	flex-direction: column;
	gap: 4px;
	padding: 8px;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius);
	background: var(--color-background-hover);
}

.markdown-field__link-row {
	display: flex;
	flex-wrap: wrap;
	gap: 4px;
}

.markdown-field__link-row .markdown-field__input {
	flex: 1 1 12em;
}

.markdown-field__input {
	width: 100%;
}
</style>
