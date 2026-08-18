<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<header class="ac-header pq-site__header" data-testid="site-header">
		<div class="ac-header__navigation-main">
			<div class="ac-header__logo">
				<div>
					<!--
						THE MARK, AND IT HAS TO RENDER SOMETHING.

						`.con-logo-container.header` is the vendored slot and it
						measured 0x0 on this portal: the reference fills it with
						its own asset and we had nothing to put there, so the
						header opened with bare text where the brand's mark
						belongs.

						A portal's own `logo` wins when it has one. Without it,
						the fallback is the portal's initial on the brand shape —
						not a generic placeholder glyph, and not somebody else's
						logo. Both are `aria-hidden`: the site name is right
						beside them and announcing "logo" adds nothing.
					-->
					<img
						v-if="logo"
						class="pq-site__logo-mark"
						:src="logo"
						alt=""
						aria-hidden="true"
						data-testid="site-logo-mark" />
					<span
						v-else
						class="pq-site__logo-mark pq-site__logo-mark--initial"
						aria-hidden="true"
						data-testid="site-logo-mark">
						{{ logoInitial }}
					</span>
					<!--
						A SPAN, NOT AN `h1`.

						The site name in the header used to be an `h1`, which
						gave the home page two competing level-one headings —
						the portal's name and the page's own hero title. A
						screen-reader user asking for the page heading got the
						site name, which is the one thing already announced by
						the document title.

						The page's content owns the `h1`. Addressed by
						`data-testid` in the specs, so the tag can change.
					-->
					<span class="logo-text" data-testid="site-title">
						{{ title || '…' }}
					</span>
				</div>
			</div>

			<!--
				The sign-in affordance appears ONLY when the portal declares
				a mode other than `public`. A portal with no accounts must
				show no login button: an inert one is a support ticket from
				every visitor who presses it.

				It sits in the reference's `__right-section` / `ac-navigation`
				slot, which is where that implementation puts
				Aanmelden/Inloggen.
			-->
			<!--
				SINGLE-BAR HEADER: the navigation joins the logo and the
				sign-in controls on one line, the pattern documentation and
				product sites use. In the `double` variant this renders
				nothing and the navigation stays in its own bar below, which
				is the government pattern this renderer was built against.

				The menus are rendered in ONE place per variant, never both:
				a hidden duplicate would put every link into the
				accessibility tree twice and make "next link" announce the
				same destination two times.
			-->
			<div
				v-if="singleBar"
				class="ac-c-navigation__container pq-site__header-nav"
				data-testid="site-header-nav">
				<SiteMenu
					v-for="menu in menus"
					:key="menu.title"
					:menu="menu"
					:currentRoute="currentRoute"
					@navigate="$emit('navigate', $event)" />
			</div>

			<div class="ac-header__right-section">
				<div
					v-if="session || signInRoutes.length"
					class="ac-navigation pq-site__auth"
					data-testid="site-auth">
					<template v-if="session">
						<span data-testid="site-auth-subject">{{
							sessionLabel
						}}</span>
						<button
							type="button"
							data-testid="site-signout"
							@click="$emit('signout')">
							{{ signOutLabel }}
						</button>
					</template>
					<!--
						REGISTER IS SECONDARY, SIGNING IN IS PRIMARY.

						Two controls in one row, and the emphasis is the whole
						point: on a portal most visitors already have an account,
						so signing in is the action and registering is the way
						out for the minority who cannot. Rendering both as plain
						links made the two look equally likely, which is the one
						thing a header can get wrong here.

						Register only appears when the portal DECLARES where to
						send somebody. A "Registreren" button that leads nowhere
						is worse than no button, and this renderer cannot invent
						a registration flow that the portal has not configured.
					-->
					<nav v-else :aria-label="userMenuLabel">
						<ul>
							<li v-if="registerRoute">
								<a
									class="pq-site__auth-action pq-site__auth-action--secondary"
									:href="registerRoute.href"
									data-testid="site-register">
									{{ registerRoute.label }}
								</a>
							</li>
							<li v-for="entry in signInRoutes" :key="entry.mode">
								<a
									class="pq-site__auth-action pq-site__auth-action--primary"
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

		<div v-if="!singleBar" class="ac-header__navigation-secondary">
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

		<div class="ac-header__navigation-breadcrumb">
			<div class="container" />
		</div>
	</header>
</template>

<script>
import SiteMenu from './SiteMenu.vue'

/**
 * The portal's masthead, as a BLOCK rather than as markup in the shell.
 *
 * WHY IT MOVED. `App.vue` hard-coded this header, which made the shell
 * something only a developer could change: a portal could not drop the
 * navigation bar, reorder the masthead, or put anything else above the hero
 * (task 0.1). As a block it is placed in the `header` region like any other
 * widget, and a page or portal can replace it, or leave the region explicitly
 * empty.
 *
 * IT RENDERS THE SAME DOM IT DID AS MARKUP, byte for byte, and that is checked
 * rather than asserted: `tests/shell-snapshot.mjs` holds the rendered header
 * against the version captured before the move. A migration that changes every
 * portal's appearance is not a migration (task 2.3).
 *
 * EVERY STRING IS A PROP (task 3.5). No `t()` here — this renderer must boot at
 * a public origin with no Nextcloud globals, and a block that reaches for a
 * translation function is a block the Docusaurus build cannot mount. The
 * defaults are the Dutch the shell already shipped, so nothing changes for a
 * portal that sets none of them.
 *
 * @spec openspec/changes/portal-page-composition/tasks.md
 */
export default {
	name: 'BrandHeader',

	components: { SiteMenu },

	props: {
		/** The portal's title, shown beside the mark. */
		title: {
			type: String,
			default: '',
		},

		/** The portal's logo URL; falsy renders the initial instead. */
		logo: {
			type: String,
			default: '',
		},

		/** The single character shown when there is no logo. */
		logoInitial: {
			type: String,
			default: '',
		},

		/** The menus to render, already filtered to the header's own. */
		menus: {
			type: Array,
			default: () => [],
		},

		/** The route currently shown, for marking the active item. */
		currentRoute: {
			type: String,
			default: '/',
		},

		/** Whether the navigation shares the masthead's row. */
		singleBar: {
			type: Boolean,
			default: false,
		},

		/** The visitor's session, or null. */
		session: {
			type: Object,
			default: null,
		},

		/** How to name the signed-in visitor. */
		sessionLabel: {
			type: String,
			default: '',
		},

		/** The sign-in routes this portal offers. */
		signInRoutes: {
			type: Array,
			default: () => [],
		},

		/** Where to send somebody without an account, or null. */
		registerRoute: {
			type: Object,
			default: null,
		},

		/** The sign-out control's label. */
		signOutLabel: {
			type: String,
			default: 'Uitloggen',
		},

		/** The accessible name of the account navigation. */
		userMenuLabel: {
			type: String,
			default: 'Gebruikersmenu',
		},
	},

	emits: ['navigate', 'signout'],
}
</script>
