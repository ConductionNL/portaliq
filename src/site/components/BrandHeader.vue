<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<!--
		The masthead as a block. The markup is the header `App.vue` hard-coded
		before this change, with one deliberate difference: the site name is a
		`span`, so the page's own content owns the one `h1`.

		`double` (the default) keeps the navigation in its own bar below the
		masthead. `single` puts it on the masthead's row and renders no second
		bar, so each link is in the accessibility tree once.
	-->
	<header
		class="ac-header pq-site__header"
		:class="{ 'pq-site__header--designed': designed }"
		data-testid="site-header">
		<div class="ac-header__navigation-main">
			<div class="ac-header__logo">
				<div>
					<div class="con-logo-container header" />
					<span class="sr-only">{{ logoLabel }}</span>
					<span class="logo-text" data-testid="site-title">
						{{ title || '…' }}
					</span>
				</div>
			</div>

			<div
				v-if="single && showNavigation"
				class="ac-c-navigation__container pq-site__header-nav"
				data-testid="site-header-nav">
				<SiteMenu
					v-for="menu in menus"
					:key="menu.title"
					:menu="menu"
					:currentRoute="currentRoute"
					@navigate="$emit('navigate', $event)" />
			</div>

			<!-- The sign-in controls appear only when the portal declares a
			     mode other than `public`: an inert login button is a support
			     ticket from every visitor who presses it. A failed sign-in is
			     said even without them: it is about the attempt that came
			     back, not about the ways in. -->
			<div class="ac-header__right-section">
				<!-- A designed header (site-chrome-follows-the-design): search,
				     one way to the own area, the person chip, loaded on demand. -->
				<template v-if="designed">
					<p
						v-if="signinFailedMessage && !session"
						role="alert"
						data-testid="site-signin-failed">
						{{ signinFailedMessage }}
					</p>
					<HeaderTools
						:searchBox="searchBox"
						:searchLabel="searchLabel"
						:accountLabel="accountLabel"
						:accountHref="accountHref"
						:session="session"
						:accountLink="accountLink"
						:signOutLabel="signOutLabel"
						:hasMenu="showNavigation && menus.length > 0"
						:menuOpen="menuOpen"
						:menuLabel="menuLabel"
						@search="$emit('search', $event)"
						@navigate="$emit('navigate', $event)"
						@signout="$emit('signout')"
						@toggleMenu="menuOpen = !menuOpen" />
				</template>
				<div
					v-else-if="session || signInRoutes.length || signinFailedMessage"
					class="ac-navigation pq-site__auth"
					data-testid="site-auth">
					<template v-if="session">
						<!-- What the shell adds beside the signed-in name, such as
						     whom the resident acts for. -->
						<slot name="account" />
						<span data-testid="site-auth-subject"
							><template v-if="sessionParts"
								>{{ sessionParts.before
								}}<NoTranslate :value="sessionParts.value" />{{
									sessionParts.after
								}}</template
							><template v-else>{{ sessionLabel }}</template></span
						>
						<!-- The way to the resident's own area, on every page
						     (site-resident-menu REQ-SRM-003). -->
						<a
							v-if="accountLink"
							class="utrecht-link pq-site__own-area"
							:href="accountLink.href"
							data-testid="site-own-area"
							@click.prevent="$emit('navigate', accountLink.route)">
							{{ accountLink.label }}
						</a>
						<button
							type="button"
							class="utrecht-button utrecht-button--secondary-action pq-site__signout"
							data-testid="site-signout"
							@click="$emit('signout')">
							{{ signOutLabel }}
						</button>
					</template>
					<nav v-else-if="signInRoutes.length" :aria-label="userMenuLabel">
						<!-- A failed sign-in the edge sent back (REQ-BEL-006). -->
						<p
							v-if="signinFailedMessage"
							role="alert"
							data-testid="site-signin-failed">
							{{ signinFailedMessage }}
						</p>
						<ul>
							<li v-if="registerRoute">
								<a
									:href="registerRoute.href"
									data-testid="site-register">
									{{ registerRoute.label || registerLabel }}
								</a>
							</li>
							<li v-for="entry in signInRoutes" :key="entry.mode">
								<a
									:href="entry.href"
									:data-mode="entry.mode"
									data-testid="site-signin">
									{{ entry.label }}
								</a>
							</li>
						</ul>
					</nav>
					<p v-else role="alert" data-testid="site-signin-failed">
						{{ signinFailedMessage }}
					</p>
				</div>
			</div>
		</div>

		<!-- No navigation bar when the page carries a menu block, so every
		     link is on the page once (site-navigation-block). -->
		<div
			v-if="!single && showNavigation"
			:id="designed ? 'pq-site-navigation' : undefined"
			class="ac-header__navigation-secondary"
			:class="{ 'pq-site__nav--open': designed && menuOpen }">
			<div class="container">
				<div class="ac-c-navigation__container">
					<SiteMenu
						v-for="menu in menus"
						:key="menu.title"
						:menu="menu"
						:currentRoute="currentRoute"
						@navigate="$emit('navigate', $event)" />
				</div>
			</div>
		</div>

		<!-- The trail renders only below the home route, and its last crumb
		     is the current page, not a link. -->
		<div class="ac-header__navigation-breadcrumb">
			<div class="container">
				<nav
					v-if="breadcrumbs.length > 1"
					class="ac-breadcrumb"
					:aria-label="breadcrumbLabel"
					data-testid="site-breadcrumb">
					<ul class="ac-breadcrumb__list">
						<li
							v-for="(crumb, index) in breadcrumbs"
							:key="crumb.route"
							class="ac-breadcrumb__item">
							<a
								v-if="index < breadcrumbs.length - 1"
								class="utrecht-link"
								:href="crumb.href"
								@click.prevent="$emit('navigate', crumb.route)">
								{{ crumb.label }}
							</a>
							<span v-else aria-current="page">{{ crumb.label }}</span>
							<span
								v-if="index < breadcrumbs.length - 1"
								class="ac-breadcrumb__separator"
								aria-hidden="true">
								›
							</span>
						</li>
					</ul>
				</nav>
			</div>
		</div>
	</header>
