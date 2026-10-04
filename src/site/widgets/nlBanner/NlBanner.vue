<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A balk across the top of a page: a storing, an onderhoudsmelding, a
	mededeling (design D1 row 61).

	NL Design System publishes no CSS for this one, so it styles itself from
	`--utrecht-*` tokens alone (design D5): the kinds reuse the alert tokens, so
	a portal that themes its meldingen themes this with them.
-->
<template>
	<div
		v-if="!closed"
		class="nl-banner"
		:class="`nl-banner--${safeKind}`"
		:role="urgent ? 'alert' : 'status'"
		:aria-live="urgent ? 'assertive' : 'polite'"
		data-testid="nl-banner">
		<p class="utrecht-paragraph nl-banner__text">{{ text }}</p>
		<button
			v-if="closable"
			type="button"
			class="utrecht-button utrecht-button--subtle-action"
			data-testid="nl-banner-close"
			@click="closed = true">
			{{ closeLabel }}
		</button>
	</div>
</template>

<script>
import '@utrecht/paragraph-css/dist/index.css'
import '@utrecht/button-css/dist/index.css'

export default {
	name: 'NlBanner',

	props: {
		/** `info`, `ok`, `warning` or `error`. */
		kind: { type: String, default: 'info' },
		/** The text the visitor reads. */
		text: { type: String, default: '' },
		/** Whether a visitor may close it. */
		closable: { type: Boolean, default: false },
		/** The text on the close button. */
		closeLabel: { type: String, default: 'Sluiten' },
	},

	data() {
		return {
			/** Whether the visitor closed it. */
			closed: false,
		}
	},

	computed: {
		/**
		 * @return {string} One of info, ok, warning or error.
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 */
		safeKind() {
			return ['info', 'ok', 'warning', 'error'].includes(this.kind)
				? this.kind
				: 'info'
		},

		/**
		 * WHAT IT ANNOUNCES: a storingsmelding or an error interrupts, because a
		 * visitor needs it before they start; an info banner waits its turn. The
		 * close button is a real button, so closing it is a key press away and
		 * the banner does not come back on the same page view.
		 *
		 * @return {boolean} Whether it interrupts.
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 */
		urgent() {
			return this.safeKind === 'warning' || this.safeKind === 'error'
		},
	},
}
</script>

<style scoped>
/*
 * TOKENS ONLY, no literal colours. Every value here is an `--utrecht-*`
 * reference with a token fallback, so a portal's own set themes this band
 * like it themes the alerts. `tests/widget-tokens.spec.mjs` fails on a hex,
 * an rgb() or a named colour in this file.
 */
.nl-banner {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: var(--utrecht-space-inline-md, 1rem);
	padding: var(--utrecht-space-block-sm, 0.5rem)
		var(--utrecht-space-inline-md, 1rem);
	border-block-end: var(--utrecht-alert-border-width, 1px) solid
		var(--utrecht-alert-info-border-color, var(--utrecht-color-grey-30));
	background-color: var(
		--utrecht-alert-info-background-color,
		var(--utrecht-document-background-color)
	);
	color: var(--utrecht-alert-info-color, var(--utrecht-document-color));
}

.nl-banner--ok {
	border-block-end-color: var(
		--utrecht-alert-ok-border-color,
		var(--utrecht-color-green-30)
	);
	background-color: var(
		--utrecht-alert-ok-background-color,
		var(--utrecht-document-background-color)
	);
	color: var(--utrecht-alert-ok-color, var(--utrecht-document-color));
}

.nl-banner--warning {
	border-block-end-color: var(
		--utrecht-alert-warning-border-color,
		var(--utrecht-color-orange-30)
	);
	background-color: var(
		--utrecht-alert-warning-background-color,
		var(--utrecht-document-background-color)
	);
	color: var(--utrecht-alert-warning-color, var(--utrecht-document-color));
}

.nl-banner--error {
	border-block-end-color: var(
		--utrecht-alert-error-border-color,
		var(--utrecht-color-red-30)
	);
	background-color: var(
		--utrecht-alert-error-background-color,
		var(--utrecht-document-background-color)
	);
	color: var(--utrecht-alert-error-color, var(--utrecht-document-color));
}

.nl-banner__text {
	margin: 0;
}
</style>
