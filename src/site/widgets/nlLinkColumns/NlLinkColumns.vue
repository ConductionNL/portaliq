<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A heading over columns of links, on a band of its own
	(site-matches-the-zuiddrecht-boards, board Home "Bestuur en organisatie").

	A BAND, not a grid cell: the ground runs edge to edge and the content sits
	in the page's container, like the hero. Each column is a `nav` of its own
	with its own heading, so a screen-reader user lands on "Gemeenteraad" and
	not on one anonymous list of nine links.
-->
<template>
	<section
		class="nl-link-columns"
		:class="`nl-link-columns--${safeTone}`"
		:aria-labelledby="heading ? headingId : null"
		data-testid="nl-link-columns">
		<div class="container nl-link-columns__inner">
			<h2 v-if="heading" :id="headingId" class="utrecht-heading-2 nl-link-columns__heading">
				{{ heading }}
			</h2>
			<div class="nl-link-columns__columns">
				<nav
					v-for="(column, index) in safeColumns"
					:key="index"
					class="nl-link-columns__column"
					:aria-label="column.title || undefined"
					data-testid="nl-link-column">
					<h3 v-if="column.title" class="utrecht-heading-3 nl-link-columns__title">
						{{ column.title }}
					</h3>
					<ul class="utrecht-link-list nl-link-columns__list">
						<li
							v-for="(link, linkIndex) in column.links"
							:key="linkIndex"
							class="utrecht-link-list__item">
							<a
								class="utrecht-link utrecht-link-list__link"
								:href="link.href"
								@click="open($event, link)"
								>{{ link.label }}</a
							>
						</li>
					</ul>
				</nav>
			</div>
		</div>
	</section>
</template>

<script>
import { authoredLink, staysInSite } from '../../components/mijn/links.js'

import '@utrecht/heading-2-css/dist/index.css'
import '@utrecht/heading-3-css/dist/index.css'
import '@utrecht/link-list-css/dist/index.css'
import '@utrecht/link-css/dist/index.css'

/** The most columns one band shows. */
const MAX_COLUMNS = 4

/**
 * @spec openspec/changes/site-matches-the-zuiddrecht-boards/specs/portaliq-cms/spec.md#requirement-link-columns-draw-a-heading-over-columns-of-links-on-a-band
 */
export default {
	name: 'NlLinkColumns',

	props: {
		/** The heading over the columns. */
		heading: { type: String, default: '' },
		/** The columns: `{title, links: [{label, href}]}`, four at most. */
		columns: { type: Array, default: () => [] },
		/** `surface` (the set's light grey ground) or `plain` (the page's own). */
		tone: { type: String, default: 'surface' },
	},

	emits: ['navigate'],

	computed: {
		/**
		 * @return {string} `surface` or `plain`.
		 * @spec openspec/changes/site-matches-the-zuiddrecht-boards/specs/portaliq-cms/spec.md#requirement-link-columns-draw-a-heading-over-columns-of-links-on-a-band
		 */
		safeTone() {
			return this.tone === 'plain' ? 'plain' : 'surface'
		},

		/**
		 * The columns with at least one usable link, four at most; a link
		 * without words or an address this site can follow is left out.
		 *
		 * @return {Array<{title: string, links: Array<object>}>} The columns.
		 * @spec openspec/changes/site-matches-the-zuiddrecht-boards/specs/portaliq-cms/spec.md#requirement-link-columns-draw-a-heading-over-columns-of-links-on-a-band
		 */
		safeColumns() {
			return (Array.isArray(this.columns) ? this.columns : [])
				.map((column) => ({
					title: String(column?.title ?? '').trim(),
					links: (Array.isArray(column?.links) ? column.links : [])
						.map((link) => ({
							label: String(link?.label ?? '').trim(),
							authored: authoredLink(link?.href),
						}))
						.filter((link) => link.label !== '' && link.authored !== null)
						.map((link) => ({
							label: link.label,
							href: link.authored.href,
							route: link.authored.route,
						})),
				}))
				.filter((column) => column.links.length > 0)
				.slice(0, MAX_COLUMNS)
		},

		/**
		 * @return {string} An id for the heading, unique enough per page.
		 * @spec openspec/changes/site-matches-the-zuiddrecht-boards/specs/portaliq-cms/spec.md#requirement-link-columns-draw-a-heading-over-columns-of-links-on-a-band
		 */
		headingId() {
			return `nl-link-columns-${this.heading.toLowerCase().replace(/[^a-z0-9]+/g, '-')}`
		},
	},

	methods: {
		/**
		 * A plain click on a page of this site stays in the site.
		 *
		 * @param {MouseEvent} event The click.
		 * @param {object} link The link.
		 * @return {void}
		 * @spec openspec/changes/site-matches-the-zuiddrecht-boards/specs/portaliq-cms/spec.md#requirement-link-columns-draw-a-heading-over-columns-of-links-on-a-band
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

<style scoped>
/* Tokens only (tests/widget-tokens.spec.mjs). */
.nl-link-columns--surface {
	background: var(--nldesign-color-surface, var(--utrecht-color-grey-95, transparent));
}

.nl-link-columns__inner {
	display: flex;
	flex-direction: column;
	gap: 1.75rem;
	padding-block: 4rem;
}

.nl-link-columns__heading,
.nl-link-columns__title {
	margin: 0;
}

.nl-link-columns__columns {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(16.25rem, 1fr));
	gap: 2rem 3rem;
}

.nl-link-columns__column {
	display: flex;
	flex-direction: column;
	gap: 0.75rem;
}

.nl-link-columns__list {
	display: grid;
	gap: 0.625rem;
	margin: 0;
	padding: 0;
	list-style: none;
}

@media (max-width: 600px) {
	.nl-link-columns__inner {
		padding-block: 2rem;
	}
}
</style>
