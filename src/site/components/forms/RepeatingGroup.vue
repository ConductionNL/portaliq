<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A repeating group on a form (form-flow-repeating-groups-calculations-and-
	decisions REQ-FFL-001), as the Zuiddrecht board FormulierKaart draws it: one
	card per item with "Wijzigen" and "Verwijderen", and a button to add another
	that is gone once the maximum is reached. Adding or changing an item opens
	its questions inline under the list with "Opslaan" and "Annuleren". Focus
	goes to the first question, and back to the card after saving.
-->
<template>
	<div class="pq-group" :data-testid="testid">
		<ol v-if="items.length" class="pq-group__list">
			<li
				v-for="(item, index) in items"
				:key="index"
				class="pq-group__card"
				data-testid="group-card">
				<h4
					:ref="`card${index}`"
					class="utrecht-heading-4 pq-group__title"
					tabindex="-1">
					{{ titleOf(index) }}
				</h4>
				<p
					v-if="linesOf(item).first"
					class="utrecht-paragraph pq-group__line">
					{{ linesOf(item).first }}
				</p>
				<p
					v-if="linesOf(item).rest"
					class="utrecht-paragraph pq-group__line">
					{{ linesOf(item).rest }}
				</p>
				<div class="pq-group__actions">
					<button
						type="button"
						class="utrecht-button utrecht-button--subtle"
						:aria-label="`${words.change}: ${titleOf(index)}`"
						data-testid="group-change"
						@click="open(index)">
						{{ words.change }}
					</button>
					<button
						type="button"
						class="utrecht-button utrecht-button--subtle"
						:aria-label="`${words.remove}: ${titleOf(index)}`"
						data-testid="group-remove"
						@click="remove(index)">
						{{ words.remove }}
					</button>
				</div>
			</li>
		</ol>

		<div
			v-if="editing !== null"
			class="pq-group__form"
			role="group"
			:aria-label="editing < 0 ? titleOf(items.length) : titleOf(editing)"
			data-testid="group-form">
			<div
				v-for="(sub, at) in subFields"
				:key="sub.name"
				class="pq-group__question">
				<label class="utrecht-form-label" :for="`${id}-sub-${sub.name}`">{{
					sub.label || sub.name
				}}</label>
				<select
					v-if="Array.isArray(sub.options) && sub.options.length"
					:id="`${id}-sub-${sub.name}`"
					:ref="at === 0 ? 'first' : undefined"
					v-model="draft[sub.name]"
					class="utrecht-select"
					:aria-invalid="problems[sub.name] ? 'true' : 'false'">
					<option value="" />
					<option
						v-for="option in sub.options"
						:key="String(option.value ?? option)"
						:value="String(option.value ?? option)">
						{{ option.label ?? option }}
					</option>
				</select>
				<textarea
					v-else-if="sub.type === 'textarea'"
					:id="`${id}-sub-${sub.name}`"
					:ref="at === 0 ? 'first' : undefined"
					v-model="draft[sub.name]"
					class="utrecht-textarea"
					:aria-invalid="problems[sub.name] ? 'true' : 'false'" />
				<input
					v-else
					:id="`${id}-sub-${sub.name}`"
					:ref="at === 0 ? 'first' : undefined"
					v-model="draft[sub.name]"
					class="utrecht-textbox"
					:type="inputType(sub)"
					:aria-invalid="problems[sub.name] ? 'true' : 'false'" />
				<p
					v-if="problems[sub.name]"
					class="utrecht-form-field-error-message"
					role="alert"
					:data-testid="`group-error-${sub.name}`">
					{{ problems[sub.name] }}
				</p>
			</div>
			<div class="pq-group__actions">
				<button
					type="button"
					class="utrecht-button utrecht-button--primary-action"
					data-testid="group-save"
					@click="save">
					{{ words.save }}
				</button>
				<button
					type="button"
					class="utrecht-button utrecht-button--secondary-action"
					data-testid="group-cancel"
					@click="cancel">
					{{ words.cancel }}
				</button>
			</div>
		</div>

		<button
			v-else-if="addable"
			:id="id"
			type="button"
			class="utrecht-button utrecht-button--secondary-action"
			:aria-invalid="invalid ? 'true' : 'false'"
			data-testid="group-add"
			@click="open(-1)">
			{{ repeat.addLabel }}
		</button>
	</div>
