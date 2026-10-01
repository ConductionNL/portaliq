<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The prompt for a missing e-mail address (identity-profile-page T09), ported
	from ContactPrompt in src/portal/components/AccountPage.jsx. The shell shows
	it above the page while `contactPromptWanted(session)` from
	src/site/pages/e/index.js holds. "Not now" hides it for the session.
-->
<template>
	<div class="pq-contact-prompt utrecht-alert" role="status" data-testid="contact-prompt">
		<p class="utrecht-paragraph">
			{{ t('Add an e-mail address so we can tell you when something changes.') }}
		</p>
		<div class="pq-e-buttons">
			<button
				type="button"
				class="utrecht-button utrecht-button--primary-action"
				data-testid="contact-prompt-open"
				@click="open">
				{{ t('Go to My account') }}
			</button>
			<button
				type="button"
				class="utrecht-button utrecht-button--secondary-action"
				data-testid="contact-prompt-dismiss"
				@click="dismiss">
				{{ t('Not now') }}
			</button>
		</div>
	</div>
</template>

<script>
import { dismissPrompt } from '../../../shared/account.js'

/**
 * sessionStorage, or null where the browser refuses it.
 *
 * @return {Storage|null}
 */
function sessionStore() {
	try {
		return window.sessionStorage
	} catch {
		return null
	}
}

export default {
	name: 'ContactPrompt',

	props: {
		/** The translator `t(key, vars)`. */
		t: {
			type: Function,
			required: true,
		},

		/** The shell's `navigate(key, params)`; "Go to My account" opens `__account__`. */
		navigate: {
			type: Function,
			default: () => {},
		},
	},

	emits: ['dismiss'],

	methods: {
		/**
		 * Open "My account".
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-manage-their-own-account-req-srp-037
		 */
		open() {
			this.navigate('__account__')
		},

		/**
		 * Hide the prompt for the rest of the session.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-resident-must-manage-their-own-account-req-srp-037
		 */
		dismiss() {
			dismissPrompt(sessionStore())
			this.$emit('dismiss')
		},
	},
}
</script>

<style scoped>
.pq-contact-prompt {
	margin-block-end: var(--utrecht-space-block-md, 1rem);
}

.pq-e-buttons {
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}
</style>
