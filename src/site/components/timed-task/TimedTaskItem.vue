<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	One question of a timed task (portal-take-assessment), by type: radio
	buttons for a choice, a select for an inline choice, an input for a text
	entry, a textarea for extended text, a list with move buttons for an order,
	a select per source for a match, and a text answer for any other type. The
	leaf app sends the prompt as plain text and the options in presentation
	order; nothing here can reveal an answer.
-->
<template>
	<fieldset
		v-if="as === 'choice'"
		class="utrecht-form-fieldset pq-timedtask-item"
		:disabled="disabled">
		<legend class="utrecht-form-fieldset__legend">{{ item.prompt }}</legend>
		<label
			v-for="choice in item.choices"
			:key="choice.id"
			class="utrecht-form-label utrecht-form-label--radio pq-timedtask-choice">
			<input
				type="radio"
				class="utrecht-radio-button"
				:name="id"
				:value="choice.id"
				:checked="value === choice.id"
				@change="$emit('change', choice.id)" />
			{{ choice.label }}
		</label>
	</fieldset>

	<div
		v-else-if="as === 'inlineChoice'"
		class="utrecht-form-field pq-timedtask-item">
		<label class="utrecht-form-label" :for="id">{{ item.prompt }}</label>
		<select
			:id="id"
			class="utrecht-select"
			:value="value || ''"
			:disabled="disabled"
			@change="$emit('change', $event.target.value)">
			<option value="">{{ t('Choose') }}</option>
			<option
				v-for="choice in item.choices"
				:key="choice.id"
				:value="choice.id">
				{{ choice.label }}
			</option>
		</select>
	</div>

	<div v-else-if="as === 'textEntry'" class="utrecht-form-field pq-timedtask-item">
		<label class="utrecht-form-label" :for="id">{{ item.prompt }}</label>
		<input
			:id="id"
			type="text"
			class="utrecht-textbox"
			:value="value || ''"
			:disabled="disabled"
			@input="$emit('change', $event.target.value)" />
	</div>

	<div v-else-if="as === 'order'" class="utrecht-form-field pq-timedtask-item">
		<p :id="`${id}-prompt`" class="utrecht-paragraph">{{ item.prompt }}</p>
		<ol :aria-labelledby="`${id}-prompt`" class="pq-timedtask-order">
			<li v-for="(optionId, index) in order" :key="optionId">
				<span>{{ labelOf(optionId) }}</span>
				<button
					type="button"
					class="utrecht-button utrecht-button--subtle"
					:disabled="disabled || index === 0"
					@click="$emit('change', moved(index, -1))">
					{{ t('Move up') }}
				</button>
				<button
					type="button"
					class="utrecht-button utrecht-button--subtle"
					:disabled="disabled || index === order.length - 1"
					@click="$emit('change', moved(index, 1))">
					{{ t('Move down') }}
				</button>
			</li>
		</ol>
	</div>

	<fieldset
		v-else-if="as === 'match'"
		class="utrecht-form-fieldset pq-timedtask-item"
		:disabled="disabled">
		<legend class="utrecht-form-fieldset__legend">{{ item.prompt }}</legend>
		<div
			v-for="source in item.sources"
			:key="source.id"
			class="utrecht-form-field pq-timedtask-match">
			<label class="utrecht-form-label" :for="`${id}-${source.id}`">{{
				source.label
			}}</label>
			<select
				:id="`${id}-${source.id}`"
				class="utrecht-select"
				:value="pairs[source.id] || ''"
				@change="
					$emit('change', { ...pairs, [source.id]: $event.target.value })
				">
				<option value="">{{ t('Choose') }}</option>
				<option
					v-for="target in item.targets"
					:key="target.id"
					:value="target.id">
					{{ target.label }}
				</option>
			</select>
		</div>
	</fieldset>

	<div v-else class="utrecht-form-field pq-timedtask-item">
		<label class="utrecht-form-label" :for="id">{{ item.prompt }}</label>
		<textarea
			:id="id"
			class="utrecht-textarea"
			:rows="as === 'extendedText' ? 10 : 4"
			:value="typeof value === 'string' ? value : ''"
			:placeholder="t('Type your answer')"
			:disabled="disabled"
			:aria-describedby="as === 'extendedText' ? `${id}-help` : undefined"
			@input="$emit('change', $event.target.value)" />
		<p
			v-if="as === 'extendedText'"
			:id="`${id}-help`"
			class="utrecht-paragraph pq-help">
			{{ t('Your teacher marks this question.') }}
		</p>
	</div>
</template>

<script>
import { move, renderAs } from '../../../shared/timedTask.js'

/**
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-pupil-must-be-able-to-take-a-timed-task-req-srp-036
 */
export default {
	name: 'TimedTaskItem',

	props: {
		/** The normalised item. */
		item: { type: Object, required: true },
		/** The current answer. */
		value: { type: [String, Array, Object], default: '' },
		/** The translator. */
		t: { type: Function, required: true },
		/** Whether answering is closed. */
		disabled: { type: Boolean, default: false },
	},

	emits: ['change'],

	computed: {
		/**
		 * @return {string} The element ids' stem.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-pupil-must-be-able-to-take-a-timed-task-req-srp-036
		 */
		id() {
			return `tt-${this.item.itemId}`
		},

		/**
		 * @return {string} How the item renders.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-pupil-must-be-able-to-take-a-timed-task-req-srp-036
		 */
		as() {
			return renderAs(this.item)
		},

		/**
		 * @return {Array<string>} The current order.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-pupil-must-be-able-to-take-a-timed-task-req-srp-036
		 */
		order() {
			return Array.isArray(this.value) ? this.value : []
		},

		/**
		 * @return {Record<string, string>} The current pairs.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-pupil-must-be-able-to-take-a-timed-task-req-srp-036
		 */
		pairs() {
			return this.value
				&& typeof this.value === 'object'
				&& !Array.isArray(this.value)
				? this.value
				: {}
		},
	},

	methods: {
		/**
		 * @param {string} optionId An option.
		 * @return {string} Its label.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-pupil-must-be-able-to-take-a-timed-task-req-srp-036
		 */
		labelOf(optionId) {
			return (
				(this.item.choices || []).find((c) => c.id === optionId)?.label
				|| optionId
			)
		},

		/**
		 * @param {number} index The option's place.
		 * @param {number} step -1 up, 1 down.
		 * @return {Array<string>} The new order.
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-pupil-must-be-able-to-take-a-timed-task-req-srp-036
		 */
		moved(index, step) {
			return move(this.order, index, step)
		},
	},
}
</script>
