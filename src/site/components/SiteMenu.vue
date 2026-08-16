<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<!--
		Emits the reference implementation's `ac-c-navigation__*` structure so
		`nlds-app.css` styles it, captured from the running reference:

		  nav.ac-c-navigation__primary > ul.ac-c-navigation__ul
		    > li.ac-c-navigation__li
		      > a.ac-c-navigation__link-container
		        > div.ac-c-navigation__label

		The label is a `div` INSIDE the anchor, not the anchor's own text —
		that indirection is what the CSS targets, so flattening it renders an
		unstyled link that still passes any test asserting on link text.

		`pq-menu*` and the `data-testid`s are kept alongside so the existing
		e2e and scoped styles keep addressing the same nodes.
	-->
	<nav
		class="ac-c-navigation__primary pq-menu"
		:aria-label="menu.title"
		data-testid="site-menu">
		<ul class="ac-c-navigation__ul pq-menu__list">
			<li
				v-for="item in menu.items"
				:key="item.name"
				class="ac-c-navigation__li pq-menu__item">
				<a
					class="ac-c-navigation__link-container pq-menu__link"
					:href="item.link"
					:aria-current="isCurrent(item.link) ? 'page' : undefined"
					@click.prevent="$emit('navigate', item.link)">
					<div class="ac-c-navigation__label">{{ item.name }}</div>
				</a>

				<!-- Exactly one level of children. The API already drops
				     anything deeper, so a consumer never has to guess how far
				     the tree can go. -->
				<ul
					v-if="item.items && item.items.length"
					class="ac-c-navigation__ul pq-menu__sublist">
					<li
						v-for="child in item.items"
						:key="child.name"
						class="ac-c-navigation__li pq-menu__item">
						<a
							class="ac-c-navigation__link-container pq-menu__link pq-menu__link--child"
							:href="child.link"
							:aria-current="
								isCurrent(child.link) ? 'page' : undefined
							"
							@click.prevent="$emit('navigate', child.link)">
							<div class="ac-c-navigation__label">
								{{ child.name }}
							</div>
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
/*
 * PRESENTATION LIVES IN THE DESIGN SYSTEM, NOT HERE.
 *
 * These elements now also carry `ac-c-navigation__*`, and `nlds-app.css`
 * styles them — list reset, flex layout, 18px padding, colours and
 * `.ac-c-navigation__primary { font-weight: 500 }`. Every rule this file used
 * to add was a second opinion on the same pixels, and scoped styles win, so
 * the design system silently lost.
 *
 * MEASURED, ours against the reference, with the block still in place: the nav
 * bar came out 54px against 55px everywhere it appears — bar, container, ul,
 * link — because `font-weight: 600` here beat the system's 500 and a heavier
 * glyph is one pixel taller. Nothing else differed by then.
 *
 * Only the focus ring stays: it is an accessibility guarantee this app owes
 * its visitors regardless of which stylesheet is loaded, and it changes no
 * geometry.
 */
.pq-menu__link:focus-visible {
	outline: 2px solid var(--pq-focus-color, #1a1a1a);
	outline-offset: 2px;
}
</style>
