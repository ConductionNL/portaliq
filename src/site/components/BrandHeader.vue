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
	<header class="ac-header pq-site__header" data-testid="site-header">
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
			     ticket from every visitor who presses it. -->
			<div class="ac-header__right-section">
				<div
					v-if="session || signInRoutes.length"
					class="ac-navigation pq-site__auth"
					data-testid="site-auth">
					<template v-if="session">
						<!-- What the shell adds beside the signed-in name, such as
						     whom the resident acts for. -->
						<slot name="account" />
						<span data-testid="site-auth-subject">{{
							sessionLabel
						}}</span>
						<button
							type="button"
							class="utrecht-button utrecht-button--secondary-action pq-site__signout"
							data-testid="site-signout"
							@click="$emit('signout')">
							{{ signOutLabel }}
						</button>
					</template>
					<nav v-else :aria-label="userMenuLabel">
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
				</div>
			</div>
		</div>

		<!-- No navigation bar when the page carries a menu block, so every
		     link is on the page once (site-navigation-block). -->
		<div
			v-if="!single && showNavigation"
			class="ac-header__navigation-secondary">
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
import SiteMenu from './SiteMenu.vue'

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

	components: { SiteMenu },

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
	},

	emits: ['navigate', 'signout'],

	computed: {
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
