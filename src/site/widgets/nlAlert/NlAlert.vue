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
		class="utrecht-alert nl-alert"
		:class="[
			`utrecht-alert--${safeKind}`,
			{ 'nl-alert--row': button && !heading, 'nl-alert--marked': mark },
		]"
		:role="urgent ? 'alert' : 'status'"
		:aria-live="urgent ? 'assertive' : 'polite'"
		data-testid="nl-alert">
		<svg
			v-if="mark"
			class="nl-alert__mark"
			viewBox="0 0 24 24"
			aria-hidden="true"
			focusable="false">
			<path :d="mark" fill="currentColor" />
		</svg>
		<div class="utrecht-alert__content">
			<p v-if="heading" class="utrecht-heading-3">{{ heading }}</p>
			<p class="utrecht-paragraph">
				<template v-for="(part, index) in parts" :key="index">
					<strong v-if="part.strong">{{ part.text }}</strong>
					<template v-else>{{ part.text }}</template>
				</template>
			</p>
		</div>
		<!-- THE BUTTON IN THE CALLOUT (boards Contentpagina): "Afwezig
		     melden" inside "Online melden", with a chevron. -->
		<a
			v-if="button"
			class="utrecht-button-link utrecht-button-link--html-a utrecht-button-link--primary-action nl-alert__action"
			:href="button.link.href"
			data-testid="nl-alert-action"
			@click="open"
			>{{ button.label
			}}<svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
				<path :d="CHEVRON" fill="currentColor" /></svg
		></a>
	</div>
</template>

<script>
import { staysInSite } from '../../components/mijn/links.js'
import { alertAction, alertKind, boldParts } from './alert.js'

import '@utrecht/alert-css/dist/index.css'
import '@utrecht/button-link-css/dist/index.css'
import '@utrecht/heading-3-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'

/** The chevron after the button's label (Material "chevron-right"). */
const CHEVRON = 'M8.59,16.58L13.17,12L8.59,7.41L10,6L16,12L10,18L8.59,16.58Z'

/** The warning triangle before a warning's text (Material "alert-outline"). */
const WARNING = 'M12,2L1,21H23M12,6L19.53,19H4.47M11,10V14H13V10M11,16V18H13V16'

/** The round mark before an error's text (Material "alert-circle-outline"). */
const ERROR =
	'M11,15H13V17H11V15M11,7H13V13H11V7M12,2C6.47,2 2,6.5 2,12A10,10 0 0,0 12,22A10,10 0 0,0 22,12A10,10 0 0,0 12,2M12,20A8,8 0 0,1 4,12A8,8 0 0,1 12,4A8,8 0 0,1 20,12A8,8 0 0,1 12,20Z'

export default {
	name: 'NlAlert',

	props: {
		/** `info`, `ok`, `warning`, `error`, or `plain` (a white card with a line). */
		kind: { type: String, default: 'info' },
		/** The heading above the text. */
		heading: { type: String, default: '' },
		/** The text the visitor reads; words between `**` show in bold. */
		text: { type: String, default: '' },
		/** A button in the melding: `{label, href}`. */
		action: { type: Object, default: null },
	},

	emits: ['navigate'],

	data() {
		return { CHEVRON }
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
			return alertKind(this.kind)
		},

		/**
		 * @return {Array<{text: string, strong: boolean}>} The text, its bold parts apart.
		 * @spec openspec/changes/site-callouts-steps-and-tables-follow-the-boards/specs/site-look/spec.md#requirement-a-melding-may-carry-a-button-bold-words-and-a-plain-look
		 */
		parts() {
			return boldParts(this.text)
		},

		/**
		 * @return {{label: string, link: object}|null} The button, when declared.
		 * @spec openspec/changes/site-callouts-steps-and-tables-follow-the-boards/specs/site-look/spec.md#requirement-a-melding-may-carry-a-button-bold-words-and-a-plain-look
		 */
		button() {
			return alertAction(this.action)
		},

		/**
		 * The mark before a warning or an error (board Contentpagina: the
		 * triangle before "Is uw kind afwezig zonder melding?"); none for the
		 * other kinds.
		 *
		 * @return {string} An icon path, or ''.
		 * @spec openspec/changes/site-callouts-steps-and-tables-follow-the-boards/specs/site-look/spec.md#requirement-a-melding-may-carry-a-button-bold-words-and-a-plain-look
		 */
		mark() {
			return { warning: WARNING, error: ERROR }[this.safeKind] || ''
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

	methods: {
		/**
		 * Follow the button in the site, unless the visitor asked for a new tab.
		 *
		 * @param {MouseEvent} event The click.
		 * @return {void}
		 * @spec openspec/changes/site-callouts-steps-and-tables-follow-the-boards/specs/site-look/spec.md#requirement-a-melding-may-carry-a-button-bold-words-and-a-plain-look
		 */
		open(event) {
			if (staysInSite(event, this.button?.link)) {
				event.preventDefault()
				this.$emit('navigate', this.button.link.route)
			}
		},
	},
}
</script>

<style scoped>
/* A melding with a mark: the mark, then the words. */
.nl-alert--marked,
.nl-alert--row {
	display: flex;
	gap: 0.75rem;
	align-items: flex-start;
}

.nl-alert__mark {
	flex: none;
	inline-size: 1.25rem;
	block-size: 1.25rem;
	margin-block-start: 0.125rem;
}

/* With a heading the button stands under the words: the Utrecht alert is a
   row, so a melding with a button turns into a column. */
.nl-alert:has(.nl-alert__action):not(.nl-alert--row) {
	flex-direction: column;
	align-items: flex-start;
}

/* Without a heading the button stands beside the words (Esdoornveen). */
.nl-alert--row {
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: 1rem 1.5rem;
}

.nl-alert__action.utrecht-button-link {
	display: inline-flex;
	gap: 0.375rem;
	align-items: center;
	margin-block-start: 0.5rem;
	text-decoration: none;
}

.nl-alert--row .nl-alert__action.utrecht-button-link {
	margin-block-start: 0;
}

.nl-alert__action svg {
	inline-size: 1.25rem;
	block-size: 1.25rem;
}

/* THE PLAIN MELDING (board Contentpagina, "Liever bellen?"): a white card
   with a hairline, no tint. */
.utrecht-alert--plain {
	border: 1px solid
		var(--nldesign-color-border, var(--utrecht-document-color, CanvasText));
	border-radius: var(
		--nldesign-website-border-radius-large,
		var(--utrecht-border-radius-md, 0.75rem)
	);
	background-color: var(--utrecht-document-background-color, Canvas);
}
</style>
