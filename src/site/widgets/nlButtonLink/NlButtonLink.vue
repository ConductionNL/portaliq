<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A link that looks like a button (design D1 row 8).

	A LINK, NOT A BUTTON ELEMENT: it navigates, and an element that navigates is
	a link whatever it looks like, so a keyboard user gets the behaviour they
	expect from what they see. An address this app cannot trust renders as plain
	text instead of a button that goes nowhere.
-->
<template>
	<a
		v-if="safeHref"
		class="utrecht-button utrecht-button--html-a"
		:class="`utrecht-button--${safeKind}-action`"
		:href="safeHref"
		data-testid="nl-button-link">
		{{ label }}
	</a>
	<span v-else class="utrecht-paragraph" data-testid="nl-button-link-plain">{{
		label
	}}</span>
</template>

<script>
import '@utrecht/button-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'

export default {
	name: 'NlButtonLink',

	props: {
		/** The text on the button. */
		label: { type: String, default: '' },
		/** Where it goes. */
		href: { type: String, default: '' },
		/** `primary`, `secondary` or `subtle`. */
		kind: { type: String, default: 'primary' },
	},

	computed: {
		/**
		 * @return {string} The kind, or `primary` for anything unknown.
		 */
		safeKind() {
			return ['primary', 'secondary', 'subtle'].includes(this.kind)
				? this.kind
				: 'primary'
		},

		/**
		 * @return {string} The address, or '' when it is not one to trust.
		 */
		safeHref() {
			const href = String(this.href || '').trim()
			if (href === '') {
				return ''
			}

			if (href.startsWith('/') && !href.startsWith('//')) {
				return href
			}

			try {
				return ['http:', 'https:', 'mailto:', 'tel:'].includes(
					new URL(href).protocol,
				)
					? href
					: ''
			} catch {
				return ''
			}
		},
	},
}
</script>
