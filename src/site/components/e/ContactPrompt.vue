<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The prompt for a missing e-mail address (identity-profile-page T09), ported
	from ContactPrompt in the React portal's AccountPage.jsx. The shell shows
	it above the page while `contactPromptWanted(session)` from
	src/site/pages/e/index.js holds. "Not now" hides it for the session.
-->
<template>
	<div
		class="pq-contact-prompt utrecht-alert"
		role="status"
		data-testid="contact-prompt">
		<p class="utrecht-paragraph">
			{{
				texts.text
				|| t(
					'Add an e-mail address so we can tell you when something changes.',
				)
			}}
		</p>
		<div class="pq-e-buttons">
			<button
				type="button"
				class="utrecht-button utrecht-button--primary-action"
				data-testid="contact-prompt-open"
				@click="open">
				{{ texts.button || t('Go to My account') }}
			</button>
			<button
				type="button"
				class="utrecht-button utrecht-button--secondary-action"
				data-testid="contact-prompt-dismiss"
				@click="dismiss">
				{{ texts.dismiss || t('Not now') }}
			</button>
		</div>
	</div>
</template>

<script>
import { dismissPrompt } from '../../../shared/account.js'

// The alert's look travels with the prompt. On a `/mijn` page another
// component had already loaded the Utrecht alert CSS, so the prompt looked
// right there and nowhere else: above the public home page it stood with a
// transparent ground and a black border. Webpack loads the module once, so a
// page that already had it renders exactly as before.
import '@utrecht/alert-css/dist/index.css'

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

		/**
		 * The portal's own words, `{text?, button?, dismiss?}`, in its tone
		 * (mijn-overview-follows-the-boards); the site's words where it writes none.
		 */
		texts: {
			type: Object,
			default: () => ({}),
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
	/* The Utrecht alert reads its padding from four tokens and declares no
	   fallback, so on a set that names none of them the text stood against
	   the alert's left edge (measured: padding 0px on the Zuiddrecht /mijn
	   and home pages). A set that names them keeps its own. */
	padding-block: var(--utrecht-alert-padding-block-start, 16px)
		var(--utrecht-alert-padding-block-end, 16px);
	padding-inline: var(--utrecht-alert-padding-inline-start, 20px)
		var(--utrecht-alert-padding-inline-end, 20px);
}

.pq-e-buttons {
	display: flex;
	flex-wrap: wrap;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}
</style>
