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
		:class="`utrecht-button--${safeKind}-action`"
		:href="link.href"
		data-testid="nl-button-link"
		@click="open">
		{{ label }}
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
