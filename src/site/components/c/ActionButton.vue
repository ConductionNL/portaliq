<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<div class="pq-action-button" :data-testid="`action-button-${action.id}`">
		<button
			type="button"
			class="utrecht-button utrecht-button--primary-action"
			:disabled="busy || (requireEndpoint && !action.endpoint)"
			@click="run">
			{{
				busy ? translate('Please wait…') : label || action.label || action.id
			}}
		</button>
		<p
			class="utrecht-paragraph pq-action-button__status"
			role="status"
			:data-testid="`action-status-${action.id}`">
			{{ message }}
		</p>
	</div>
</template>

<script>
import { runAction } from '../../../shared/rowAction.js'
import { forwardApi } from '../../lib/residentActions.js'
import { residentAuthBase, residentToken } from '../../lib/residentSession.js'
import { translatorOr } from './forms.js'

/**
 * The browser navigation, kept apart so a caller or test can pass its own.
 *
 * @param {string} url An absolute https URL, already checked by redirectTarget.
 */
function goTo(url) {
	window.location.assign(url)
}

/**
 * An endpoint or cta action as one button (App.jsx `onAction`). It forwards
 * the action through portaliq, which signs the assertion; the browser follows
 * a checked https redirect, and any other answer shows in a status region.
 * Without an `api` it forwards through the site's own resident session
 * (`residentActions.forwardApi`), the way the save buttons do.
 *
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-an-endpoint-action-must-show-its-answer-or-follow-its-redirect-req-srp-027
 */
export default {
	name: 'ActionButton',

	props: {
		/** The endpoint action (`id`, `label`, `endpoint`, `app`). */
		action: { type: Object, required: true },
		/** The contributing app; the action's own `app` wins. */
		app: { type: String, default: '' },
		/** The button text (a cta block's label); the action's label otherwise. */
		label: { type: String, default: '' },
		/** Disable the button when the action has no `endpoint` (an `action` block). */
		requireEndpoint: { type: Boolean, default: false },
		/** The portal api (`forwardAction`); the site session when null. */
		api: { type: Object, default: null },
		/** The translator `t(key, vars)`. */
		t: { type: Function, default: null },
		/** Where a checked redirect goes; the browser by default. */
		navigate: { type: Function, default: goTo },
	},

	data() {
		return {
			busy: false,
			message: '',
		}
	},

	computed: {
		translate() {
			return translatorOr(this.t)
		},
	},

	methods: {
		/**
		 * Forward the action and follow or show its answer.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-an-endpoint-action-must-show-its-answer-or-follow-its-redirect-req-srp-027
		 */
		async run() {
			this.message = ''
			this.busy = true
			const api = this.api || forwardApi(residentAuthBase(), residentToken)
			const { redirect, messageKey } = await runAction(
				api,
				this.action.app || this.app,
				this.action,
			)
			if (redirect) {
				this.navigate(redirect)
				return
			}
			this.busy = false
			this.message = this.translate(messageKey)
		},
	},
}
</script>

<style scoped>
.pq-action-button {
	margin-block: var(--utrecht-space-block-sm, 0.5rem);
}

.pq-action-button__status:empty {
	display: none;
}
</style>
