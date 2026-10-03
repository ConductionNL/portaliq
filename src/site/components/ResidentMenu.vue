<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<!--
		THE RESIDENT'S OWN MENU, BESIDE THE CONTENT ON EVERY `/mijn` PAGE.

		The groups come from lib/residentMenu.js. A group's name is a plain
		label, not a heading: the menu stands before the page's own h1, and a
		heading here would put level two above level one.

		On a phone the list folds behind one button, so the page opens on its
		content and never scrolls sideways. The button says whether the list is
		open (`aria-expanded`) and which list it opens (`aria-controls`).
	-->
	<nav
		class="pq-resident-menu"
		:aria-label="label"
		data-testid="site-resident-menu">
		<button
			type="button"
			class="utrecht-button utrecht-button--secondary-action pq-resident-menu__toggle"
			:aria-expanded="String(open)"
			:aria-controls="listId"
			data-testid="site-resident-menu-toggle"
			@click="open = !open">
			{{ open ? hideLabel : showLabel }}
		</button>
		<div
			:id="listId"
			class="pq-resident-menu__groups"
			:class="{ 'pq-resident-menu__groups--open': open }"
			data-testid="site-resident-menu-groups">
			<div
				v-for="group in groups"
				:key="group.key"
				class="denhaag-sidenav pq-resident-menu__group"
				data-testid="site-resident-menu-group">
				<p :id="groupId(group)" class="pq-resident-menu__title">
					{{ group.title }}
				</p>
				<ul
					class="denhaag-sidenav__list pq-resident-menu__list"
					:aria-labelledby="groupId(group)">
					<li
						v-for="item in group.items"
						:key="item.key"
						class="denhaag-sidenav__item">
						<a
							class="denhaag-sidenav__link pq-resident-menu__link"
							:class="{
								'denhaag-sidenav__link--current': isCurrent(
									item.link,
								),
								'pq-resident-menu__link--current': isCurrent(
									item.link,
								),
							}"
							:href="item.href || item.link"
							:aria-current="isCurrent(item.link) ? 'page' : undefined"
							data-testid="site-resident-menu-link"
							@click.prevent="select(item.link)">
							<!-- The page's icon, decorative: the label says it all
							     (site-mijn-omgeving-components REQ-SMO-006). -->
							<svg
								v-if="iconPath(item)"
								class="pq-resident-menu__icon"
								viewBox="0 0 24 24"
								aria-hidden="true"
								focusable="false">
								<path :d="iconPath(item)" fill="currentColor" />
							</svg>
							<span
								class="denhaag-sidenav__link-label pq-resident-menu__label"
								>{{ item.name }}</span
							>
							<span
								v-if="item.badge"
								class="pq-resident-menu__badge"
								data-testid="site-resident-menu-badge">
								<span aria-hidden="true">{{ item.badge }}</span>
								<span class="pq-resident-menu__sr">{{
									item.badgeLabel || item.badge
								}}</span>
							</span>
						</a>
					</li>
				</ul>
			</div>
		</div>
	</nav>
</template>

<script>
/**
 * The resident's own menu (`/mijn` pages only).
 *
 * @spec openspec/changes/site-resident-menu/specs/site-resident-menu/spec.md#requirement-the-residents-own-items-must-sit-in-a-menu-beside-the-content-req-srm-002
 */
