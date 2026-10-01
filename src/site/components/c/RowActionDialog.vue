<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<SigningDialog
		v-if="kind === 'sign'"
		:action="action"
		:viewAction="viewDocument"
		:collection="collection"
		:row="row"
		:api="api"
		:t="t"
		@done="$emit('done')"
		@close="$emit('close')" />
	<DeclineDialog
		v-else-if="kind === 'decline'"
		:action="action"
		:collection="collection"
		:row="row"
		:api="api"
		:t="t"
		@done="$emit('done')"
		@close="$emit('close')" />
	<RowActionConfirm
		v-else
		:action="action"
		:collection="collection"
		:row="row"
		:api="api"
		:t="t"
		:navigate="navigate"
		@done="$emit('done')"
		@close="$emit('close')" />
</template>

<script>
import { defineAsyncComponent } from 'vue'
import { isEndpointRowAction } from '../../../shared/rowAction.js'
import { dialogFor } from '../../../shared/signing.js'

/**
 * Sets `window.location` to a checked https URL.
 *
 * @param {string} url The URL.
 */
function goTo(url) {
	window.location.assign(url)
}

/**
 * The step an endpoint row action opens below its table (PageView.jsx): sign
 * and decline get their own dialogs, every other endpoint row action the plain
 * confirm step. Each dialog loads on first use.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-an-endpoint-row-action-must-confirm-before-it-runs-req-srp-026
 */
export default {
	name: 'RowActionDialog',

	components: {
		SigningDialog: defineAsyncComponent(
			() => import('../../modals/c/SigningDialog.vue'),
		),

		DeclineDialog: defineAsyncComponent(
			() => import('../../modals/c/DeclineDialog.vue'),
		),

		RowActionConfirm: defineAsyncComponent(
			() => import('../../modals/c/RowActionConfirm.vue'),
		),
	},

	inheritAttrs: false,

	props: {
		/** The endpoint row action the resident pressed. */
		action: { type: Object, required: true },
		/** Every resolved row action of the collection (finds `viewDocument`). */
		rowActions: { type: Array, default: () => [] },
		/** The `viewDocument` row action when the page already found it. */
		viewAction: { type: Object, default: null },
		/** The step when the page already chose it: sign, decline or confirm. */
		dialog: { type: String, default: '' },
		/** The collection the row belongs to. */
		collection: { type: Object, required: true },
		/** The row. */
		row: { type: Object, required: true },
		/** The portal api (`forwardRowAction`). */
		api: { type: Object, required: true },
		/** The translator `t(key, vars)`. */
		t: { type: Function, default: null },
		/** Where a checked redirect goes; the browser by default. */
		navigate: { type: Function, default: goTo },
	},

	emits: ['done', 'close'],

	computed: {
		kind() {
			return ['sign', 'decline', 'confirm'].includes(this.dialog)
				? this.dialog
				: dialogFor(this.action)
		},

		viewDocument() {
			return (
				this.viewAction
				|| this.rowActions.find(
					(a) => a && a.id === 'viewDocument' && isEndpointRowAction(a),
				)
				|| null
			)
		},
	},
}
</script>
