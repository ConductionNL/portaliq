<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<section class="pq-rowaction" :aria-label="label" data-testid="rowaction-form">
		<h3 ref="heading" class="utrecht-heading-3" tabindex="-1">
			{{ label }}
		</h3>
		<SchemaForm
			:action="action"
			:api="api"
			:t="t"
			:initial="row || {}"
			:send="sendUpdate"
			@submitted="$emit('done')" />
		<div class="pq-rowaction__buttons">
			<button
				type="button"
				class="utrecht-button utrecht-button--subtle"
				data-testid="rowaction-form-close"
				@click="$emit('close')">
				{{ translate('Close') }}
			</button>
		</div>
	</section>
</template>

<script>
import SchemaForm from '../../components/c/SchemaForm.vue'
import { rowIdOf, translatorOr } from '../../components/c/forms.js'

/**
 * The step a `type: update` row action opens when it has fields to fill in
 * (a pupil's self-assessment on a work process): the action's form below the
 * table, starting from the row's own values, and a PATCH of only the answers
 * when it is sent. A transition without fields still runs at once.
 *
 * @spec openspec/changes/site-action-forms/specs/site-forms/spec.md#requirement-an-update-row-action-that-needs-input-must-open-its-form-on-the-row
 */
export default {
	name: 'RowActionForm',

	components: { SchemaForm },

	inheritAttrs: false,

	props: {
		/** The `type: update` row action. */
		action: { type: Object, required: true },
		/** The row. */
		row: { type: Object, required: true },
		/** The portal api (`updateObject`, `fetchOptions`). */
		api: { type: Object, required: true },
		/** The translator `t(key, vars)`. */
		t: { type: Function, default: null },
	},

	emits: ['done', 'close'],

	computed: {
		translate() {
			return translatorOr(this.t)
		},

		label() {
			return this.action.label || this.action.id
		},
	},

	mounted() {
		if (this.$refs.heading) {
			this.$refs.heading.focus()
		}
	},

	methods: {
		/**
		 * Write the answers onto the row through the update action.
		 *
		 * @param {object} body The typed answers.
		 * @return {Promise<object>} The result in the form's send shape.
		 *
		 * @spec openspec/changes/site-action-forms/specs/site-forms/spec.md#requirement-an-update-row-action-that-needs-input-must-open-its-form-on-the-row
		 */
		async sendUpdate(body) {
			const id = rowIdOf(this.row)
			if (id === '' || typeof this.api.updateObject !== 'function') {
				return { ok: false, status: 0 }
			}
			return (await this.api.updateObject(this.action, id, body)) || {}
		},
	},
}
</script>

<style scoped>
.pq-rowaction {
	margin-block: var(--utrecht-space-block-md, 1rem);
	padding: var(--utrecht-space-block-md, 1rem);
	border: var(--utrecht-border-width-sm, 1px) solid
		var(--utrecht-color-grey-60, currentcolor);
}

.pq-rowaction__buttons {
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}
</style>