</template>

<script>
import {
	canAdd,
	GROUP_WORDS,
	itemErrors,
	itemLines,
	itemTitle,
	repeatOf,
	withItem,
	withoutItem,
} from './group.js'

const INPUT_TYPES = { number: 'number', email: 'email', date: 'date' }

/**
 * @spec openspec/changes/archive/2026-11-25-form-flow-repeating-groups-calculations-and-decisions/tasks.md#t02
 */
export default {
	name: 'RepeatingGroup',
	props: {
		/** The group field: `label`, `repeat`, `fields`. */
		field: { type: Object, required: true },
		/** The items, one object of answers each. */
		modelValue: { type: Array, default: () => [] },
		/** The element id of the add button, which the error summary links to. */
		id: { type: String, default: '' },
		/** Whether the group has an error, for the add button. */
		invalid: { type: Boolean, default: false },
		/** The sentences, Dutch by default. */
		words: { type: Object, default: () => GROUP_WORDS },
		/** The test id of the group. */
		testid: { type: String, default: 'group' },
	},

	emits: ['update:modelValue'],
	data() {
		return { editing: null, draft: {}, problems: {} }
	},

	computed: {
		/**
		 * @return {Array<object>} The items.
		 * @spec openspec/changes/archive/2026-11-25-form-flow-repeating-groups-calculations-and-decisions/tasks.md#t02
		 */
		items() {
			return Array.isArray(this.modelValue) ? this.modelValue : []
		},

		/**
		 * @return {object} The repeat settings.
		 * @spec openspec/changes/archive/2026-11-25-form-flow-repeating-groups-calculations-and-decisions/tasks.md#t02
		 */
		repeat() {
			return repeatOf(this.field)
		},

		/**
		 * @return {Array<object>} The sub-questions.
		 * @spec openspec/changes/archive/2026-11-25-form-flow-repeating-groups-calculations-and-decisions/tasks.md#t02
		 */
		subFields() {
			return Array.isArray(this.field.fields) ? this.field.fields : []
		},

		/**
		 * @return {boolean} False once the maximum is reached.
		 * @spec openspec/changes/archive/2026-11-25-form-flow-repeating-groups-calculations-and-decisions/tasks.md#t02
		 */
		addable() {
			return canAdd(this.field, this.items)
		},
	},

	methods: {
		/**
		 * @param {number} index The item's place.
		 * @return {string} "Bewoner 2".
		 * @spec openspec/changes/archive/2026-11-25-form-flow-repeating-groups-calculations-and-decisions/tasks.md#t02
		 */
		titleOf(index) {
			return itemTitle(this.field, index)
		},

		/**
		 * @param {object} item The item.
		 * @return {{first: string, rest: string}} What its card says.
		 * @spec openspec/changes/archive/2026-11-25-form-flow-repeating-groups-calculations-and-decisions/tasks.md#t02
		 */
		linesOf(item) {
			return itemLines(this.field, item)
		},

		/**
		 * @param {object} sub A sub-question.
		 * @return {string} The input type.
		 * @spec openspec/changes/archive/2026-11-25-form-flow-repeating-groups-calculations-and-decisions/tasks.md#t02
		 */
		inputType(sub) {
			return INPUT_TYPES[sub.type] || 'text'
		},

		/**
		 * Open the questions of one item (-1: a new one) and focus the first.
		 *
		 * @param {number} index The item's place, or -1.
		 * @return {void}
		 * @spec openspec/changes/archive/2026-11-25-form-flow-repeating-groups-calculations-and-decisions/tasks.md#t02
		 */
		open(index) {
			this.editing = index
			this.problems = {}
			this.draft = Object.fromEntries(
				this.subFields.map((sub) => [
					sub.name,
					index >= 0
						? String((this.items[index] || {})[sub.name] ?? '')
						: '',
				]),
			)
			this.$nextTick(() => {
				const first = Array.isArray(this.$refs.first)
					? this.$refs.first[0]
					: this.$refs.first
				if (first && first.focus) {
					first.focus()
				}
			})
		},

		/**
		 * Keep the item when its required questions are answered; focus its card.
		 *
		 * @return {void}
		 * @spec openspec/changes/archive/2026-11-25-form-flow-repeating-groups-calculations-and-decisions/tasks.md#t02
		 */
		save() {
			this.problems = itemErrors(this.field, this.draft, this.words.required)
			if (Object.keys(this.problems).length > 0) {
				return
			}
			const at = this.editing
			this.$emit('update:modelValue', withItem(this.items, at, this.draft))
			this.close(at < 0 ? this.items.length : at)
		},

		/**
		 * Leave the questions without keeping anything; focus the card.
		 *
		 * @return {void}
		 * @spec openspec/changes/archive/2026-11-25-form-flow-repeating-groups-calculations-and-decisions/tasks.md#t02
		 */
		cancel() {
			this.close(this.editing < 0 ? this.items.length - 1 : this.editing)
		},

		/**
		 * Take the item away; the cards are numbered from 1 again.
		 *
		 * @param {number} index The item's place.
		 * @return {void}
		 * @spec openspec/changes/archive/2026-11-25-form-flow-repeating-groups-calculations-and-decisions/tasks.md#t02
		 */
		remove(index) {
			this.$emit('update:modelValue', withoutItem(this.items, index))
			this.close(Math.max(0, index - 1))
		},

		/**
		 * Close the questions and put focus on a card.
		 *
		 * @param {number} cardIndex The card to focus.
		 * @return {void}
		 *
		 * @spec openspec/changes/archive/2026-11-25-form-flow-repeating-groups-calculations-and-decisions/tasks.md#t02
		 */
		close(cardIndex) {
			this.editing = null
			this.problems = {}
			this.$nextTick(() => {
				const card = this.$refs[`card${cardIndex}`]
				const element = Array.isArray(card) ? card[0] : card
				if (element && element.focus) {
					element.focus()
				}
			})
		},
	},
}
</script>

