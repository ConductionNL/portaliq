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
		<!-- Whom the resident acts for, at the top of the menu
		     (resident-menu-badges-and-cards, G-06). -->
		<div
			v-if="card"
			class="pq-resident-menu__card"
			data-testid="site-resident-menu-card">
			<p class="pq-resident-menu__card-label">{{ card.label }}</p>
			<p class="pq-resident-menu__card-title">{{ card.title }}</p>
			<p v-if="card.subline" class="pq-resident-menu__card-subline">
				{{ card.subline }}
			</p>
		</div>
		<!-- The person the menu belongs to, with their class or role
		     (resident-menu-follows-the-boards). The name is text, not a
		     heading: the page's own h1 follows the menu. -->
		<div
			v-else-if="person"
			class="pq-resident-menu__person"
			data-testid="site-resident-menu-person">
			<span class="pq-resident-menu__avatar" aria-hidden="true">{{
				person.initials
			}}</span>
			<span class="pq-resident-menu__who">
				<span class="pq-resident-menu__person-name">{{ person.name }}</span>
				<span
					v-if="person.subline"
					class="pq-resident-menu__person-subline"
					data-testid="site-resident-menu-person-subline"
					>{{ person.subline }}</span
				>
			</span>
		</div>
		<button
			type="button"
			class="utrecht-button utrecht-button--secondary-action pq-resident-menu__toggle"
			:aria-expanded="String(open)"
			:aria-controls="listId"
			data-testid="site-resident-menu-toggle"
			@click="open = !open">
			{{ open ? hideLabel : showLabel }}
			<span
				v-if="!open && newCount > 0"
				class="pq-resident-menu__toggle-badge"
				data-testid="site-resident-menu-toggle-badge">
				{{ newLabel.replace('{count}', String(newCount)) }}
			</span>
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
								>{{ item.name
								}}<span
									v-if="item.subline"
									class="pq-resident-menu__subline"
									data-testid="site-resident-menu-subline"
									>{{ item.subline }}</span
								></span
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
			<!-- On a phone with the person in the header, signing out sits at
			     the end of the menu (mijn-phone-chrome). -->
			<button
				v-if="signOutLabel"
				type="button"
				class="utrecht-button utrecht-button--subtle pq-resident-menu__signout"
				data-testid="site-resident-menu-signout"
				@click="$emit('signout')">
				{{ signOutLabel }}
			</button>
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
		/** `{label, title, subline?}`: whom the resident acts for, or null. */
		card: { type: Object, default: null },
		/** `{initials, name, subline}`: the person block, or null. */
		person: { type: Object, default: null },
		/** The sign-out button at the end of the menu on a phone, '' for none. */
		signOutLabel: { type: String, default: '' },
		/** The phone button's count text, `{count}` for the number ("2 nieuw"). */
		newLabel: { type: String, default: '{count} nieuw' },
	},

	emits: ['navigate', 'signout'],

	data() {
		return {
			/** Whether the list is open on a phone; wider screens always show it. */
			open: false,
			listId: 'pq-resident-menu-list',
			/** The icon paths by name, once loaded; none until then. */
			icons: {},
		}
	},

	computed: {
		/**
		 * The sum of the counts in the menu, for the phone button
		 * (resident-menu-badges-and-cards).
		 *
		 * @return {number}
		 *
		 * @spec openspec/changes/resident-menu-badges-and-cards/specs/site-resident-menu/spec.md#requirement-a-menu-entry-may-show-the-count-of-a-collection
		 */
		newCount() {
			return this.groups
				.flatMap((group) => group.items)
				.reduce((sum, item) => sum + (Number(item.badge) || 0), 0)
		},
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
/* The menu as the school boards draw it (resident-menu-follows-the-boards):
   uppercase group labels, roomy items, the page on screen on the accent's
   light wash, counts on the accent. Colours from the theme only. */
.pq-resident-menu {
	min-inline-size: 0;
	display: flex;
	flex-direction: column;
	gap: 22px;
}

.pq-resident-menu__toggle {
	display: none;
}

.pq-resident-menu__groups {
	display: flex;
	flex-direction: column;
	gap: 22px;
}

.pq-resident-menu__title {
	margin: 0 0 6px;
	padding: 0 12px;
	color: var(
		--nldesign-color-text-muted,
		var(--utrecht-document-color, CanvasText)
	);
	font-size: 0.8125rem;
	font-weight: 700;
	letter-spacing: 0.05em;
	text-transform: uppercase;
}

.pq-resident-menu__list {
	display: flex;
	flex-direction: column;
	gap: 2px;
	margin: 0;
	padding: 0;
	list-style: none;
}

.pq-resident-menu__link {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 8px;
	box-sizing: border-box;
	min-block-size: 44px;
	padding: 6px 12px;
	border-radius: var(--nldesign-website-border-radius, 6px);
	color: var(--utrecht-link-color, LinkText);
	font-weight: 500;
	text-decoration: none;
	/* A word breaks only when it cannot fit on a line of its own, never in
	   the middle because a second line stood beside it. */
	overflow-wrap: break-word;
}

.pq-resident-menu__icon {
	flex-shrink: 0;
	inline-size: 1.25rem;
	block-size: 1.25rem;
}

.pq-resident-menu__label {
	flex-grow: 1;
	min-inline-size: 0;
}

/* A row's second line ("Groep 7 · Meester Daan") under its name. */
.pq-resident-menu__subline {
	display: block;
	color: var(
		--nldesign-color-text-muted,
		var(--utrecht-document-color, CanvasText)
	);
	font-size: 0.875rem;
	font-weight: 400;
}

.pq-resident-menu__link:hover {
	text-decoration: underline;
}

/* The page on screen: the accent's light wash, its text colour and bold
   text, and `aria-current` for a screen reader, so the place never rests on
   colour alone. */
.pq-resident-menu__link--current {
	background: var(
		--thematiq-accent-light-color,
		var(--nldesign-color-accent-light, Canvas)
	);
	color: var(
		--thematiq-accent-text-color,
		var(--nldesign-color-accent-text, CanvasText)
	);
	font-weight: 700;
}

.pq-resident-menu__link:focus-visible {
	outline: 2px solid var(--pq-focus-color, CanvasText);
	outline-offset: 2px;
}

.pq-resident-menu__badge {
	display: inline-flex;
	flex-shrink: 0;
	align-items: center;
	justify-content: center;
	box-sizing: border-box;
	min-inline-size: 24px;
	block-size: 24px;
	padding: 0 7px;
	border-radius: 12px;
	background: var(
		--thematiq-badge-background-color,
		var(
			--utrecht-badge-counter-background-color,
			var(--utrecht-document-color, CanvasText)
		)
	);
	color: var(
		--thematiq-badge-color,
		var(
			--utrecht-badge-counter-color,
			var(--utrecht-document-background-color, Canvas)
		)
	);
	font-size: 0.8125rem;
	font-weight: 700;
	line-height: 1;
}

/* The phone button's count ("2 nieuw"): a pill on the accent. */
.pq-resident-menu__toggle-badge {
	margin-inline-start: 8px;
	padding: 2px 10px;
	border-radius: 999px;
	background: var(--thematiq-badge-background-color, CanvasText);
	color: var(--thematiq-badge-color, Canvas);
	font-size: 0.8125rem;
	font-weight: 700;
}

/* The person block: initials in a circle, the name and the class. */
.pq-resident-menu__person {
	display: flex;
	align-items: center;
	gap: 12px;
	padding: 0 12px 4px;
}

.pq-resident-menu__avatar {
	display: flex;
	flex: none;
	align-items: center;
	justify-content: center;
	inline-size: 44px;
	block-size: 44px;
	border-radius: 50%;
	background: var(--nldesign-color-primary-light, Canvas);
	color: var(--nldesign-color-primary-hover, CanvasText);
	font-size: 0.9375rem;
	font-weight: 700;
}

.pq-resident-menu__who {
	display: flex;
	flex-direction: column;
	min-inline-size: 0;
	line-height: 1.25;
}

.pq-resident-menu__person-name {
	font-size: 1.0625rem;
	font-weight: 700;
}

.pq-resident-menu__person-subline,
.pq-resident-menu__card-subline {
	color: var(
		--nldesign-color-text-muted,
		var(--utrecht-document-color, CanvasText)
	);
	font-size: 0.9375rem;
}

/* The card of whom the resident acts for: a tinted panel. */
.pq-resident-menu__card {
	padding: 14px 16px;
	border-radius: var(--nldesign-website-border-radius-large, 8px);
	background: var(
		--nldesign-component-content-surface-background-color,
		var(--nldesign-color-primary-light, Canvas)
	);
}

.pq-resident-menu__card p {
	margin: 0;
}

.pq-resident-menu__card-label {
	color: var(
		--nldesign-color-text-muted,
		var(--utrecht-document-color, CanvasText)
	);
	font-size: 0.8125rem;
	font-weight: 700;
	letter-spacing: 0.05em;
	text-transform: uppercase;
}

.pq-resident-menu__card-title {
	font-weight: 700;
}

.pq-resident-menu__sr {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
}

/* Signing out at the end of the menu: on a phone only, where the header
   shows the person instead (mijn-phone-chrome). */
.pq-resident-menu__signout {
	display: none;
	align-self: flex-start;
	padding-inline: 12px;
	color: var(--utrecht-link-color, LinkText);
	text-decoration: underline;
}

/* A phone: the list folds behind the button until the resident opens it. */
@media (max-width: 767px) {
	.pq-resident-menu {
		gap: 12px;
	}

	.pq-resident-menu__toggle {
		display: inline-flex;
		align-self: flex-start;
	}

	.pq-resident-menu__groups {
		display: none;
	}

	.pq-resident-menu__groups--open {
		display: flex;
		margin-block-end: 24px;
	}

	.pq-resident-menu__signout {
		display: inline-flex;
	}
}
</style>
