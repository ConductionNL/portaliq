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
				v-for="link in safeLinks"
				:key="link.href"
				class="utrecht-link-list__item">
				<a class="utrecht-link-list__link" :href="link.href">{{
					link.label
				}}</a>
				<span v-if="link.description" class="utrecht-link-list__description">
					{{ link.description }}
				</span>
			</li>
		</ul>
	</nav>
</template>

<script>
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

	computed: {
		/**
		 * The links that have both a text and an address inside this site or on
		 * the web. A half-filled row is left out rather than rendered as a link
		 * to nowhere.
		 *
		 * @return {Array<object>} The links.
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 */
		safeLinks() {
			return (this.links || [])
				.filter((link) => link && String(link.href || '').trim() !== '')
				.map((link) => ({
					href: String(link.href).trim(),
					label: String(link.label || link.href).trim(),
					description: String(link.description || '').trim(),
				}))
				.filter((link) => /^(https?:|mailto:|tel:|\/(?!\/))/.test(link.href))
		},
	},
}
</script>