<style scoped>
/*
 * The cards the FormulierKaart board draws for a repeating group: one bordered
 * card per item, its title, lines and actions beside each other, the add
 * button under the list (form-flow-repeating-groups-calculations-and-decisions
 * T09, found live: the group had no styles of its own, so the cards ran
 * together and "Wijzigen" and "Verwijderen" read as one word). Tokens only.
 */
.pq-group__list {
	display: grid;
	gap: var(--utrecht-space-block-sm, 0.5rem);
	margin: 0 0 var(--utrecht-space-block-md, 1rem);
	padding: 0;
	list-style: none;
}

.pq-group__card {
	display: grid;
	grid-template-columns: 1fr auto;
	column-gap: var(--utrecht-space-inline-md, 1rem);
	align-items: center;
	padding: var(--utrecht-space-block-sm, 0.75rem) var(--utrecht-space-inline-md, 1rem);
	border: 1px solid var(--nldesign-website-card-border-color, var(--nldesign-color-border, currentcolor));
	border-radius: var(--nldesign-website-border-radius, 4px);
}

.pq-group__title,
.pq-group__line {
	grid-column: 1;
	margin: 0;
}

.pq-group__actions {
	display: flex;
	flex-wrap: wrap;
	grid-column: 2;
	grid-row: 1 / span 3;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}

.pq-group__form {
	display: grid;
	gap: var(--utrecht-space-block-sm, 0.75rem);
	margin-block-end: var(--utrecht-space-block-md, 1rem);
}
</style>
