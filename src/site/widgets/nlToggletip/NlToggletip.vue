<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	Uitleg bij een woord, shown when the visitor asks for it (design D1 row 98).

	A TOGGLETIP, NOT A TOOLTIP: it opens on a press rather than on hover, so it
	works on a phone and for somebody who cannot hover, and the explanation
	appears in a live region so a screen reader reads it when it arrives. Its
	look comes from Utrecht's tooltip CSS; the behaviour is this widget's.
-->
<template>
	<span class="nl-toggletip" data-testid="nl-toggletip">
		<span class="nl-toggletip__term">{{ term }}</span>
		<button
			type="button"
			class="nl-toggletip__button"
			:aria-expanded="open ? 'true' : 'false'"
			:aria-label="buttonLabel"
			data-testid="nl-toggletip-button"
			@click="toggle">
			?
		</button>
		<!--
			WHAT IT ANNOUNCES AND TO WHOM: the explanation itself, politely, when
			the visitor opens it. The region is in the document from the start and
			filled on the press, which is what makes a screen reader read it; a
			region that appears with its text already in it is often missed.
		-->
		<span
			class="utrecht-tooltip nl-toggletip__bubble"
			role="status"
			aria-live="polite"
			data-testid="nl-toggletip-bubble">
			{{ open ? explanation : '' }}
		</span>
	</span>
</template>

<script>
import '@utrecht/tooltip-css/dist/index.css'

export default {
	name: 'NlToggletip',

	props: {
		/** The word being explained. */
		term: { type: String, default: '' },
		/** The explanation. */
		explanation: { type: String, default: '' },
	},

	data() {
		return {
			/** Whether the explanation is showing. */
			open: false,
		}
	},

	computed: {
		/**
		 * The button's name, which has to say what it explains: a page with
		 * four of these would otherwise offer a screen-reader user four buttons
		 * called "?".
		 *
		 * @return {string} The accessible name.
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 */
		buttonLabel() {
			return 'Uitleg bij ' + (this.term || 'dit woord')
		},
	},

	methods: {
		/**
		 * Show or hide the explanation.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 */
		toggle() {
			this.open = !this.open
		},
	},
}
</script>

<style scoped>
/*
 * TOKENS ONLY beyond Utrecht's tooltip look: the button takes the link
 * colour and the focus outline from the document's own tokens.
 */
.nl-toggletip {
	display: inline-flex;
	align-items: baseline;
	gap: var(--utrecht-space-inline-xs, 0.25rem);
}

.nl-toggletip__button {
	inline-size: 1.5rem;
	block-size: 1.5rem;
	padding: 0;
	border: 1px solid var(--utrecht-link-color, LinkText);
	border-radius: 50%;
	background-color: transparent;
	color: var(--utrecht-link-color, LinkText);
	cursor: pointer;
}

.nl-toggletip__button:focus-visible {
	outline: var(--utrecht-focus-outline-width, 2px)
		var(--utrecht-focus-outline-style, solid)
		var(--utrecht-focus-outline-color, currentcolor);
	outline-offset: 2px;
}

.nl-toggletip__bubble:empty {
	display: none;
}
</style>
