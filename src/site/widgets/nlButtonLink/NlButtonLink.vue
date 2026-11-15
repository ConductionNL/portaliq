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
		v-if="link"
		class="utrecht-button utrecht-button--html-a"
		:class="[
			`utrecht-button--${safeKind}-action`,
			{ 'nl-button-link--icon': icon === 'chevron' },
		]"
		:href="link.href"
		data-testid="nl-button-link"
		@click="open"
		>{{ label
		}}<svg
			v-if="icon === 'chevron'"
			class="nl-button-link__chevron"
			viewBox="0 0 24 24"
			aria-hidden="true"
			focusable="false">
			<path d="M9 5l7 7-7 7" />
		</svg>
	</a>
	<span v-else class="utrecht-paragraph" data-testid="nl-button-link-plain">{{
		label
	}}</span>
</template>

<script>
import { authoredLink, staysInSite } from '../../components/mijn/links.js'

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
		/** `none` or `chevron` (an arrow after the words). */
		icon: { type: String, default: 'none' },
	},

	emits: ['navigate'],

	computed: {
		/**
		 * @return {string} The kind, or `primary` for anything unknown.
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 */
		safeKind() {
			return ['primary', 'secondary', 'subtle'].includes(this.kind)
				? this.kind
				: 'primary'
		},

		/**
		 * The address as a link: a page of this site by the site's own
		 * address for that route, a web, mail or phone address as it is, and
		 * null for anything else.
		 *
		 * @return {{href: string, route: string}|null} The link.
		 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-a-link-in-a-link-list-or-a-button-link-must-open-the-page-wherever-the-site-is-served
		 */
		link() {
			return authoredLink(this.href)
		},
	},

	methods: {
		/**
		 * A plain click on a page of this site stays in the site.
		 *
		 * @param {Event} event The click.
		 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-a-link-in-a-link-list-or-a-button-link-must-open-the-page-wherever-the-site-is-served
		 */
		open(event) {
			if (staysInSite(event, this.link)) {
				event.preventDefault()
				this.$emit('navigate', this.link.route)
			}
		},
	},
}
</script>

<style scoped>
/* The chevron after the words (Zuiddrecht board Contentpagina). */
.nl-button-link--icon {
	display: inline-flex;
	align-items: center;
	gap: 0.625rem;
	min-block-size: 3.25rem;
	font-size: 1.125rem;
}

.nl-button-link__chevron {
	inline-size: 1.125rem;
	block-size: 1.125rem;
	fill: none;
	stroke: currentcolor;
	stroke-width: 2.6;
	stroke-linecap: round;
}
</style>
