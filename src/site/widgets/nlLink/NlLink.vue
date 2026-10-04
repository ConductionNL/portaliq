<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The NL Design System Link, placeable on a page (design D1 row 53).

	It renders Utrecht's own class structure and imports that component's CSS
	package, so a portal's own tokens style it: the widget owns no colour of
	its own.

	An address that is not one is NOT rendered as a link. An author who leaves
	the field empty, or types something the browser would resolve against this
	origin as a path they did not mean, reads their own text as plain text
	rather than a link that goes somewhere surprising.
-->
<template>
	<a
		v-if="safeHref"
		class="utrecht-link"
		:href="safeHref"
		:target="external ? '_blank' : null"
		:rel="external ? 'noopener noreferrer' : null"
		data-testid="nl-link">
		{{ text }}
	</a>
	<span v-else class="utrecht-paragraph" data-testid="nl-link-plain">{{
		text
	}}</span>
</template>

<script>
import '@utrecht/link-css/dist/index.css'

/**
 * The schemes a placed link may use. A `javascript:` or `data:` address is
 * refused here rather than sanitised, because an author has no reason to
 * write one and a visitor has every reason not to follow one.
 *
 * @type {Array<string>}
 */
const SCHEMES = ['http:', 'https:', 'mailto:', 'tel:']

export default {
	name: 'NlLink',

	props: {
		/** The text the visitor reads. */
		label: { type: String, default: '' },
		/** Where it goes: an absolute address, or a path inside this site. */
		href: { type: String, default: '' },
	},

	computed: {
		/**
		 * @return {string} The text, or the address when no text was given.
		 */
		text() {
			const label = String(this.label || '').trim()
			return label === '' ? String(this.href || '').trim() : label
		},

		/**
		 * @return {string} The address to link to, or '' when there is none to trust.
		 */
		safeHref() {
			const href = String(this.href || '').trim()
			if (href === '') {
				return ''
			}

			// A path inside this site is kept as written: it is resolved by the
			// browser against this origin, which is where the author meant it.
			if (href.startsWith('/') && !href.startsWith('//')) {
				return href
			}

			try {
				return SCHEMES.includes(new URL(href).protocol) ? href : ''
			} catch {
				return ''
			}
		},

		/**
		 * @return {boolean} Whether it leaves this site.
		 */
		external() {
			return this.safeHref !== '' && !this.safeHref.startsWith('/')
		},
	},
}
</script>
