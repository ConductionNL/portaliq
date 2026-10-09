<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The form of an update action on a product the resident holds
	(life-domain-theme-pages REQ-LDT-003): the action's own fields with the
	row's current values. It writes through the contribution's update action,
	the way every update does; portaliq adds no endpoint.
-->
<template>
	<form
		class="pq-product-update"
		novalidate
		data-testid="product-update"
		@submit.prevent="save">
		<div v-for="field in fields" :key="field" class="utrecht-form-field">
			<label :for="`${uid}-${field}`" class="utrecht-form-label">
				{{ labelOf(field) }}
			</label>
			<input
				:id="`${uid}-${field}`"
				v-model="values[field]"
				class="utrecht-textbox"
				type="text"
				:data-testid="`product-update-${field}`" />
		</div>
		<p
			v-if="problem !== ''"
			class="utrecht-paragraph pq-product-update__error"
			role="alert"
			data-testid="product-update-problem">
			{{ t(problem) }}
		</p>
		<div class="pq-product-update__buttons">
			<button
				type="submit"
				class="utrecht-button utrecht-button--primary-action"
				:disabled="busy"
				data-testid="product-update-save">
				{{ t('Save') }}
			</button>
			<button
				type="button"
				class="utrecht-button utrecht-button--secondary-action"
				data-testid="product-update-cancel"
				@click="$emit('cancel')">
				{{ t('Cancel') }}
			</button>
		</div>
	</form>
</template>

<script>
/**
 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t05
 */
export default {
	name: 'ProductUpdateForm',

	props: {
		/** The update action: `id`, `register`, `schema`, `fields`, `fieldConfigs`. */
		action: { type: Object, required: true },
		/** The product row, for its current values and its id. */
		row: { type: Object, required: true },
		/** The portal api (`updateObject`). */
		api: { type: Object, required: true },
		/** The translator `t(key, vars)`. */
		t: { type: Function, required: true },
	},

	emits: ['saved', 'cancel'],

	data() {
		const fields = (this.action.fields || []).filter(
			(field) => typeof field === 'string' && field !== 'id',
		)
		return {
			fields,
			values: Object.fromEntries(
				fields.map((field) => [field, String(this.row[field] ?? '')]),
			),
			busy: false,
			problem: '',
			uid: `pq-pu-${String(this.row.id ?? this.row.uuid ?? '')}-${this.action.id}`,
		}
	},

	methods: {
		/**
		 * The label of one field: the action's own, else the field name.
		 *
		 * @param {string} field The field.
		 * @return {string} The label.
		 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t05
		 */
		labelOf(field) {
			return this.action.fieldConfigs?.[field]?.label || field
		},

		/**
		 * Save the changed values through the contribution; a refusal is told in words.
		 *
		 * @return {Promise<void>} Resolves when answered.
		 * @spec openspec/changes/life-domain-theme-pages/tasks.md#t05
		 */
		async save() {
			this.problem = ''
			this.busy = true
			const answer = await this.api.updateObject(
				this.action,
				String(this.row.id ?? this.row.uuid),
				{ ...this.values },
			)
			this.busy = false
			if (!answer || answer.ok !== true) {
				this.problem = 'That did not work. Try again later.'
				return
			}
			this.$emit('saved', answer.object)
		},
	},
}
</script>

<style scoped>
.pq-product-update > * + * {
	margin-block-start: var(--utrecht-space-block-sm, 0.5rem);
}

.pq-product-update input {
	inline-size: 100%;
	max-inline-size: 28rem;
}

.pq-product-update__error {
	color: var(--utrecht-feedback-danger-color, currentcolor);
}

.pq-product-update__buttons {
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}
</style>
