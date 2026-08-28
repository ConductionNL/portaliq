<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<!--
		ABSENT, NOT HIDDEN.

		The host renders this component only once the probe has said yes, and
		this `v-if` is the second half of that: a control that exists in the
		document with `display: none` is still in the accessibility tree of
		several screen readers, still focusable in some browsers, and still
		tells anyone reading the source that an editing surface exists here.
		For a public government portal the honest rendering for a reader is
		nothing at all.
	-->
	<div v-if="context" class="pq-edit" data-testid="site-edit">
		<!--
			The menu is rendered BEFORE the button in the DOM so that tabbing
			forward from the button leaves the control entirely, while the menu
			itself is reached through the button's own key handling. It is
			positioned above the button visually.
		-->
		<ul
			v-if="open"
			:id="menuId"
			class="pq-edit__menu"
			role="menu"
			:aria-label="menuLabel"
			data-testid="site-edit-menu"
			@keydown="onMenuKey">
			<li v-for="(action, index) in actions" :key="action.key" role="none">
				<a
					:ref="setItemRef"
					class="pq-edit__item"
					role="menuitem"
					:href="action.href"
					:data-testid="`site-edit-${action.key}`"
					:tabindex="index === activeIndex ? 0 : -1"
					@click="open = false">
					{{ action.label }}
				</a>
			</li>
		</ul>

		<button
			ref="trigger"
			type="button"
			class="pq-edit__trigger"
			:aria-expanded="open ? 'true' : 'false'"
			aria-haspopup="menu"
			:aria-controls="open ? menuId : undefined"
			:aria-label="triggerLabel"
			:title="triggerLabel"
			data-testid="site-edit-button"
			@click="toggle"
			@keydown.down.prevent="openMenu(0)"
			@keydown.up.prevent="openMenu(actions.length - 1)"
			@keydown.esc="close">
			<!--
				The pencil is drawn inline rather than pulled from an icon font
				or a sprite: this bundle is downloaded before a public portal
				renders and is held to a hard size budget, and one path is
				cheaper than any icon dependency. `aria-hidden` because the
				button already carries its own label.
			-->
			<svg
				class="pq-edit__icon"
				viewBox="0 0 24 24"
				width="24"
				height="24"
				aria-hidden="true"
				focusable="false">
				<path
					fill="currentColor"
					d="M20.71 7.04a1 1 0 0 0 0-1.41l-2.34-2.34a1 1 0 0 0-1.41 0l-1.83 1.83 3.75 3.75M3 17.25V21h3.75L17.81 9.94l-3.75-3.75L3 17.25Z" />
			</svg>
		</button>
	</div>
</template>

<script>
/**
 * The site's editing entry point.
 *
 * WHY THE SITE CARRIES ONLY THE DOOR AND NOT THE ROOM. The editing itself —
 * the grid, the widget palette, the property forms — lives in the Nextcloud
 * app, because this bundle is what an anonymous visitor downloads on a phone
 * before anything renders and `webpack.site.js` enforces that with
 * `hints: 'error'` at 400 KiB. GridStack alone is comparable to this entire
 * bundle. What genuinely belongs here is the one thing the app cannot know:
 * which page the visitor is looking at.
 */
