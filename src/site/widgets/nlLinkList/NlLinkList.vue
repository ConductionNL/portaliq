<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A list of links, with a heading above it (design D1 row 54).

	A `nav`, because that is what a list of links to other pages is, and a
	screen-reader user navigates by landmark.
-->
<template>
	<nav class="utrecht-link-list-nav" data-testid="nl-link-list">
		<h2 v-if="heading" class="utrecht-heading-3">{{ heading }}</h2>
		<ul class="utrecht-link-list">
			<li
				v-for="(link, index) in safeLinks"
				:key="index"
				class="utrecht-link-list__item">
				<a
					class="utrecht-link-list__link"
					:href="link.href"
					@click="open($event, link)"
					>{{ link.label }}</a
				>
				<span v-if="link.description" class="utrecht-link-list__description">
					{{ link.description }}
				</span>
			</li>
		</ul>
	</nav>
</template>

<script>
import { authoredLink, staysInSite } from '../../components/mijn/links.js'

import '@utrecht/link-list-css/dist/index.css'
import '@utrecht/heading-3-css/dist/index.css'

export default {
	name: 'NlLinkList',

	props: {
		/** The heading above the list. */
		heading: { type: String, default: '' },
		/** The links: `{label, href, description}`. */
		links: { type: Array, default: () => [] },
	},

	emits: ['navigate'],

	computed: {
		/**
		 * The links that have both a text and an address inside this site or on
		 * the web. A half-filled row is left out rather than rendered as a link
		 * to nowhere.
		 *
		 * @return {Array<object>} The links.
		 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-a-link-in-a-link-list-or-a-button-link-must-open-the-page-wherever-the-site-is-served
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 */
		safeLinks() {
			return (this.links || [])
				.filter((link) => link && String(link.href || '').trim() !== '')
				.map((link) => ({
					authored: authoredLink(link.href),
					label: String(link.label || link.href).trim(),
					description: String(link.description || '').trim(),
				}))
				.filter((link) => link.authored !== null)
				.map((link) => ({
					href: link.authored.href,
					route: link.authored.route,
					label: link.label,
					description: link.description,
				}))
		},
	},

	methods: {
		/**
		 * A plain click on a page of this site stays in the site. The address
		 * on the link is the site's own for that route, so a new tab opens the
		 * same page, also on a portal served through Nextcloud.
		 *
		 * @param {Event} event The click.
		 * @param {{href: string, route: string}} link The link.
		 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-a-link-in-a-link-list-or-a-button-link-must-open-the-page-wherever-the-site-is-served
		 */
		open(event, link) {
			if (staysInSite(event, link)) {
				event.preventDefault()
				this.$emit('navigate', link.route)
			}
		},
	},
}
</script>