</template>

<script>
import { defineAsyncComponent } from 'vue'
import NoTranslate from './NoTranslate.vue'
import SiteMenu from './SiteMenu.vue'
import { markAround } from '../lib/markAround.js'

/**
 * The portal's header block (`brandHeader`).
 *
 * Every visible string is a prop whose default is the Dutch the shell shipped,
 * navigation and sign-out are emitted, and nothing here reads a router, a
 * translation function or a Nextcloud global, so the block mounts at a public
 * origin and in an editor canvas alike.
 *
 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-header-must-be-a-block-whose-shape-the-portal-chooses-req-ptb-004
 */
export default {
	name: 'BrandHeader',

	components: {
		NoTranslate,
		SiteMenu,
		// Only a designed header loads it (site-chrome-follows-the-design).
		HeaderTools: defineAsyncComponent(() => import('./chrome/HeaderTools.vue')),
	},

	props: {
		/** The portal's name, beside the mark. */
		title: { type: String, default: '' },
		/** `double` or `single`; anything else renders `double`. */
		variant: { type: String, default: 'double' },
		/** The header menus. */
		menus: { type: Array, default: () => [] },
		/** False when the page carries a menu block: the header shows no menu. */
		showNavigation: { type: Boolean, default: true },
		/** The route on screen, to mark the current menu item. */
		currentRoute: { type: String, default: '/' },
		/** `{route, label, href}` crumbs, home first. */
		breadcrumbs: { type: Array, default: () => [] },
		/** The visitor's portal session, or null. */
		session: { type: Object, default: null },
		/** How to name the signed-in visitor. */
		sessionLabel: { type: String, default: '' },
		/** `{route, href, label}` of the resident's own area, or null. */
		accountLink: { type: Object, default: null },
		/** The sign-in routes the portal declares. */
		signInRoutes: { type: Array, default: () => [] },
		/** The message for a sign-in the edge refused; empty shows nothing. */
		signinFailedMessage: { type: String, default: '' },
		/** `{href, label}` when the portal declares a register destination. */
		registerRoute: { type: Object, default: null },
		/** The register control's label when the portal names none. */
		registerLabel: { type: String, default: 'Registreren' },
		/** The sign-out control's label. */
		signOutLabel: { type: String, default: 'Uitloggen' },
		/** The account navigation's accessible name. */
		userMenuLabel: { type: String, default: 'Gebruikersmenu' },
		/** The breadcrumb landmark's accessible name. */
		breadcrumbLabel: { type: String, default: 'Kruimelpad' },
		/** The screen-reader text beside the logo mark. */
		logoLabel: { type: String, default: 'Logo' },
		/** `{enabled, placeholder}`: the search box (site-chrome-follows-the-design). */
		searchBox: { type: Object, default: () => ({ enabled: false }) },
		/** The search button's accessible name. */
		searchLabel: { type: String, default: 'Zoeken' },
		/** The one button to the own area while signed out; empty keeps the sign-in links. */
		accountLabel: { type: String, default: '' },
		/** The own area's real address. */
		accountHref: { type: String, default: '' },
		/** The phone menu button's text. */
		menuLabel: { type: String, default: 'Menu' },
	},

	emits: ['navigate', 'signout', 'search'],

	data() {
		return { menuOpen: false }
	},

	computed: {
		/**
		 * The signed-in line split around the resident's name, so the name stays
		 * untranslated and "Ingelogd als" does not.
		 *
		 * @return {{before: string, value: string, after: string}|null}
		 *
		 * @spec openspec/changes/personal-data-left-untranslated/specs/site-chrome/spec.md#requirement-browser-translation-leaves-names-and-personal-data-alone-req-pdu-001
		 */
		sessionParts() {
			const name = String(
				this.session?.displayName || this.session?.name || '',
			).trim()
			return markAround(this.sessionLabel, name)
		},

		/**
		 * Whether the navigation shares the masthead's row.
		 *
		 * @return {boolean} True for the `single` variant only.
		 *
		 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-header-must-be-a-block-whose-shape-the-portal-chooses-req-ptb-004
		 */
		single() {
			return this.variant === 'single'
		},

		/**
		 * Whether the portal asked for the designed header: a search box or one
		 * button to its own area. A portal that asks for neither keeps the
		 * header it has, markup and all.
		 *
		 * @return {boolean}
		 *
		 * @spec openspec/changes/site-chrome-follows-the-design/specs/site-chrome/spec.md#requirement-the-header-must-carry-the-search-box-and-one-way-to-the-own-area
		 */
		designed() {
			return Boolean(this.accountLabel) || this.searchBox.enabled === true
		},
	},
}
</script>

