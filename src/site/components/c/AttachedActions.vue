<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<div
		v-if="actions.length > 0 && row && api"
		class="pq-save pq-attached"
		data-testid="attached-actions">
		<template v-if="open === null">
			<button
				v-for="entry in actions"
				:key="`${entry.app}:${entry.id}`"
				type="button"
				class="utrecht-button utrecht-button--secondary-action"
				:data-testid="`attached-action-${entry.id}`"
				@click="choose(entry)">
				{{ entry.label || entry.id }}
			</button>
		</template>

		<form
			v-else
			class="pq-save__form"
			:aria-label="open.label || open.id"
			novalidate
			@submit.prevent="submit">
			<h3 class="utrecht-heading-3">
				{{ open.label || open.id }}
			</h3>
			<SchemaField
				v-for="field in fields"
				:id="`attached-${open.id}-${field}`"
				:key="field"
				v-model="values[field]"
				:field="field"
				:label="labelOf(field)"
				:config="configOf(field)"
				input="textarea"
				:error="errors[field] || ''"
				:t="t" />
			<div class="pq-save__buttons">
				<button
					type="submit"
					class="utrecht-button utrecht-button--primary-action"
					:disabled="busy">
					{{ busy ? translate('Please wait…') : (open.submitLabel || translate('Send')) }}
				</button>
				<button
					type="button"
					class="utrecht-button utrecht-button--subtle"
					@click="open = null">
					{{ translate('Cancel') }}
				</button>
			</div>
		</form>

		<p
			class="utrecht-paragraph pq-save__status"
			role="status"
			data-testid="attached-actions-status">
			{{ message }}
		</p>
	</div>
</template>

<script>
import SchemaField from './SchemaField.vue'
import { attachedActionsOf, fieldLabel, runAttachedAction } from '../../../shared/attachedActions.js'
import { fieldConfig, fieldErrors, formFields, translatorOr } from './forms.js'

/**
 * Another app's actions on this record (the Vue port of AttachedActions.jsx):
 * on a dossier, pipelinq's question and dossiq's Woo request. One button per
 * action, in the same shape as the site's save buttons (SaveToDossier.vue,
 * SaveSearch.vue). Choosing one opens its fields; sending forwards them with
 * the record's id through the row-action route with `actionApp`, so the
 * server proves the record is the resident's first.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-another-apps-actions-must-show-on-a-record-req-srp-028
 */
export default {
	name: 'AttachedActions',

	components: { SchemaField },

	props: {
		/** The collection the record belongs to (lists `attachedActions`). */
		collection: { type: Object, required: true },
		/** The record on screen. */
		row: { type: Object, default: null },
		/** The portal api (`forwardRowAction`). */
		api: { type: Object, default: null },
		/** The translator `t(key, vars)`. */
		t: { type: Function, default: null },
	},

	data() {
		return {
			open: null,
			values: {},
			errors: {},
			busy: false,
			message: '',
		}
	},

	computed: {
		translate() {
			return translatorOr(this.t)
		},

		actions() {
			return attachedActionsOf(this.collection)
		},

		fields() {
			return this.open ? formFields(this.open) : []
		},
	},

	methods: {
		labelOf(field) {
			return fieldLabel(this.open, field)
		},

		configOf(field) {
			return fieldConfig(this.open, field)
		},

		/**
		 * Open one action's fields.
		 *
		 * @param {object} entry The attached action.
		 */
		choose(entry) {
			this.open = entry
			this.values = Object.fromEntries(formFields(entry).map((field) => [field, '']))
			this.errors = {}
			this.message = ''
		},

		/**
		 * Forward the open action with the record's id.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-another-apps-actions-must-show-on-a-record-req-srp-028
		 */
		async submit() {
			this.errors = fieldErrors(this.open, this.values, {}, this.t)
			if (Object.keys(this.errors).length > 0) {
				return
			}
			this.busy = true
			const result = await runAttachedAction(this.api, this.collection, this.row, this.open, this.values)
			this.busy = false
			const success = this.open.successMessage
			this.message = result.ok && typeof success === 'string' && success !== '' ? success : this.translate(result.messageKey)
			if (result.ok) {
				this.open = null
				this.values = {}
			}
		},
	},
}
</script>

<style scoped>
.pq-attached {
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
	margin-block: var(--utrecht-space-block-md, 1rem);
}

.pq-save__form {
	flex-basis: 100%;
	max-inline-size: 40rem;
}

.pq-save__buttons {
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}

.pq-save__status {
	flex-basis: 100%;
}

.pq-save__status:empty {
	display: none;
}
</style>
