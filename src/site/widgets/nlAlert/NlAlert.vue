<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A melding, in one of four kinds (design D1 row 3).

	It announces itself: see `urgent()` for which kind interrupts a screen
	reader and which waits its turn.
-->
<template>
	<div
		class="utrecht-alert"
		:class="`utrecht-alert--${safeKind}`"
		:role="urgent ? 'alert' : 'status'"
		:aria-live="urgent ? 'assertive' : 'polite'"
		data-testid="nl-alert">
		<div class="utrecht-alert__content">
			<p v-if="heading" class="utrecht-heading-3">{{ heading }}</p>
			<p class="utrecht-paragraph">{{ text }}</p>
		</div>
	</div>
</template>

<script>
import '@utrecht/alert-css/dist/index.css'
import '@utrecht/heading-3-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'

export default {
	name: 'NlAlert',

	props: {
		/** `info`, `ok`, `warning` or `error`. */
		kind: { type: String, default: 'info' },
		/** The heading above the text. */
		heading: { type: String, default: '' },
		/** The text the visitor reads. */
		text: { type: String, default: '' },
	},

	computed: {
		/**
		 * The kind, or `info` for anything unknown: a melding with a soort
		 * nobody declared should read as information rather than as an error a
		 * visitor cannot place.
		 *
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
		 * WHAT THIS ANNOUNCES, AND TO WHOM. A warning or an error is read out as
		 * soon as it appears, because it is about something that went wrong and a
		 * screen-reader user should not meet it only when they happen to reach
		 * it. An info or an ok melding is announced politely, after whatever is
		 * being read: it is news, not an interruption. A melding placed on a page
		 * at load is in the document from the start, so this matters when an
		 * author changes it in the editor and when a portal re-renders a page.
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
