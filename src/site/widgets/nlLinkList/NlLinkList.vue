<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A list of links, with a heading above it (design D1 row 54).

	A `nav`, because that is what a list of links to other pages is, and a
	screen-reader user navigates by landmark. The heading names the landmark,
	so three link lists on one page are three different landmarks. A list
	without a heading has no name to give, so it is no landmark: an unnamed
	`nav` beside a named one is the axe `landmark-unique` finding.
-->
<template>
	<component
		:is="heading ? 'nav' : 'div'"
		class="utrecht-link-list-nav"
		:class="
			safeDisplay === 'plain'
				? null
				: `nl-link-list nl-link-list--${safeDisplay}`
		"
		:aria-labelledby="heading ? headingId : undefined"
		data-testid="nl-link-list">
		<h2 v-if="heading" :id="headingId" class="utrecht-heading-3">
			{{ heading }}
		</h2>
		<p v-if="intro" class="utrecht-paragraph nl-link-list__intro">
			{{ intro }}
		</p>
		<ul class="utrecht-link-list">
			<li
				v-for="(link, index) in safeLinks"
				:key="index"
				class="utrecht-link-list__item">
				<a
					class="utrecht-link utrecht-link-list__link"
					:href="link.href"
					@click="open($event, link)"
					>{{ link.label }}</a
				>
				<span v-if="link.description" class="utrecht-link-list__description">
					{{ link.description }}
				</span>
			</li>
		</ul>
	</component>
</template>

<script>
import { authoredLink, staysInSite } from '../../components/mijn/links.js'

import '@utrecht/link-list-css/dist/index.css'
import '@utrecht/link-css/dist/index.css'
import '@utrecht/heading-3-css/dist/index.css'
import '@utrecht/paragraph-css/dist/index.css'

/** A number per mounted list, so every heading id on the page is unique. */
let instances = 0

export default {
	name: 'NlLinkList',

	props: {
		/** The heading above the list. */
		heading: { type: String, default: '' },
		/** The links: `{label, href, description}`. */
		links: { type: Array, default: () => [] },
		/** `plain`, `card` (a bordered card) or `accent` (a thick line in the accent above). */
		display: { type: String, default: 'plain' },
		/** A line under the heading, before the links. */
		intro: { type: String, default: '' },
	},

	emits: ['navigate'],

	/**
	 * The id of the heading that names this list's landmark, unique on the
	 * page.
	 *
	 * @return {{headingId: string}} The state.
	 * @spec openspec/changes/site-content-blocks-styled/specs/site-look/spec.md#requirement-a-link-list-must-be-a-named-landmark-with-targets-of-at-least-24px
	 */
	data() {
		instances += 1
		return {
			/** The id of the heading that names this list's landmark. */
			headingId: `nl-link-list-${instances}`,
		}
	},

	computed: {
		/**
		 * @return {string} `plain`, `card` or `accent`.
		 * @spec openspec/changes/site-matches-the-zuiddrecht-boards/specs/portaliq-cms/spec.md#requirement-a-link-list-may-draw-as-a-card-or-under-an-accent-line
		 */
		safeDisplay() {
			return ['plain', 'card', 'tinted', 'accent'].includes(this.display)
				? this.display
				: 'plain'
		},

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

<style scoped>
/* The drawn displays (Zuiddrecht boards Home, Contentpagina and Publicatie).
   Tokens only; `plain` adds nothing. */
.nl-link-list__intro {
	margin: 0 0 0.75rem;
}

.nl-link-list--card {
	padding: 1.75rem 2rem;
	border: 1px solid
		var(--nldesign-color-border-dark, var(--utrecht-color-grey-80, currentcolor));
	border-radius: var(
		--nldesign-website-border-radius-large,
		var(--utrecht-border-radius-md, 0.75rem)
	);
}

.nl-link-list--card .utrecht-link-list__link,
.nl-link-list--tinted .utrecht-link-list__link {
	font-weight: 600;
}

/* A card on a light ground of the primary colour, without a line (board
   Publicatie, "Zelf iets opvragen?"). */
.nl-link-list--tinted {
	padding: 1.5rem;
	border-radius: var(
		--nldesign-website-border-radius-large,
		var(--utrecht-border-radius-md, 0.75rem)
	);
	background: var(--nldesign-color-primary-light, transparent);
}

.nl-link-list--accent {
	padding-block-start: 1.25rem;
	border-block-start: 4px solid var(--nldesign-color-accent, currentcolor);
}

.nl-link-list h2 {
	margin-block-start: 0;
}

.nl-link-list .utrecht-link-list {
	margin-block-start: 0;
}
</style>
