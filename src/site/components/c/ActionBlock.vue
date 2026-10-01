<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<div
		v-if="action"
		class="pq-action-block"
		:data-testid="`action-block-${action.id}`">
		<template v-if="isForm">
			<h2 class="utrecht-heading-2">
				{{ action.label || action.id }}
			</h2>
			<SchemaForm
				:action="action"
				:api="api"
				:t="t"
				@submitted="(object) => $emit('submitted', object, action)" />
		</template>
		<ActionButton
			v-else
			:action="action"
			:app="contribution.app || ''"
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

	props: {
		/** The page block: `{type: 'action'|'cta', action, label?}`. */
		block: { type: Object, required: true },
		/** The contribution the page belongs to (`app`, `actions`). */
		contribution: { type: Object, required: true },
		/** The portal api. */
		api: { type: Object, required: true },
		/** The translator `t(key, vars)`. */
		t: { type: Function, default: null },
		/** Where a checked redirect goes; the browser by default. */
		navigate: { type: Function, default: goTo },
	},

	emits: ['submitted'],

	computed: {
		action() {
			return (
				(this.contribution.actions || []).find(
					(a) => a && a.id === this.block.action,
				) || null
			)
		},

		isForm() {
			return (
				this.block.type === 'action'
				&& (this.action.type === 'create' || this.action.type === 'update')
			)
		},
	},
}
</script>
