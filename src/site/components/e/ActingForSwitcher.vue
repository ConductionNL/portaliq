<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	"Acting for" for the site header (cases-my-cases-page REQ-CMC-004), ported
	from src/portal/components/ActingForSwitcher.jsx. The person acts for
	themself or under one of the mandates they hold; the choice applies to "My
	cases" and every case screen for the rest of the session. Renders nothing
	for a person who holds no mandate.

	By default it reads and writes the shared store in ./actingFor.js, so the
	header can mount it with only `t`. `mandates` and `value` override the store
	for a host that keeps its own state; `change` is emitted either way.
-->
<template>
	<span v-if="held.length > 0" class="pq-acting-for" data-testid="acting-for">
		<label for="pq-acting-for" class="utrecht-form-label">{{
			t('Acting for')
		}}</label>
		<select
			id="pq-acting-for"
			class="utrecht-select"
			:value="current"
			@change="choose($event.target.value)">
			<option
				v-for="option in options"
				:key="option.id"
				:value="option.id"
				:selected="option.id === current">
				{{ option.label }}
			</option>
		</select>
	</span>
</template>

<script>
import { actingForOptions } from '../../../shared/myCases.js'
import { actingFor, chooseActingFor } from './actingFor.js'

export default {
	name: 'ActingForSwitcher',

	props: {
		/** The translator `t(key, vars)`. */
		t: { type: Function, required: true },
		/** The mandates held; the shared store's when null. */
		mandates: { type: Array, default: null },
		/** The current choice; the shared store's when empty. */
		value: { type: String, default: '' },
	},

	emits: ['change'],

	computed: {
		held() {
			const list = this.mandates ?? actingFor.mandates
			return Array.isArray(list) ? list : []
		},

		options() {
			return actingForOptions(this.held, this.t)
		},

		current() {
			return this.value || actingFor.id
		},
	},

	methods: {
		/**
		 * Act under the chosen mandate, or as yourself, for the rest of the session.
		 *
		 * @param {string} id The mandate id, or `self`.
		 * @return {void}
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-be-able-to-act-for-someone-else-req-srp-041
		 */
		choose(id) {
			chooseActingFor(id)
			this.$emit('change', id)
		},
	},
}
</script>

<style scoped>
.pq-acting-for {
	display: inline-flex;
	align-items: center;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}
</style>
