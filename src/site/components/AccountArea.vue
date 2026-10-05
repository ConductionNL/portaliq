<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<!--
		THE SIGNED-IN AREA (`/mijn/...`). Signed out it is the way in: the
		portal's sign-in routes, and the dev login only where the server accepts
		it. Signed in it renders the page the menu entry names, through
		pages/registry.js, so a section whose page is not built yet still shows
		up and says so.
	-->
	<section
		class="container pq-account"
		:class="{ 'pq-account--with-menu': withMenu }"
		data-testid="site-account">
		<!-- The resident's own menu, beside the content on every page of this
		     area once signed in (site-resident-menu REQ-SRM-002). -->
		<ResidentMenu
			v-if="withMenu"
			class="pq-account__menu"
			:groups="menuGroups"
			:currentRoute="currentRoute"
			:label="t('My area')"
			:showLabel="(portal && portal.accountLabel) || t('Menu of my area')"
			:card="menuCard"
			:newLabel="t('{count} new')"
			:hideLabel="t('Close the menu')"
			@navigate="$emit('navigate', $event)" />
		<div class="pq-account__content">
			<!-- The ask for an e-mail address, in the content column and above
			     the page heading, so it lines up with the page it is about. -->
			<slot name="prompt" />
			<!-- Whom the resident acts for, on every signed-in page while it is
			     not themselves (site-mijn-omgeving-components REQ-SMO-008). -->
			<ActingForBar
				v-if="session && actingForSomeone"
				:t="t"
				:locale="locale" />
			<p v-if="!sessionKnown" class="utrecht-paragraph" role="status">
				{{ t('Loading…') }}
			</p>

			<div v-else-if="!session" data-testid="site-account-signin">
				<!-- The sign-in page as role cards, for a portal that writes them
				     (site-chrome-follows-the-design, G-18); loaded on demand. -->
				<SignInPage
					v-if="signInDesigned"
					:routes="signInRoutes"
					:page="signInPageText"
					:welcomeLabel="t('Welcome')"
					:introLabel="t('Log in to view your information.')"
					:noWayLabel="
						t('No login method is configured for this organisation yet.')
					" />
				<template v-else>
					<h1 class="utrecht-heading-2">
						{{ t('Welcome') }}
					</h1>
					<p class="utrecht-paragraph">
						{{ t('Log in to view your information.') }}
					</p>
					<ul v-if="signInRoutes.length" class="pq-account__ways-in">
						<li v-for="way in signInRoutes" :key="way.mode">
							<a
								class="utrecht-button-link utrecht-button-link--html-a utrecht-button-link--primary-action"
								:href="way.href"
								:data-mode="way.mode"
								data-testid="site-account-signin-route">
								{{ way.label }}
							</a>
						</li>
					</ul>
					<p v-else class="utrecht-paragraph">
						{{
							t(
								'No login method is configured for this organisation yet.',
							)
						}}
					</p>
				</template>
				<button
					v-if="devLogin"
					type="button"
					class="utrecht-button utrecht-button--secondary-action"
					data-testid="site-devlogin"
					@click="$emit('devlogin')">
					{{ t('Dev-login (test)') }}
				</button>
				<p v-if="devError" class="utrecht-paragraph" role="alert">
					{{ devError }}
				</p>
				<!-- The doors besides the sign-in buttons, only where they lead
			     somewhere (identity-ways-in-screens REQ-IWI-005). -->
				<WaysIn
					v-if="ways.register || ways.reference"
					:ways="ways"
					:authBase="authBase"
					:portal="portalSlug"
					:t="waysT || t" />
			</div>

			<p v-else-if="loading" class="utrecht-paragraph" role="status">
				{{ t('Loading…') }}
			</p>

			<p
				v-else-if="nav.length === 0"
				class="utrecht-paragraph"
				data-testid="site-account-empty">
				{{ t('No contributions to show yet.') }}
			</p>

			<!-- `/mijn` itself: the resident's home, loaded on demand
			     (site-mijn-omgeving-components REQ-SMO-007, design D4). -->
			<template v-else-if="isHome">
				<component
					:is="homeComponent"
					v-if="homeComponent"
					:session="session"
					:nav="nav"
					:contributions="contributions"
					:api="api"
					:t="t"
					:locale="locale"
					@navigate="$emit('navigate', $event)" />
				<p v-else class="utrecht-paragraph" role="status">
					{{ t('Loading…') }}
				</p>
			</template>

			<template v-else-if="entry">
				<h1
					v-if="!ownsHeading"
					id="site-account-title"
					class="utrecht-heading-2"
					data-testid="site-account-title">
					{{ entry.label }}
				</h1>
				<p v-if="pageLoading" class="utrecht-paragraph" role="status">
					{{ t('Loading…') }}
				</p>
				<component
					:is="pageComponent"
					v-else-if="pageComponent"
					:key="entry.key"
					v-bind="pageProps"
					@navigate="$emit('navigate', $event)"
					@unread="$emit('unread', $event)"
					@refresh="$emit('refresh')"
					@removed="$emit('signout')" />
			</template>
		</div>
	</section>
