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
		      > ul.ac-c-navigation__dropdown          <- children, if any

		The label is a `div` INSIDE the anchor, not the anchor's own text —
		that indirection is what the CSS targets, so flattening it renders an
		unstyled link that still passes any test asserting on link text.

		A SUBMENU IS A DROPDOWN, NOT A SECOND ROW. The child list first shipped
		wearing `ac-c-navigation__ul` — the TOP-LEVEL bar class — because it is
		also a list of nav items. That class is `display: flex` and in flow, so
		the children rendered as an extra 55px strip stacked under their parent
		and the whole navigation bar came out 110px against the reference's 55.
		It went unnoticed because the portal being compared had no child items;
		the one that did was visibly wrong on the same build.

		`ac-c-navigation__dropdown` is what the reference uses: `position:
		absolute; display: none`, revealed by `.isOpen`, anchored by the
		`position: relative` already on `.ac-c-navigation__li`. Being out of
		flow is the point — it cannot alter the bar's height whether it is open
		or closed.

		`pq-menu*` and the `data-testid`s are kept alongside so the existing
		e2e and scoped styles keep addressing the same nodes.
	-->
	<nav
		class="ac-c-navigation__primary pq-menu"
		:aria-label="menu.title"
		data-testid="site-menu"
		@keydown.esc="open = null">
		<ul class="ac-c-navigation__ul pq-menu__list">
			<li
				v-for="item in menu.items"
				:key="item.name"
				class="ac-c-navigation__li pq-menu__item"
				@mouseenter="open = item.name"
				@mouseleave="open = null"
				@focusin="open = item.name"
				@focusout="onFocusOut($event, item.name)">
				<a
					class="ac-c-navigation__link-container pq-menu__link"
					:href="item.link"
					:aria-current="isCurrent(item.link) ? 'page' : undefined"
					:aria-expanded="
						hasChildren(item) ? String(open === item.name) : undefined
					"
					@click.prevent="select(item.link)">
					<div class="ac-c-navigation__label">{{ item.name }}</div>
				</a>

				<!-- Exactly one level of children. The API already drops
				     anything deeper, so a consumer never has to guess how far
				     the tree can go. -->
				<ul
					v-if="hasChildren(item)"
					class="ac-c-navigation__dropdown pq-menu__sublist"
					:class="{ isOpen: open === item.name }"
					data-testid="site-menu-dropdown">
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
							@click.prevent="select(child.link)">
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

	data() {
		return {
			/**
			 * Name of the item whose dropdown is open, or null.
			 *
			 * One value rather than a per-item flag: only one submenu may be
			 * open at a time, and making that impossible to express wrongly is
			 * cheaper than keeping several booleans in step.
			 */
			open: null,
		}
	},

	methods: {
		/**
		 * Whether an item has a submenu.
		 *
		 * @param {object} item The menu item.
		 * @return {boolean} True when it has at least one child.
		 */
		hasChildren(item) {
			return Boolean(item.items && item.items.length)
		},

		/**
		 * Navigate, and CLOSE THE DROPDOWN.
		 *
		 * Closing is not tidiness, it is the fix for a page that stops
		 * responding. Activating a parent item leaves focus on its anchor, and
		 * `focusin` is one of the two things that opens the submenu — so after
		 * a click the dropdown stayed open with nothing to dismiss it. Being
		 * `position: absolute` it then sat OVER the content: measured 110x55 at
		 * (119, 151), directly under the navigation bar, and
		 * `document.elementFromPoint` at its centre returned the dropdown's own
		 * label rather than the page beneath.
		 *
		 * So the visitor navigated, landed on the new page, and found a
		 * rectangle in the top-left of the content that swallowed every click.
		 * Nothing errored and nothing looked broken — the menu simply never
		 * closed. Moving the mouse away fixed it, which is exactly why it reads
		 * as intermittent.
		 *
		 * @param {string} link The route to navigate to.
		 * @return {void}
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portals-theme-must-change-what-a-visitor-sees
		 */
		select(link) {
			this.open = null
			this.$emit('navigate', link)
		},

		/**
		 * Close the dropdown when focus leaves the item ENTIRELY.
		 *
		 * `focusout` fires when moving between two children of the same item,
		 * so closing unconditionally would shut the menu the moment a keyboard
		 * user tabbed from the first child link to the second — the submenu
		 * would be unreachable by keyboard while working fine with a mouse.
		 * `relatedTarget` is where focus is going; if that is still inside this
		 * item, stay open.
		 *
		 * @param {FocusEvent} event The focusout event.
		 * @param {string} name The item's name.
		 * @return {void}
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portals-theme-must-change-what-a-visitor-sees
		 */
		onFocusOut(event, name) {
			if (
				event.relatedTarget
				&& event.currentTarget.contains(event.relatedTarget)
			) {
				return
			}

			if (this.open === name) {
				this.open = null
			}
		},

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
