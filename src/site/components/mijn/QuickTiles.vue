<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	Quick action tiles: consecutive `cta` blocks that open a page or a route,
	as links in one list ("Vera ziek melden", "Cijfers", "Bericht sturen").
	Each tile is one link with a real address; its icon is decorative.
-->
<template>
	<ul class="pq-quick-tiles" data-testid="mijn-quick-tiles">
		<li v-for="tile in tiles" :key="tile.key" class="pq-quick-tiles__item">
			<a
				class="pq-quick-tiles__link"
				:href="hrefOf(tile.route)"
				data-testid="mijn-quick-tile"
				@click="open($event, tile)">
				<svg
					class="pq-quick-tiles__icon"
					viewBox="0 0 24 24"
					aria-hidden="true"
					focusable="false">
					<path
						d="M9 6l6 6-6 6"
						fill="none"
						stroke="currentColor"
						stroke-width="2" />
				</svg>
				<span>{{ tile.label }}</span>
			</a>
		</li>
	</ul>
</template>

<script>
import { siteHref } from './rows.js'

/**
 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-cta-block-may-open-a-page-or-a-site-route-for-the-open-record-with-the-record-in-its-label-req-smo-024
 */
export default {
	name: 'QuickTiles',

	props: {
		/** The tiles: `{key, label, route}`. */
		tiles: { type: Array, required: true },
	},

	emits: ['open'],

	methods: {
		/**
		 * @param {string} route An in-site route.
		 * @return {string} Its real address.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-cta-block-may-open-a-page-or-a-site-route-for-the-open-record-with-the-record-in-its-label-req-smo-024
		 */
		hrefOf(route) {
			return siteHref(route)
		},

		/**
		 * A plain click stays in the site; a click for a new tab is the browser's.
		 *
		 * @param {MouseEvent} event The click.
		 * @param {object} tile The tile.
		 * @return {void}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/portal-contribution-contract/spec.md#requirement-a-cta-block-may-open-a-page-or-a-site-route-for-the-open-record-with-the-record-in-its-label-req-smo-024
		 */
		open(event, tile) {
			if (event?.ctrlKey || event?.metaKey || event?.shiftKey) {
				return
			}
			event?.preventDefault?.()
			this.$emit('open', tile)
		},
	},
}
</script>

<style scoped>
.pq-quick-tiles {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(min(100%, 12rem), 1fr));
	gap: var(--utrecht-space-inline-sm, 0.5rem);
	margin: 0 0 var(--utrecht-space-block-lg, 1.5rem);
	padding: 0;
	list-style: none;
}

.pq-quick-tiles__link {
	display: flex;
	align-items: center;
	gap: 0.5rem;
	block-size: 100%;
	padding: var(--utrecht-space-block-md, 1rem);
	border: 1px solid var(--utrecht-color-grey-80, #ccc);
	border-radius: 0.5rem;
	background-color: var(--utrecht-document-background-color, #fff);
	color: var(--utrecht-link-color, LinkText);
	font-weight: bold;
	text-decoration: none;
}

.pq-quick-tiles__link:hover {
	text-decoration: underline;
}

.pq-quick-tiles__link:focus-visible {
	outline: var(--utrecht-focus-outline-width, 2px)
		var(--utrecht-focus-outline-style, solid)
		var(--utrecht-focus-outline-color, currentcolor);
	outline-offset: 2px;
}

.pq-quick-tiles__icon {
	flex-shrink: 0;
	inline-size: 1.25rem;
	block-size: 1.25rem;
}
</style>
