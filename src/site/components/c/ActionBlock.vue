<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<div
		v-if="resolved"
		class="pq-action-block"
		:data-testid="`action-block-${resolved.id}`">
		<template v-if="isForm">
			<h2 class="utrecht-heading-2">
				{{ resolved.label || resolved.id }}
			</h2>
			<SchemaForm
				:action="resolved"
				:api="api"
				:t="t"
				@submitted="(object) => $emit('created', object, resolved)" />
		</template>
		<!-- A call to action that creates something about the open record
		     (a bezwaar, a klacht on a case page): a button that opens the
		     action's form, with the record already in its `recordField`
		     (case-actions-on-the-case-page). -->
		<template v-else-if="isRecordForm">
			<div class="pq-action-button">
				<button
					type="button"
					class="utrecht-button utrecht-button--primary-action"
					:aria-expanded="String(open)"
					:aria-controls="`pq-action-form-${resolved.id}`"
					:data-testid="`action-open-${resolved.id}`"
					@click="open = !open">
					{{ block.label || resolved.label || resolved.id }}
				</button>
			</div>
			<div
				v-if="open"
				:id="`pq-action-form-${resolved.id}`"
				class="pq-action-block__form">
				<h2 class="utrecht-heading-3">
					{{ block.label || resolved.label || resolved.id }}
				</h2>
				<SchemaForm
					:action="resolved"
					:api="api"
					:t="t"
					:preset="{ [resolved.recordField]: block.record }"
					@submitted="(object) => $emit('created', object, resolved)" />
			</div>
		</template>
		<ActionButton
			v-else
			:action="resolved"
			:app="(contribution && contribution.app) || ''"
			:label="block.type === 'cta' ? block.label || '' : ''"
			:requireEndpoint="block.type === 'action'"
			:api="api"
			:t="t"
			:navigate="navigate" />
	</div>
</template>

<script>
import ActionButton from './ActionButton.vue'
import SchemaForm from './SchemaForm.vue'

/**
 * Sets `window.location` to a checked https URL.
 *
 * @param {string} url The URL.
 */
function goTo(url) {
	window.location.assign(url)
}

/**
 * One `action` or `cta` block of a contributed page (PageView.jsx): a
 * `create` or `update` action renders its schema form, every other action a
 * button that forwards it. The block's action is looked up in the
 * contribution, so a block can only run an action that contribution declares.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-an-endpoint-action-must-show-its-answer-or-follow-its-redirect-req-srp-027
 */
export default {
	name: 'ActionBlock',

	components: { ActionButton, SchemaForm },

	inheritAttrs: false,

	props: {
		/** The page block: `{type: 'action'|'cta', action, label?}`. */
		block: { type: Object, required: true },
		/** The contribution the page belongs to (`app`, `actions`). */
		contribution: { type: Object, default: () => ({}) },
		/** The portal api. */
		api: { type: Object, required: true },
		/** The translator `t(key, vars)`. */
		t: { type: Function, default: null },
		/** The block's action when the page already resolved it. */
		action: { type: Object, default: null },
		/** Where a checked redirect goes; the browser by default. */
		navigate: { type: Function, default: goTo },
	},

	emits: ['created'],

	data() {
		return { open: false }
	},

	computed: {
		resolved() {
			return (
				this.action
				|| ((this.contribution && this.contribution.actions) || []).find(
					(a) => a && a.id === this.block.action,
				)
				|| null
			)
		},

		isForm() {
			return (
				this.block.type === 'action'
				&& (this.resolved.type === 'create'
					|| this.resolved.type === 'update')
			)
		},

		/**
		 * Whether this is a call to action that creates something about the
		 * open record: a `cta` with `withRecord`, on a record page (the page
		 * sets `record`), for a create action that names its `recordField`.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-cta-block-may-open-a-page-or-a-site-route-for-the-open-record-with-the-record-in-its-label-req-smo-024
		 */
		isRecordForm() {
			return (
				this.block.type === 'cta'
				&& this.block.withRecord === true
				&& typeof this.block.record === 'string'
				&& this.block.record !== ''
				&& this.resolved.type === 'create'
				&& typeof this.resolved.recordField === 'string'
				&& (this.resolved.fields || []).includes(this.resolved.recordField)
			)
		},
	},
}
</script>