export default {
	name: 'ResidentMenu',

	props: {
		/** The groups, from residentMenuGroups(). */
		groups: { type: Array, default: () => [] },
		/** The route on screen, to mark the current item. */
		currentRoute: { type: String, default: '' },
		/** The landmark's accessible name. */
		label: { type: String, default: 'Mijn omgeving' },
		/** The phone button's text while the list is closed. */
		showLabel: { type: String, default: 'Menu mijn omgeving' },
		/** The phone button's text while the list is open. */
		hideLabel: { type: String, default: 'Menu sluiten' },
	},

	emits: ['navigate'],

	data() {
		return {
			/** Whether the list is open on a phone; wider screens always show it. */
			open: false,
			listId: 'pq-resident-menu-list',
			/** The icon paths by name, once loaded; none until then. */
			icons: {},
		}
	},

	/**
	 * Load the Den Haag side navigation CSS and the icons on demand, so the
	 * site's entry carries neither (site-mijn-omgeving-components D1).
	 *
	 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-the-resident-menu-must-show-icons-and-counts-in-groups-req-smo-006
	 */
	mounted() {
		import('@gemeente-denhaag/sidenav/index.css').catch(() => {})
		import('../lib/menuIcons.js')
			.then((module) => {
				this.icons = module.default || {}
			})
			.catch(() => {})
	},

	methods: {
		/**
		 * The path of an item's icon, or '' when it names none this menu has.
		 *
		 * @param {object} item A menu item.
		 * @return {string} The SVG path data.
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-the-resident-menu-must-show-icons-and-counts-in-groups-req-smo-006
		 */
		iconPath(item) {
			return (item.icon && this.icons[item.icon]) || ''
		},

		/**
		 * Whether an item is the page on screen. Drives `aria-current`, so the
		 * place is announced and not only coloured.
		 *
		 * @param {string} link The item's route.
		 * @return {boolean} True for the page on screen.
		 *
		 * @spec openspec/changes/site-resident-menu/specs/site-resident-menu/spec.md#requirement-the-residents-own-items-must-sit-in-a-menu-beside-the-content-req-srm-002
		 */
		isCurrent(link) {
			return link === this.currentRoute
		},

		/**
		 * Go to an item and fold the list again, so on a phone the chosen page
		 * is what the resident sees next.
		 *
		 * @param {string} link The item's route.
		 * @return {void}
		 *
		 * @spec openspec/changes/site-resident-menu/specs/site-resident-menu/spec.md#requirement-the-menu-must-fold-behind-a-button-on-a-phone-req-srm-004
		 */
		select(link) {
			this.open = false
			this.$emit('navigate', link)
		},

		/**
		 * @param {object} group A group.
		 * @return {string} The id of its label.
		 *
		 * @spec openspec/changes/site-resident-menu/specs/site-resident-menu/spec.md#requirement-the-residents-own-items-must-sit-in-a-menu-beside-the-content-req-srm-002
		 */
		groupId(group) {
			return `pq-resident-menu-${String(group.key).replace(/[^a-z0-9-]/gi, '-')}`
		},
	},
}
</script>

<style scoped>
.pq-resident-menu {
	min-inline-size: 0;
}

.pq-resident-menu__toggle {
	display: none;
}

.pq-resident-menu__group + .pq-resident-menu__group {
	margin-block-start: 24px;
}

.pq-resident-menu__title {
	margin: 0 0 8px;
	color: var(--utrecht-document-color, CanvasText);
	font-size: 0.875em;
	font-weight: 700;
}

.pq-resident-menu__list {
	margin: 0;
	padding: 0;
	list-style: none;
}

.pq-resident-menu__link {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	padding: 8px 12px;
	border-inline-start: 4px solid transparent;
	color: var(--utrecht-link-color, LinkText);
	text-decoration: none;
	overflow-wrap: anywhere;
}

.pq-resident-menu__icon {
	flex-shrink: 0;
	inline-size: 1.25rem;
	block-size: 1.25rem;
}

.pq-resident-menu__label {
	flex-grow: 1;
}

.pq-resident-menu__link:hover {
	text-decoration: underline;
}

/* The page on screen: the accent colour, a bar beside it and bold text, so
   the place never rests on colour alone. */
.pq-resident-menu__link--current {
	border-inline-start-color: var(
		--utrecht-link-active-color,
		var(--utrecht-link-color, LinkText)
	);
	background: var(
		--utrecht-surface-background-color,
		var(--utrecht-document-background-color, Canvas)
	);
	color: var(--utrecht-link-active-color, var(--utrecht-link-color, LinkText));
	font-weight: 700;
}

.pq-resident-menu__link:focus-visible {
	outline: 2px solid var(--pq-focus-color, CanvasText);
	outline-offset: 2px;
}

.pq-resident-menu__badge {
	display: inline-block;
	min-inline-size: 1.5em;
	padding: 0 6px;
	border-radius: 999px;
	background: var(
		--utrecht-badge-counter-background-color,
		var(--utrecht-document-color, CanvasText)
	);
	color: var(
		--utrecht-badge-counter-color,
		var(--utrecht-document-background-color, Canvas)
	);
	font-size: 0.85em;
	line-height: 1.5;
	text-align: center;
}

.pq-resident-menu__sr {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
}

/* A phone: the list folds behind the button until the resident opens it. */
@media (max-width: 767px) {
	.pq-resident-menu__toggle {
		display: inline-flex;
		margin-block-end: 16px;
	}

	.pq-resident-menu__groups {
		display: none;
	}

	.pq-resident-menu__groups--open {
		display: block;
		margin-block-end: 24px;
	}
}
</style>