<style scoped>
/*
 * THE SIGN-OUT BUTTON WAS IN THE DOM AND NOWHERE ON SCREEN.
 *
 * `nlds-app.css` carries `.ac-navigation button { display: none }`, a rule for
 * the reference's mobile menu toggle, and this block's auth controls sit in an
 * `.ac-navigation`. Measured on :8090 signed in as a guardian: the button had
 * `display: none`, a 0x0 box, and was absent from the accessibility tree, so a
 * resident could sign in and had no way to sign out. Two classes outrank the
 * vendored rule's one class and one element.
 */
.pq-site__auth {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 12px;
}

.pq-site__auth .pq-site__signout {
	display: inline-flex;
}

/*
 * ON A PHONE THE MASTHEAD WRAPS. Its height is fixed by the design system,
 * and signed in it holds the name, "Mijn omgeving" and "Uitloggen" beside
 * the logo. Measured at 390 px: the site name was cut to "N TILB" and the
 * sign-out button sat over the blue bar. Below tablet width the controls
 * take their own row under the logo instead.
 */
@media (max-width: 767px) {
	.pq-site__header .ac-header__navigation-main {
		flex-wrap: wrap;
		block-size: auto;
		min-block-size: var(--navigation-bar-height, 72px);
		row-gap: 8px;
		padding-block-end: 8px;
	}

	.pq-site__header .ac-header__right-section {
		margin-inline: 16px;
	}
}

/*
 * THE BREADCRUMB IS ONE LINE. App.vue carried this rule scoped, and a scoped
 * rule never reaches a child component's elements, so the trail rendered as
 * three stacked lines on every page. It belongs with the markup it styles.
 */
.ac-breadcrumb__list {
	display: flex;
	align-items: center;
	flex-wrap: wrap;
	gap: 8px;
	list-style: none;
	margin: 0;
	padding: 0;
}

.ac-breadcrumb__item {
	display: flex;
	align-items: center;
	gap: 8px;
}
</style>