export default {
	name: 'SiteEditButton',

	props: {
		/**
		 * The editing context from the probe: `{pageId, designerUrl,
		 * pagesUrl, newPageUrl}`. Null for every visitor who may not edit, and
		 * the component then renders nothing at all.
		 */
		context: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			open: false,
			activeIndex: 0,
			// Rebuilt on every render pass; see `setItemRef`.
			items: [],
			menuId: 'pq-edit-menu',
			triggerLabel: 'Deze site bewerken',
			menuLabel: 'Bewerkacties',
		}
	},

	computed: {
		/**
		 * The menu's actions, in the order an editor needs them.
		 *
		 * "Edit this page" is offered only when the probe resolved a page at
		 * this route. A route with no page behind it — a client-side route, a
		 * 404, a detail view under a parent — has nothing to open, and an entry
		 * that navigates to a designer for no page is worse than its absence.
		 *
		 * @return {Array<object>} The actions.
		 */
		actions() {
			const actions = []
			if (this.context?.designerUrl) {
				actions.push({
					key: 'page',
					label: 'Deze pagina bewerken',
					href: this.context.designerUrl,
				})
			}

			if (this.context?.pagesUrl) {
				actions.push({
					key: 'pages',
					label: 'Alle pagina’s',
					href: this.context.pagesUrl,
				})
			}

			if (this.context?.newPageUrl) {
				actions.push({
					key: 'new',
					label: 'Nieuwe pagina',
					href: this.context.newPageUrl,
				})
			}

			return actions
		},
	},

	beforeUpdate() {
		// Vue 3 does not clear function refs between renders, so the list is
		// reset here and refilled by `setItemRef` during the render that
		// follows. Without this the array grows on every open/close cycle and
		// the arrow keys start addressing detached nodes.
		this.items = []
	},

	mounted() {
		document.addEventListener('click', this.onDocumentClick)
	},

	beforeUnmount() {
		document.removeEventListener('click', this.onDocumentClick)
	},

	methods: {
		/**
		 * Collect a menu item's element for keyboard navigation.
		 *
		 * @param {object} el The element or component instance.
		 * @return {void}
		 */
		setItemRef(el) {
			if (el) {
				this.items.push(el)
			}
		},

		/**
		 * Toggle the menu from the trigger.
		 *
		 * @return {void}
		 */
		toggle() {
			if (this.open) {
				this.close()
				return
			}

			this.openMenu(0)
		},

		/**
		 * Open the menu and focus one item.
		 *
		 * @param {number} index The item to focus.
		 * @return {void}
		 */
		openMenu(index) {
			if (this.actions.length === 0) {
				return
			}

			this.open = true
			this.activeIndex = Math.max(0, Math.min(this.actions.length - 1, index))
			this.$nextTick(() => this.focusActive())
		},

		/**
		 * Close the menu and return focus to the trigger.
		 *
		 * Returning focus is not a nicety: without it a keyboard user who
		 * presses Escape is left with focus on a removed node, which browsers
		 * reset to the document body — sending them back to the top of a page
		 * they had navigated to the bottom of.
		 *
		 * @return {void}
		 */
		close() {
			if (!this.open) {
				return
			}

			this.open = false
			this.$nextTick(() => this.$refs.trigger?.focus())
		},

		/**
		 * Keyboard handling inside the menu.
		 *
		 * @param {KeyboardEvent} event The event.
		 * @return {void}
		 */
		onMenuKey(event) {
			if (event.key === 'Escape') {
				event.preventDefault()
				this.close()
				return
			}

			if (event.key === 'ArrowDown') {
				event.preventDefault()
				this.move(1)
				return
			}

			if (event.key === 'ArrowUp') {
				event.preventDefault()
				this.move(-1)
				return
			}

			if (event.key === 'Tab') {
				// Tabbing out of a menu closes it, which is what every native
				// menu does; leaving it open would strand an invisible-to-them
				// popup behind a user who has moved on.
				this.open = false
			}
		},

		/**
		 * Move the active item, wrapping at both ends.
		 *
		 * @param {number} delta The direction.
		 * @return {void}
		 */
		move(delta) {
			const count = this.actions.length
			if (count === 0) {
				return
			}

			this.activeIndex = (this.activeIndex + delta + count) % count
			this.focusActive()
		},

		/**
		 * Focus the active menu item.
		 *
		 * @return {void}
		 */
		focusActive() {
			this.items[this.activeIndex]?.focus?.()
		},

		/**
		 * Close on a click anywhere outside the control.
		 *
		 * @param {MouseEvent} event The event.
		 * @return {void}
		 */
		onDocumentClick(event) {
			if (!this.open) {
				return
			}

			if (this.$el instanceof Node && this.$el.contains(event.target)) {
				return
			}

			this.open = false
		},
	},
}
</script>

<style scoped>
.pq-edit {
	position: fixed;
	inset-inline-end: 1.5rem;
	bottom: 1.5rem;
	z-index: 100;
	display: flex;
	flex-direction: column;
	align-items: flex-end;
	gap: 0.5rem;
}

.pq-edit__trigger {
	/* 3rem square: comfortably past the 24x24 CSS-pixel minimum WCAG 2.2
	   Target Size (Minimum) asks for, on a control that sits over content and
	   is used on touch screens. */
	width: 3rem;
	height: 3rem;
	border: 2px solid transparent;
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	cursor: pointer;
	color: var(--pq-edit-color, #fff);
	background: var(
		--pq-edit-background,
		var(--utrecht-button-primary-action-background-color, #0078c8)
	);
	box-shadow: 0 2px 8px rgb(0 0 0 / 30%);
}

.pq-edit__trigger:hover {
	filter: brightness(0.92);
}

.pq-edit__trigger:focus-visible {
	/* An outline that does not rely on the theme having set a focus colour:
	   this control floats over arbitrary page content, so a focus ring in a
	   single colour is a coin flip against the background behind it. */
	outline: 3px solid var(--pq-edit-focus, #000);
	outline-offset: 2px;
}

.pq-edit__menu {
	margin: 0;
	padding: 0.25rem;
	list-style: none;
	min-width: 14rem;
	border-radius: 0.5rem;
	background: var(--pq-edit-menu-background, #fff);
	color: var(--pq-edit-menu-color, #1b1b1b);
	box-shadow: 0 2px 12px rgb(0 0 0 / 25%);
}

.pq-edit__item {
	display: block;
	padding: 0.625rem 0.75rem;
	border-radius: 0.25rem;
	color: inherit;
	text-decoration: none;
}

.pq-edit__item:hover,
.pq-edit__item:focus-visible {
	background: var(--pq-edit-item-hover, rgb(0 0 0 / 8%));
	text-decoration: underline;
}

@media print {
	/* An editing control has no meaning on paper, and it would print over the
	   page's own content. */
	.pq-edit {
		display: none;
	}
}
</style>
