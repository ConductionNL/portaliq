<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<nav class="pq-menu" :aria-label="menu.title" data-testid="site-menu">
		<ul class="pq-menu__list">
			<li v-for="item in menu.items" :key="item.name" class="pq-menu__item">
				<a
					class="pq-menu__link"
					:href="item.link"
					:aria-current="isCurrent(item.link) ? 'page' : undefined"
					@click.prevent="$emit('navigate', item.link)">
					{{ item.name }}
				</a>

				<!-- Exactly one level of children. The API already drops
				     anything deeper, so a consumer never has to guess how far
				     the tree can go. -->
				<ul v-if="item.items && item.items.length" class="pq-menu__sublist">
					<li
						v-for="child in item.items"
						:key="child.name"
						class="pq-menu__item">
						<a
							class="pq-menu__link pq-menu__link--child"
							:href="child.link"
							:aria-current="
								isCurrent(child.link) ? 'page' : undefined
							"
							@click.prevent="$emit('navigate', child.link)">
							{{ child.name }}
						</a>
					</li>
				</ul>
			</li>
		</ul>
	</nav>
</template>

<script>
export default {
	name: 'SiteMenu',

	props: {
		/** One menu, as the content API shapes it. */
		menu: {
			type: Object,
			required: true,
		},

		/** The route currently being shown. */
		currentRoute: {
			type: String,
			default: '/',
		},
	},

	emits: ['navigate'],

	methods: {
		/**
		 * Whether a link points at the page currently shown.
		 *
		 * Drives `aria-current="page"`, so the current location is announced
		 * to a screen reader rather than only shown as a colour — this is a
		 * government surface and colour alone does not carry it.
		 *
		 * @param {string} link The item's link.
		 * @return {boolean} True when it is the current route.
		 */
		isCurrent(link) {
			return link === this.currentRoute
		},
	},
}
</script>

<style scoped>
.pq-menu__list,
.pq-menu__sublist {
	list-style: none;
	margin: 0;
	padding: 0;
}

.pq-menu__list {
	display: flex;
	flex-wrap: wrap;
	gap: 1.25rem;
}

.pq-menu__sublist {
	display: flex;
	gap: 0.75rem;
	margin-top: 0.25rem;
}

.pq-menu__link {
	color: var(--pq-link-color, #004488);
	text-decoration: underline;
	font-weight: 600;
}

.pq-menu__link--child {
	font-weight: 400;
	font-size: 0.9em;
}

.pq-menu__link[aria-current='page'] {
	text-decoration-thickness: 3px;
}

.pq-menu__link:focus-visible {
	outline: 2px solid var(--pq-focus-color, #1a1a1a);
	outline-offset: 2px;
}
</style>
