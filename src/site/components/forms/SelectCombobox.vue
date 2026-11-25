<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A SEARCHABLE SELECT (site-nlds-widget-palette D1 row 82, Select Combobox):
	the WAI-ARIA combobox pattern with a list popup, on Utrecht's combobox and
	listbox CSS. The visitor types to narrow the options, moves with the arrow
	keys, picks with Enter or a click, and closes with Escape; a polite live
	region says how many options remain. The value is the chosen option, or the
	typed text when it names none, which FormBlock then refuses in words.
	@spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
-->
<template>
	<div class="utrecht-combobox pq-combobox">
		<input
			:id="id"
			v-model="query"
			class="utrecht-textbox utrecht-textbox--html-input"
			:class="{ 'utrecht-textbox--invalid': invalid }"
			type="text"
			role="combobox"
			autocomplete="off"
			aria-autocomplete="list"
			:aria-expanded="open ? 'true' : 'false'"
			:aria-controls="listId"
			:aria-activedescendant="active >= 0 ? optionId(active) : undefined"
			:aria-labelledby="labelledby || undefined"
			:aria-describedby="describedby || undefined"
			:aria-required="required ? 'true' : undefined"
			:aria-invalid="invalid ? 'true' : undefined"
			:data-testid="testid || undefined"
			@input="typed"
			@focus="open = true"
			@blur="settle"
			@keydown="key" />
		<div
			:id="listId"
			role="listbox"
			:aria-labelledby="labelledby || undefined"
			class="utrecht-combobox__popover utrecht-combobox__popover--block-end utrecht-listbox utrecht-listbox--html-div"
			:class="{ 'utrecht-combobox__popover--hidden': !open || shown.length === 0 }">
			<ul class="utrecht-listbox__list">
				<li
					v-for="(option, index) in shown"
					:id="optionId(index)"
					:key="option"
					role="option"
					class="utrecht-listbox__option utrecht-listbox__option--html-li"
					:class="{
						'utrecht-listbox__option--active': index === active,
						'utrecht-listbox__option--selected': option === modelValue,
					}"
					:aria-selected="option === modelValue ? 'true' : 'false'"
					:data-testid="testid ? `${testid}-option-${index}` : undefined"
					@mousedown.prevent="pick(option)">
					{{ labelOf(option) }}
				</li>
			</ul>
		</div>
		<span class="pq-combobox__status" role="status" aria-live="polite">{{ status }}</span>
	</div>
</template>

<script>
import { filterOptions, moveActive, optionNamed } from '../../lib/comboboxOptions.js'

import '@utrecht/combobox-css/dist/index.css'
import '@utrecht/listbox-css/dist/index.css'
import '@utrecht/textbox-css/dist/index.css'

export default {
	name: 'SelectCombobox',

	props: {
		/** The input's id, which the field's label points at. */
		id: { type: String, required: true },
		/** The chosen option, or the typed text that names none. */
		modelValue: { type: String, default: '' },
		/** The declared options. */
		options: { type: Array, default: () => [] },
		/** The words a visitor sees for one option. */
		labelOf: { type: Function, default: (option) => option },
		required: { type: Boolean, default: false },
		invalid: { type: Boolean, default: false },
		labelledby: { type: String, default: '' },
		describedby: { type: String, default: '' },
		testid: { type: String, default: '' },
	},

	emits: ['update:modelValue'],

	data() {
		return {
			query: this.modelValue ? this.labelOf(this.modelValue) : '',
			open: false,
			active: -1,
		}
	},

	computed: {
		/**
		 * @return {string} The popup's id.
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
		 */
		listId() {
			return `${this.id}-listbox`
		},

		/**
		 * @return {Array<string>} The options the typed text leaves.
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
		 */
		shown() {
			return filterOptions(this.options, this.labelOf, this.query)
		},

		/**
		 * @return {string} The live region's words while the popup is open.
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
		 */
		status() {
			if (!this.open) {
				return ''
			}
			const count = this.shown.length
			if (count === 0) {
				return 'Geen keuzes gevonden.'
			}
			return count === 1 ? '1 keuze beschikbaar.' : `${count} keuzes beschikbaar.`
		},
	},

	watch: {
		/**
		 * Follow a value set from outside: a chosen option shows its words, a
		 * cleared form an empty box.
		 *
		 * @param {string} value The new value.
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
		 */
		modelValue(value) {
			const named = value ? this.labelOf(value) : ''
			if (fold(named) !== fold(this.query) && this.options.includes(value)) {
				this.query = named
			} else if (value === '') {
				this.query = ''
			}
		},
	},

	methods: {
		/**
		 * @param {number} index A shown option's position.
		 * @return {string} Its element id.
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
		 */
		optionId(index) {
			return `${this.id}-option-${index}`
		},

		/**
		 * The visitor typed: open the popup, drop the active option, and send
		 * the option the text names or else the text itself.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
		 */
		typed() {
			this.open = true
			this.active = -1
			const named = optionNamed(this.options, this.labelOf, this.query)
			this.$emit('update:modelValue', named ?? this.query.trim())
		},

		/**
		 * Choose an option and close the popup.
		 *
		 * @param {string} option The option.
		 * @return {void}
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
		 */
		pick(option) {
			this.query = this.labelOf(option)
			this.open = false
			this.active = -1
			this.$emit('update:modelValue', option)
		},

		/**
		 * Leaving the box closes the popup; the value stays what typing sent.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
		 */
		settle() {
			this.open = false
			this.active = -1
		},

		/**
		 * Arrow keys move, Enter picks the active option, Escape closes.
		 *
		 * @param {KeyboardEvent} event The key.
		 * @return {void}
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portal-page-designer/spec.md#requirement-form-fields-must-be-added-inside-a-form-widget-req-snw-003
		 */
		key(event) {
			if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
				event.preventDefault()
				this.open = true
				this.active = moveActive(this.active, this.shown.length, event.key === 'ArrowDown' ? 1 : -1)
			} else if (event.key === 'Enter' && this.open && this.active >= 0) {
				event.preventDefault()
				this.pick(this.shown[this.active])
			} else if (event.key === 'Escape' && this.open) {
				event.preventDefault()
				this.settle()
			}
		},
	},
}

/**
 * @param {string} text A text.
 * @return {string} It trimmed and in lower case.
 */
function fold(text) {
	return String(text ?? '').trim().toLowerCase()
}
</script>

<style scoped>
.pq-combobox__status {
	position: absolute;
	inline-size: 1px;
	block-size: 1px;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
}

.pq-combobox .utrecht-combobox__popover {
	inline-size: 100%;
	max-block-size: 16rem;
}
</style>