</template>

<script>
import { defineAsyncComponent, markRaw } from 'vue'
import PlaceholderPage from '../pages/PlaceholderPage.vue'
import ResidentMenu from './ResidentMenu.vue'
import WaysIn from './WaysIn.vue'
import { ACTING_FOR_SELF } from '../../shared/myCases.js'
import {
	navKeyFor,
	OPEN_STORAGE_KEY,
	opensAsRecordPage,
} from '../../shared/openRecord.js'
import {
	ACCOUNT_ROUTE,
	recordIdOfRoute,
	routeForNav,
} from '../../shared/portalNav.js'
import { pageOwnsHeading, sitePageLoader } from '../pages/registry.js'
import { actingFor } from './e/actingFor.js'

// The sign-in links are button links. Without this stylesheet their classes
// name nothing and the browser draws its own blue link.
import '@utrecht/button-link-css/dist/index.css'

/**
 * The names a component declares as props, whether as an array or an object.
 *
 * @param {object} component The component.
 * @return {Array<string>} The prop names.
 */
function declaredProps(component) {
	const props = component && component.props
	if (Array.isArray(props)) {
		return props
	}
	return props ? Object.keys(props) : []
}

/**
 * The signed-in area of the site renderer.
 *
 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
 */
export default {
	name: 'AccountArea',

	components: {
		// Loaded only while the resident acts for someone else.
		ActingForBar: defineAsyncComponent(() => import('./mijn/ActingForBar.vue')),
		// Loaded only on a portal that writes its sign-in cards.
		SignInPage: defineAsyncComponent(() => import('./chrome/SignInPage.vue')),
		ResidentMenu,
		WaysIn,
	},

	props: {
		/** Whether the session has been read; until then nothing is decided. */
		sessionKnown: { type: Boolean, default: false },
		/** The resident's session, or null. */
		session: { type: Object, default: null },
		/** Whether the contributions are still loading. */
		loading: { type: Boolean, default: false },
		/** The signed-in navigation. */
		nav: { type: Array, default: () => [] },
		/** The entry the route names, or null. */
		entry: { type: Object, default: null },
		/** The contributions aggregate, or null. */
		contributions: { type: Object, default: null },
		/** The shared portal API bound to the site's bearer. */
		api: { type: Object, default: null },
		/** The portal's sign-in routes. */
		signInRoutes: { type: Array, default: () => [] },
		/** The doors besides the sign-in buttons, from waysInFrom(). */
		ways: {
			type: Object,
			default: () => ({
				register: false,
				reference: false,
				emailSignIn: '',
				referenceCaseTypes: [],
			}),
		},

		/** The translator of the ways in, or null for the site's. */
		waysT: { type: Function, default: null },
		/** The portal API base (`.../portal/api`), for the ways in. */
		authBase: { type: String, default: '' },
		/** The serving portal's slug, or ''. */
		portalSlug: { type: String, default: '' },
		/** Whether the server accepts the dev login. */
		devLogin: { type: Boolean, default: false },
		/** Why the dev login did not work, or ''. */
		devError: { type: String, default: '' },
		/** The site translator. */
		t: { type: Function, required: true },
		/** The site language. */
		locale: { type: String, default: 'nl' },
		/** The portal record from the content API. */
		portal: { type: Object, default: null },
		/** The resident menu's groups, from residentMenuGroups(); empty shows none. */
		menuGroups: { type: Array, default: () => [] },
		/** The route on screen, to mark the current item in the menu. */
		currentRoute: { type: String, default: '' },
	},

	emits: ['devlogin', 'navigate', 'unread', 'refresh', 'signout'],

	data() {
		return {
			// The page module for the entry on screen, loaded on demand, and
			// whether it is still loading. Loaded here rather than through
			// defineAsyncComponent so the props it declares are known: a page
			// gets only those, and nothing lands on its DOM as an attribute.
			pageComponent: null,
			pageLoading: false,
			// The `/mijn` home, loaded the first time it is opened.
			homeComponent: null,
		}
	},

	computed: {
		/**
		 * The card at the top of the resident menu: whom the resident acts for,
		 * when the session acts for an organisation and the portal names the
		 * card's label (resident-menu-badges-and-cards, G-06).
		 *
		 * @return {object|null} `{label, title}`.
		 *
		 * @spec openspec/changes/resident-menu-badges-and-cards/specs/site-resident-menu/spec.md#requirement-the-menu-may-open-with-whom-the-resident-acts-for
		 */
		menuCard() {
			const label = this.portal?.residentMenu?.cardLabel
			const title = this.session?.organisationName
			return label && title ? { label, title } : null
		},

		/**
		 * The portal's sign-in page text, or an empty object.
		 *
		 * @return {object} `authentication.signInPage`.
		 *
		 * @spec openspec/changes/site-chrome-follows-the-design/specs/site-chrome/spec.md#requirement-the-sign-in-page-must-offer-each-way-in-as-a-card-for-its-role
		 */
		signInPageText() {
			return this.portal?.authentication?.signInPage || {}
		},

		/**
		 * Whether the portal wrote its sign-in page: page text or a card for
		 * one of its ways in. Otherwise the plain list of buttons stays.
		 *
		 * @return {boolean}
		 *
		 * @spec openspec/changes/site-chrome-follows-the-design/specs/site-chrome/spec.md#requirement-the-sign-in-page-must-offer-each-way-in-as-a-card-for-its-role
		 */
		signInDesigned() {
			return (
				Object.keys(this.signInPageText).length > 0
				|| this.signInRoutes.some((way) => way.card)
			)
		},

		/**
		 * Whether the resident menu shows: signed in, with groups to show.
		 * Signed out this area is the way in and keeps its full width.
		 *
		 * @return {boolean} True when the menu shows.
		 *
		 * @spec openspec/changes/site-resident-menu/specs/site-resident-menu/spec.md#requirement-the-residents-own-items-must-sit-in-a-menu-beside-the-content-req-srm-002
		 */
		withMenu() {
			return Boolean(this.session) && this.menuGroups.length > 0
		},

		/**
		 * @return {boolean} Whether the page on screen shows its own h1.
		 *
		 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
		 */
		/**
		 * Whether `/mijn` itself is on screen, signed in with something to
		 * show: then the home renders instead of a page.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-mijn-must-open-on-what-the-resident-still-has-to-do-req-smo-007
		 */
		/**
		 * Whether the resident acts for someone else right now.
		 *
		 * @return {boolean}
		 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-the-resident-must-see-and-switch-for-whom-they-act-req-smo-008
		 */
		actingForSomeone() {
			return actingFor.id !== ACTING_FOR_SELF
		},

		isHome() {
			return (
				Boolean(this.session)
				&& this.nav.length > 0
				&& this.currentRoute === ACCOUNT_ROUTE
			)
		},

		ownsHeading() {
			return pageOwnsHeading(this.entry)
		},

		/**
		 * The page contract (pages/registry.js), narrowed to what the page
		 * on screen declares.
		 *
		 * @return {object} The props.
		 *
		 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
		 */
		pageProps() {
			const contract = {
				entry: this.entry,
				page: this.entry && this.entry.page,
				contribution: this.entry && this.entry.contribution,
				api: this.api,
				session: this.session,
				portal: this.portal,
				contributions: this.contributions,
				nav: this.nav,
				t: this.t,
				locale: this.locale,
				navigate: (target) => this.$emit('navigate', target),
				closedMarker: this.contributions?.cases?.closedMarker === true,
				canOpen: (target) => navKeyFor(this.nav, target) !== null,
				openCase: (target, row) => this.openCase(target, row),
				// The record a route chooses on a record page
				// (site-mijn-omgeving-components REQ-SMO-008).
				routeRecordId: recordIdOfRoute(this.currentRoute),
			}
			const wanted = declaredProps(this.pageComponent)
			return Object.fromEntries(
				Object.entries(contract).filter(([name]) => wanted.includes(name)),
			)
		},
	},

	watch: {
		isHome: {
			immediate: true,
			/**
			 * Load the home the first time `/mijn` itself is on screen.
			 *
			 * @param {boolean} home Whether `/mijn` itself is on screen.
			 * @return {void}
			 * @spec openspec/changes/site-mijn-omgeving-components/specs/site-mijn-omgeving/spec.md#requirement-mijn-must-open-on-what-the-resident-still-has-to-do-req-smo-007
			 */
			handler(home) {
				if (home && !this.homeComponent) {
					import('./mijn/MijnHome.vue')
						.then((module) => {
							this.homeComponent = markRaw(module.default || module)
						})
						.catch(() => {
							this.homeComponent = markRaw(PlaceholderPage)
						})
				}
			},
		},

		entry: {
			immediate: true,
			handler() {
				this.loadPage()
			},
		},
	},

	methods: {
		/**
		 * Load the component for the entry on screen: the registered page,
		 * else the placeholder.
		 *
		 * @return {Promise<void>} Resolves when the page is set.
		 *
		 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
		 */
		async loadPage() {
			const entry = this.entry
			const loader = sitePageLoader(entry)
			if (!loader) {
				this.pageComponent = markRaw(PlaceholderPage)
				this.pageLoading = false
				return
			}
			this.pageLoading = true
			let component = PlaceholderPage
			try {
				const module = await loader()
				component = module.default || module
			} catch {
				// A page that does not load says it is not available here.
			}
			if (entry !== this.entry) {
				return
			}
			this.pageComponent = markRaw(component)
			this.pageLoading = false
		},

		/**
		 * Open a case from my cases on the page of the app it belongs to,
		 * the way a record link does: kept for the page, then routed to.
		 *
		 * @param {{app: string, collection: string, id: string}} target The case.
		 * @param {object} [row] The case row, so a case read under a mandate opens.
		 * @return {void}
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-record-link-must-open-its-record-after-sign-in-req-srp-021
		 */
		openCase(target, row) {
			const key = navKeyFor(this.nav, target)
			const entry = this.nav.find((candidate) => candidate.key === key)
			if (!entry) {
				return
			}
			try {
				window.sessionStorage.setItem(
					OPEN_STORAGE_KEY,
					JSON.stringify({
						app: target.app,
						collection: target.collection,
						id: target.id,
						row: row || null,
					}),
				)
			} catch {
				// Without storage the page opens without the case selected.
			}
			// A record page opens on that record's route (REQ-SMO-010).
			this.$emit(
				'navigate',
				opensAsRecordPage(entry, target.collection)
					? `${routeForNav(entry)}/${encodeURIComponent(target.id)}`
					: routeForNav(entry),
			)
		},
	},
}
</script>

<style scoped>
.pq-account {
	padding-block: 24px;
}

/* The menu beside the content from tablet width up; on a phone the menu
   stands above it and folds behind its button (ResidentMenu.vue). */
.pq-account--with-menu {
	display: grid;
	grid-template-columns: minmax(0, 1fr);
	gap: 16px;
}

.pq-account__content {
	min-inline-size: 0;
}

@media (min-width: 768px) {
	.pq-account--with-menu {
		grid-template-columns: minmax(180px, 260px) minmax(0, 1fr);
		gap: 40px;
		align-items: start;
	}
}

.pq-account__ways-in {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
	margin: 0 0 16px;
	padding: 0;
	list-style: none;
}
</style>
