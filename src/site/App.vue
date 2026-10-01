<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<!--
		THE `ac-*` SKELETON IS THE REFERENCE IMPLEMENTATION'S, ON PURPOSE.

		`nlds-app.css` is 1362 `ac-*` selectors and 633 `con-*` against 157
		`utrecht-*` — it styles the reference's DOM, not a portal's, so loading
		it changed nothing on screen until this renderer emitted the matching
		structure. Captured from the running reference rather than guessed:

		  .ac-app-container
		    header.ac-header                       1280x151
		      .ac-header__navigation-main          1280x96
		      .ac-header__navigation-secondary     1280x55
		        .container                         1200 @ x=40
		      .ac-header__navigation-breadcrumb
		    main.ac-app-main                       1280 @ x=0, full bleed
		      .container                           1200 @ x=40
		    footer.ac-footer
		      h2.sr-only
		      section                              96px band, blue-600
		        .container.ac-footer__container     4-column grid, 28px gap
		      section.ac-footer__sub-footer        28px band, blue-500

		Two things in that tree are load-bearing in a way the class names do
		NOT advertise, and both are explained where they are emitted below:
		the footer needs TWO sections (the CSS selects on `:first-of-type` /
		`:last-of-type:not(:only-of-type)`, never on `.ac-footer__sub-footer`),
		and every reading region needs its own `.container` — `ac-app-main` is
		deliberately full-bleed.

		`pq-site` and the `data-testid`s stay so the existing e2e keeps
		addressing the same nodes.
	-->
	<div
		class="pq-site ac-app-container"
		:class="themeClass"
		data-testid="site-root">
		<!--
			NO SKIP LINK HERE — it is emitted by `templates/site.php`, ahead of
			this mount point.

			It lived here, and here it did not exist until the bundle had
			downloaded, parsed and mounted. The visitor who most needs a skip
			link is the one tabbing into a page that is still loading, and for
			them there was nothing to find. The shell owns the document, so the
			shell owns the SC 2.4.1 affordance; a second one at this level would
			be a duplicate tab stop announcing the same target twice.
		-->
		<!--
			THE HEADER REGION (REQ-PTB-008, REQ-PTB-009). Its default is one
			`brandHeader` block, which reproduces the header this shell used to
			hard-code; a portal or page can replace it or leave it empty. The
			shell owns the data, the block owns the markup (REQ-PTB-004).
		-->
		<template v-for="block in regions.header" :key="block.id || block.widgetKey">
			<BrandHeader
				v-if="block.widgetKey === 'brandHeader'"
				v-bind="authoredProps(block)"
				:title="site.title || ''"
				:variant="headerVariant"
				:menus="headerMenus"
				:currentRoute="route"
				:breadcrumbs="breadcrumbs"
				:session="session"
				:sessionLabel="sessionLabel"
				:signInRoutes="signInRoutes"
				:registerRoute="registerRoute"
				:signinFailedMessage="signinFailed ? signinFailedMessage : ''"
				:registerLabel="t('Register')"
				:signOutLabel="t('Sign out')"
				:userMenuLabel="t('User menu')"
				:breadcrumbLabel="t('Breadcrumb')"
				:logoLabel="t('Logo')"
				@navigate="go"
				@signout="signOut" />
			<WidgetGrid
				v-else
				:widgets="[block]"
				v-bind="gridContext"
				@navigate="go"
				@search="goSearch" />
		</template>

		<!-- The warning before an inactivity sign-out (signin-session-idle-warning-and-sso T06). -->
		<IdleWarningDialog
			v-if="session && idleWarning && idleTimes"
			:times="idleTimes"
			:locale="locale"
			@stay="staySignedIn"
			@signout="signOut" />
		<p
			v-if="idleSignedOut && !session"
			class="utrecht-paragraph pq-idle-signed-out"
			role="status"
			data-testid="site-idle-signed-out">
			{{ idleSignedOutMessage }}
		</p>

		<!-- Maintenance and warning notices running now (operate-maintenance-notice). -->
		<SiteNotices
			v-if="(site.notices || []).length > 0"
			:notices="site.notices"
			:locale="locale" />

		<!--
			`.container` IS THE CONTENT COLUMN, AND IT IS NOT OPTIONAL.

			`ac-app-main` itself is full-bleed — measured on the reference, 1280
			wide at x=0 with zero padding — because the bands inside it paint
			edge to edge. What holds the READING column is a `.container`:
			`max-width: 1200px; margin: 0 40px; padding: 0 16px`, landing at
			x=40, and every band in the header and footer already uses one.

			Main was the only region emitting its content as a direct child, so
			body copy started hard against the viewport edge at x=0 while the
			navigation above it and the footer below both began at 40. On a wide
			screen that is a full-width line of text — the least readable layout
			the design system can produce, and the only place it happened.
		-->
		<main id="pq-main" class="ac-app-main pq-site__main">
			<!--
				MAIN IS FULL-BLEED AND THE CONTAINER MOVED INWARDS.

				It used to wrap everything here, which was right while the only
				block was markdown and wrong the moment bands existed: a hero
				inside this column measured 1168px against the reference's 1280,
				and nothing inside the hero could recover the width because the
				clamp was an ancestor.

				This is the reference's own structure — `main` full-bleed, every
				`section` bringing its own `.container` — so each region below
				takes one, and `WidgetGrid` decides per block whether to.
			-->
			<div>
				<!-- THE HERO REGION: the page's own hero band, else the
				     portal's, unless the page clears it (REQ-PTB-009). -->
				<WidgetGrid
					v-if="!loading && !error && page && regions.hero.length"
					data-testid="site-region-hero"
					:widgets="regions.hero"
					v-bind="gridContext"
					@navigate="go"
					@search="goSearch" />

				<!-- The signed-in area owns every `/mijn` route; no CMS page is
				     read for it (src/shared/portalNav.js). -->
				<AccountArea
					v-if="accountRoute || (signInNeeded && !session)"
					:sessionKnown="sessionKnown"
					:session="session"
					:loading="account.loading"
					:nav="nav"
					:entry="accountEntry"
					:contributions="account.contributions"
					:api="api"
					:signInRoutes="signInRoutes"
					:devLogin="signinConfig.devLogin === true"
					:devError="devError"
					:t="t"
					:locale="locale"
					@devlogin="devLogin"
					@navigate="go"
					@unread="unreadOverride = $event"
					@refresh="loadAccount" />

				<p v-else-if="loading" class="container" data-testid="site-loading">
					{{ t('Loading…') }}
				</p>

				<!-- A failed load says so. Rendering an empty page instead would
			     make a broken deployment look exactly like an empty site — the
			     one confusion this surface can least afford. -->
				<div
					v-else-if="error"
					class="container"
					role="alert"
					data-testid="site-error"
					:data-portaliq-status="error.status === 404 ? '404' : null"
					:data-portaliq-path="error.status === 404 ? route : null">
					<h2>
						{{
							error.status === 404
								? t('Page not found')
								: t('Something went wrong')
						}}
					</h2>
					<p>
						{{
							error.status === 404
								? t('This page does not exist (any more).')
								: t('The content could not be loaded.')
						}}
					</p>
				</div>

				<!--
					`utrecht-article` IS A PROSE MEASURE, so a grid does not get
					one.

					The class carries `max-inline-size` in token sets that
					define it — Rotterdam's is 750px, straight from RODS — and
					that is exactly right for a column of running text. Applied
					to a 12-column widget grid it clamps the whole page to a
					reading width: measured at 1280px viewport, the article came
					out 750px wide at x=0 while the header and footer
					`.container`s sat at x=40 and 1200px, so the content was both
					narrower than the design and misaligned with the furniture
					above and below it.

					The element stays an `<article>` either way — the page IS a
					self-contained document, which is a question about semantics
					and not about line length.
				-->
				<!-- Edit mode: the editor bundle mounts in place of the page. -->
				<div
					v-else-if="editMode && editing && editing.pageId"
					data-testid="site-edit-host">
					<p v-if="editorStatus" class="container" role="status">
						{{ editorStatus }}
					</p>
					<div ref="editorHost" />
				</div>
				<article
					v-else-if="page"
					:class="bodyIsGrid ? null : 'utrecht-article'"
					data-testid="site-page">
					<!--
						THE RENDERER'S OWN TITLE HEADING IS A FALLBACK, not a
						fixture. A page whose body opens with a hero already
						declares its heading, and emitting this one as well
						printed the same sentence twice — once here in black
						above the band, once inside the band.

						The check is on the BODY rather than on a flag, because
						the duplication is a property of what the page actually
						renders, not of what an author remembered to tick.
					-->
					<div v-if="!bodyProvidesHeading" class="container">
						<!-- The page's own heading is the h1: the site name in the
						     header is not a heading (REQ-PTB-004). The class keeps
						     the size it had as an h2. -->
						<h1 class="utrecht-heading-2" data-testid="page-title">
							{{ page.title }}
						</h1>
					</div>

					<!-- The page's hero image, from the portal's media library or
					     an address (site-page-seo-history-and-media T08). The
					     content API resolves media:<id> and carries the item's
					     alternative text with it. -->
					<div v-if="page.hero && page.hero.url" class="container">
						<img
							class="pq-site-hero"
							data-testid="page-hero"
							:src="page.hero.url"
							:alt="page.hero.alt" />
					</div>

					<!-- The main region: the page's own widgets outside the other
					     four regions (REQ-PTB-008). -->
					<WidgetGrid
						v-if="page.body && page.body.type === 'grid'"
						:widgets="regions.main"
						v-bind="gridContext"
						@navigate="go"
						@search="goSearch" />

					<div v-else class="container">
						<MarkdownBlock
							data-testid="page-markdown"
							:source="(page.body && page.body.markdown) || ''" />
					</div>
				</article>

				<aside
					v-if="!loading && !error && page && regions.aside.length"
					class="pq-site__aside"
					data-testid="site-region-aside">
					<WidgetGrid
						:widgets="regions.aside"
						v-bind="gridContext"
						@navigate="go"
						@search="goSearch" />
				</aside>

				<!--
					NEITHER THE GLOSSARY NOR THE CONTRIBUTED SURFACES ARE
					HARD-CODED HERE ANY MORE.

					They were two `<section>`s carrying literal
					`<h2>Begrippenlijst</h2>` and `<h2>Diensten</h2>`, rendered on
					every page that happened to satisfy a condition. That made
					them impossible for a portal to move, rename, translate,
					reorder or leave out — "Diensten / Meldingen" appeared under
					the content of pages whose author never asked for it, and the
					glossary could only ever live at one route.

					Both are CONTENT, so both belong in a page body as blocks an
					author places. The data still reaches this component and is
					still fetched over the same public contract; what changed is
					that nothing renders it unless a page asks.

					BOTH HALVES OF THAT MOVE ARE NEEDED, and only one of them
					shipped first. The `glossary` block existed and was placed;
					the contributed surfaces lost their section and gained no
					block, so the public contract kept answering with
					contributions that no page could render — a bridge built
					from one side, which is invisible because the page still
					looks complete. `contributions` is now a block too, and it
					takes its rows from here for the same reason the glossary
					does: this renderer runs at a public origin, so a block that
					fetched for itself could not mount there.
				-->
			</div>
		</main>

		<!-- THE FOOTER REGION. Its default is one `footerColumns` block
		     (REQ-PTB-005); a portal or page can replace it or leave it empty. -->
		<template v-for="block in regions.footer" :key="block.id || block.widgetKey">
			<FooterColumns
				v-if="block.widgetKey === 'footerColumns'"
				v-bind="authoredProps(block)"
				:title="site.title || ''"
				:tagline="site.tagline || ''"
				:menus="footerMenus"
				:legalLinks="legalLinks"
				:footer="site.footer || {}"
				@navigate="go" />
			<WidgetGrid
				v-else
				:widgets="[block]"
				v-bind="gridContext"
				@navigate="go"
				@search="goSearch" />
		</template>

		<!--
			THE EDITING DOOR, and it is last in the document on purpose: it is
			an addition for the few visitors who may edit, so it comes after
			everything every visitor came for. It renders nothing at all until
			the probe has said yes — see `refreshEditingContext`.
		-->
		<SiteEditButton
			v-if="editing && !editMode"
			:context="editing"
			@edit="enterEditMode" />
	</div>
</template>

<script>
import { defineAsyncComponent } from 'vue'
import AccountArea from './components/AccountArea.vue'
import BrandHeader from './components/BrandHeader.vue'
import FooterColumns from './components/FooterColumns.vue'
import IdleWarningDialog from './components/IdleWarningDialog.vue'
import MarkdownBlock from './components/MarkdownBlock.vue'
import WidgetGrid from './components/WidgetGrid.vue'
import { createTranslator } from '../shared/i18n/index.js'
import { logoutTarget, silentSignInUrl } from '../shared/idleSession.js'
import { createPortalApi } from '../shared/portalApi.js'
import {
	ACCOUNT_ROUTE,
	buildNav,
	isAccountRoute,
	navEntryForRoute,
	shellSections,
} from '../shared/portalNav.js'
import {
	accountCrumbs,
	accountMenu,
	accountRedirect,
	loggedInAs,
} from './lib/accountArea.js'
import {
	adoptSessionToken,
	authBaseFrom,
	clearSessionToken,
	fetchSession,
	refreshSession,
	signInRoutes,
	storeSessionToken,
	takeSigninFailed,
} from './lib/authApi.js'
import { withoutStyling } from './lib/blockProps.js'
import { captureLanding } from './lib/campaignTracking.js'
import { runtimeConfig } from './lib/contentApi.js'
import {
	fetchContributions,
	fetchGlossary,
	fetchMenus,
	fetchPage,
	fetchSite,
	resolveApiBase,
} from './lib/contentApi.js'
import { editorBaseFrom, fetchEditingContext } from './lib/editorApi.js'
import { createIdleTracker } from './lib/idleTracker.js'
import { loadSiteEditor } from './lib/loadSiteEditor.js'
import { pageRegionsOf, resolveRegions } from './lib/regions.js'
import {
	footerMenusOf,
	headerMenusOf,
	headerVariantOf,
	legalLinksOf,
	registerRouteOf,
} from './lib/shellData.js'

/**
 * LOADED ON DEMAND, and the budget is why — the same reason the detail and
 * search blocks are.
 *
 * `webpack.site.js` sets `performance.hints: 'error'` at 400 KiB because this
 * bundle is downloaded by a first-time visitor on a phone before anything
 * renders. Bundling the editing control eagerly took the entrypoint from 389
 * KiB to 411 KiB and FAILED THE BUILD — which is the budget doing its job, on
 * a control that almost every visitor to a public portal will never be shown.
 *
 * It is imported only once the probe has said this session may edit, so a
 * reader never downloads it at all.
 */
const SiteEditButton = defineAsyncComponent(
	() => import('./components/SiteEditButton.vue'),
)

// Loaded only when a notice is running, so a portal without one pays nothing
// for it in the site bundle (operate-maintenance-notice).
const SiteNotices = defineAsyncComponent(
	() => import('./components/SiteNotices.vue'),
)

/**
 * The built-in site renderer.
 *
 * It reads the PUBLIC content API and nothing else — the same endpoints the
 * Docusaurus plugin reads. That is what keeps the CMS headless: if this
 * component ever needed a Portaliq internal, the finding would be that the API
 * is incomplete (ADR-086 §1).
 */
export default {
	name: 'App',

	components: {
		AccountArea,
		BrandHeader,
		FooterColumns,
		IdleWarningDialog,
		MarkdownBlock,
		SiteEditButton,
		SiteNotices,
		WidgetGrid,
	},

	props: {
		/** Explicit site slug, when not resolving by host. */
		portalSlug: {
			type: String,
			default: '',
		},
	},

	data() {
		return {
			// A failed sign-in the edge sent back (REQ-BEL-006), read once.
			signinFailed: takeSigninFailed(),
			// A bearer in the fragment means the resident just signed in;
			// read before the session fetch strips it. A fresh sign-in on the
			// home page opens the signed-in area, as `/portal` does.
			freshSignIn: /[#&]token=/.test(String(window.location.hash || '')),
			// Whether the session has been read yet: until then the
			// signed-in area shows neither the way in nor a page.
			sessionKnown: false,
			// What the signed-in shell loaded for the session (the
			// contributions aggregate, message threads and news feed); the
			// navigation is built from it (src/shared/portalNav.js).
			account: {
				loading: false,
				contributions: null,
				threads: [],
				news: [],
			},

			// The inbox's unread count after a page changed it, else null.
			unreadOverride: null,
			devError: '',
			// The page on screen is behind the portal's sign-in.
			signInNeeded: false,
			site: {},
			menus: [],
			glossary: [],
			contributions: [],
			session: null,
			// The idle window (signin-session-idle-warning-and-sso T06).
			idleTimes: null,
			idleWarning: false,
			idleSignedOut: false,
			idleTracker: null,
			page: null,
			route: '/',
			// The trailing segment of a route that resolved to its PARENT
			// page — the publication id in `/publicatie/<id>`. Empty for an
			// ordinary page. See `loadRoute`.
			routeParam: '',
			// Where the hero's search box sends a term. A constant rather than
			// a portal field for now: the seeded portal puts search at
			// `/zoeken`, matching the reference, and a portal that moves it
			// wants a `searchRoute` on the portal object rather than a guess
			// here.
			searchRoute: '/zoeken',
			loading: true,
			error: null,
			// The editing context for the route on screen, or null for every
			// visitor who may not edit — which is almost all of them.
			editing: null,
			// Edit mode (portal-in-place-editing): on, its status line, its unmount.
			editMode: false,
			editorStatus: '',
			unmountEditor: null,
			// Set once the probe has refused, and never unset for this page
			// load. It is what keeps a reader's visit to one extra request in
			// total rather than one per navigation: whether a session MAY edit
			// does not change while they browse, only which page it is on.
			editingDenied: false,
		}
	},

	computed: {
		/**
		 * Theme class for the resolved site.
		 *
		 * Mirrors the fleet's theming convention — a `<variant>-theme` class
		 * whose tokens come from themiq. Portaliq defines no tokens of its own
		 * (ADR-086 §6); if the theme does not resolve, the page is unstyled
		 * rather than silently restyled to somebody else's brand.
		 *
		 * @return {string} The theme class, or ''.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-request-must-resolve-to-exactly-one-portal-or-to-none
		 */
		themeClass() {
			return this.site.theme ? `${this.site.theme}-theme` : ''
		},

		/**
		 * Whether the page body already opens with its own heading.
		 *
		 * Only a `hero` qualifies today: it is the one block that renders a
		 * page-level heading. Asking the BODY rather than trusting a flag keeps
		 * this true by construction — a page gains or loses its own heading by
		 * gaining or losing the block that draws one.
		 *
		 * @return {boolean} True when the renderer must not add a title heading.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
		 */
		/**
		 * Whether this page's body is a widget grid rather than markdown.
		 *
		 * Decides whether the page wears `utrecht-article`, which carries a
		 * prose `max-inline-size` — see the template.
		 *
		 * @return {boolean} True when the body is a grid.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
		 */
		/**
		 * The breadcrumb trail for the current route.
		 *
		 * Built from the route's own segments, with the CURRENT page's title
		 * used for the last crumb once the page has loaded. Intermediate
		 * segments are humanised from the path rather than looked up — an
		 * ancestor need not be a page at all (`/publicatie` is a page,
		 * `/publicatie/<id>` is a subject), so asking the CMS for a title per
		 * ancestor would 404 on the common case.
		 *
		 * @return {Array<object>} `{route, label}` crumbs, home first.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
		 */
		breadcrumbs() {
			if (this.accountRoute) {
				return accountCrumbs(this.accountEntry, this.t, this.hrefForRoute)
			}
			const crumbs = [
				{ route: '/', label: this.t('Home'), href: this.hrefForRoute('/') },
			]
			const segments = String(this.route || '/')
				.split('/')
				.filter(Boolean)

			segments.forEach((segment, index) => {
				const route = `/${segments.slice(0, index + 1).join('/')}`
				const isLast = index === segments.length - 1

				// The id segment of `/publicatie/<id>` is not a word; the
				// page's own title is what a visitor recognises.
				let label = segment.charAt(0).toUpperCase() + segment.slice(1)
				if (isLast === true && this.page && this.page.title) {
					label = this.page.title
				}

				crumbs.push({ route, label, href: this.hrefForRoute(route) })
			})

			return crumbs
		},

		/**
		 * Whether this page's body is a widget grid rather than markdown.
		 *
		 * Decides whether the page wears `utrecht-article`, which carries a
		 * prose `max-inline-size` — see the template.
		 *
		 * @return {boolean} True when the body is a grid.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
		 */
		bodyIsGrid() {
			return (
				(this.page && this.page.body && this.page.body.type === 'grid')
				=== true
			)
		},

		/**
		 * Whether the page body already declares its own heading.
		 *
		 * @return {boolean} True when the renderer must not add a title.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
		 */
		bodyProvidesHeading() {
			const body = this.page.body || {}
			const main = body.type === 'grid' ? this.regions.main : []

			// A block that renders its SUBJECT's name owns the page heading.
			//
			// `hero` states the page's own title. `publicationDetail` states
			// the publication's, which is the more specific and more useful
			// one — so a detail page printed "Publicatie" as an h1 and then
			// "Subsidieregister Rotterdam" as another, two page titles where
			// the reference has one, and the generic one first.
			//
			// A hero in the hero region counts too, the portal's included: the
			// page then keeps one h1 (REQ-PTB-009).
			return [...this.regions.hero, ...main].some(
				(w) => w.widgetKey === 'hero' || w.widgetKey === 'publicationDetail',
			)
		},

		/**
		 * Every region's blocks for the page on screen: the page's own, else
		 * the portal's, else the default shell.
		 *
		 * @return {object} Region name to widgets, all five present.
		 *
		 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-regions-must-resolve-page-first-then-portal-then-default-req-ptb-009
		 */
		regions() {
			return resolveRegions(
				pageRegionsOf(this.page && this.page.body),
				this.site.regions,
			)
		},

		/**
		 * What every widget grid on the page is handed by the host.
		 *
		 * @return {object} The WidgetGrid props besides `widgets`.
		 *
		 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-blocks-must-take-their-data-as-props-and-nothing-else-req-ptb-007
		 */
		gridContext() {
			return {
				glossary: this.glossary,
				contributions: this.contributions,
				routeParam: this.routeParam,
				portal: this.site.slug || '',
				signedIn: this.session !== null,
			}
		},

		/**
		 * The menus shown in the header bar.
		 *
		 * PLACEMENT COMES FROM `position`, WHICH IS WHAT THAT FIELD IS FOR — the
		 * register describes it as "ordering of this menu relative to others on
		 * the same portal (a header menu versus a footer menu)". So the rule is
		 * read off the existing contract rather than added to it: no schema
		 * change, no data migration, and a portal that declares one menu keeps
		 * behaving exactly as it did.
		 *
		 * Position 0 is the header. Everything else is a footer column.
		 *
		 * @return {Array} The header menus.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-content-api-must-be-sufficient-without-the-built-in-renderer
		 */
		headerMenus() {
			const menus = headerMenusOf(this.menus)
			if (!this.session || this.nav.length === 0) {
				return menus
			}
			// The signed-in navigation is one more header menu, after the
			// portal's own, so it gets the same bar, styling and keyboard
			// handling (SiteMenu) rather than a second kind of menu.
			return [
				...menus,
				accountMenu(this.nav, this.t, this.unreadCount, this.hrefForRoute),
			]
		},

		/**
		 * The site's language: the portal's, else the document's.
		 *
		 * @return {string} A language code, `nl` when nothing says otherwise.
		 *
		 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
		 */
		locale() {
			const lang =
				this.site.locale
				|| (typeof document !== 'undefined' && document.documentElement.lang)
				|| 'nl'
			return String(lang).slice(0, 2).toLowerCase()
		},

		/**
		 * The site translator: English source strings, Dutch and English
		 * bundles shared with `/portal` (src/shared/i18n).
		 *
		 * @return {(key: string, vars?: object) => string} The translator.
		 *
		 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
		 */
		t() {
			return createTranslator(this.locale)
		},

		/**
		 * How a resident signs in here, from the shell (`site()` in
		 * PortalPageController): dev login, silent sign-in, organisation.
		 *
		 * @return {object} The sign-in settings, possibly empty.
		 *
		 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
		 */
		signinConfig() {
			return runtimeConfig().signin || {}
		},

		/**
		 * The shared portal API, bound to this portal and to the bearer
		 * this tab keeps (sessionStorage, per tab).
		 *
		 * @return {object} The API.
		 *
		 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
		 */
		api() {
			return createPortalApi(
				{
					apiBase: authBaseFrom(resolveApiBase()),
					organisationSlug: this.site.slug || this.portalSlug || '',
					audience: this.signinConfig.audience || '',
				},
				{
					getToken: () => adoptSessionToken() || null,
					setToken: storeSessionToken,
				},
			)
		},

		/**
		 * The signed-in navigation, empty when signed out.
		 *
		 * @return {Array<object>} The entries.
		 *
		 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
		 */
		nav() {
			if (!this.session || !this.account.contributions) {
				return []
			}
			return buildNav(
				this.account.contributions.contributions,
				this.t,
				shellSections({ session: this.session, ...this.account }),
			)
		},

		/**
		 * @return {boolean} Whether the route on screen is in the signed-in area.
		 *
		 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
		 */
		accountRoute() {
			return isAccountRoute(this.route)
		},

		/**
		 * @return {object|null} The navigation entry the route names.
		 *
		 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
		 */
		accountEntry() {
			return navEntryForRoute(this.nav, this.route)
		},

		/**
		 * @return {number} The inbox's unread count.
		 *
		 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
		 */
		unreadCount() {
			return (
				this.unreadOverride
				?? (this.account.contributions?.unreadCount || 0)
			)
		},

		/**
		 * @return {string} The one sentence a failed sign-in shows.
		 *
		 * @spec openspec/specs/portal-broker-envelope-login/spec.md#requirement-a-failed-login-returns-to-the-login-screen-without-a-reason-req-bel-006
		 */
		signinFailedMessage() {
			return this.t('Signing in did not work. Try again or choose another way in.')
		},

		/**
		 * The portal's header shape, `double` unless it chose `single`.
		 *
		 * @return {string} The variant.
		 *
		 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-header-must-be-a-block-whose-shape-the-portal-chooses-req-ptb-004
		 */
		headerVariant() {
			return headerVariantOf(this.site)
		},

		/**
		 * The register destination the portal declares, or null.
		 *
		 * @return {object|null} `{href, label}`.
		 *
		 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-header-must-be-a-block-whose-shape-the-portal-chooses-req-ptb-004
		 */
		registerRoute() {
			return registerRouteOf(this.site)
		},

		/**
		 * The footer's link columns: position 1, and any position the legal
		 * strip does not claim.
		 *
		 * @return {Array} The footer menus.
		 *
		 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-footer-must-be-a-block-whose-bands-are-styled-by-role-req-ptb-005
		 */
		footerMenus() {
			return footerMenusOf(this.menus)
		},

		/**
		 * The legal strip's links: the portal's own, else its strip menu's.
		 *
		 * @return {Array} `{label, href}` entries.
		 *
		 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-footer-must-be-a-block-whose-bands-are-styled-by-role-req-ptb-005
		 */
		legalLinks() {
			return legalLinksOf(this.site, this.menus)
		},

		/**
		 * The sign-in routes this portal offers, from its DECLARED modes.
		 *
		 * Derived from `/api/content/site`, so the decision travels on the
		 * public contract even though the act of signing in does not.
		 *
		 * @return {Array} Zero or more routes.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-must-offer-only-the-sign-in-routes-it-declares
		 */
		signInRoutes() {
			return signInRoutes(this.site, authBaseFrom(resolveApiBase()), this.t)
		},

		/**
		 * @return {string} Why the visitor was signed out, in the site's language.
		 *
		 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T06
		 */
		idleSignedOutMessage() {
			return this.t('You were signed out because you were inactive.')
		},

		/**
		 * @return {string} How to name the signed-in visitor.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-must-offer-only-the-sign-in-routes-it-declares
		 */
		sessionLabel() {
			return loggedInAs(this.session, this.t)
		},
	},

	/**
	 * Boot: resolve the route, then load the portal and its first page —
	 * entirely from the public content API, with no Nextcloud global read.
	 *
	 * @return {Promise<void>} Resolves when the first page is on screen.
	 *
	 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-portal-renderer-must-not-depend-on-nextcloud-globals
	 */
	async mounted() {
		// The landing's campaign parameters are captured HERE, on every page
		// of the site, not only where a form block mounts: the form block
		// loads on demand, so a visitor who lands on the campaign page and
		// walks to the form later would otherwise have no first touch, and
		// a page that was never given a form would record none at all.
		// Synchronously, before any fetch: the shell carries the resolved
		// slug, so a visitor who moves on before the site loads still has
		// the landing that brought them. The site fetch below repeats it
		// under the slug the API answers with, which is the same one.
		captureLanding(this.portalSlug || runtimeConfig().resolvedPortal || '')
		this.route = this.routeFromLocation()
		window.addEventListener('popstate', this.onPopState)
		await this.loadSite()
		captureLanding(this.site.slug || this.portalSlug)
		await this.loadRoute(this.route)
	},

	/**
	 * Stop listening, and stop the idle window.
	 *
	 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T06
	 */
	beforeUnmount() {
		window.removeEventListener('popstate', this.onPopState)
		this.idleTracker?.stop()
	},

	methods: {
		/**
		 * The in-site route this browser location represents.
		 *
		 * @return {string} The route, always with a leading slash.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-portal-renderer-must-not-depend-on-nextcloud-globals
		 */
		routeFromLocation() {
			const params = new URLSearchParams(window.location.search)
			const explicit = params.get('route')
			if (explicit) {
				return explicit.startsWith('/') ? explicit : `/${explicit}`
			}

			return '/'
		},

		/**
		 * Handle browser back/forward.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
		 */
		onPopState() {
			const next = this.routeFromLocation()
			this.route = next
			this.loadRoute(next)
		},

		/**
		 * Load the portal record, its menus and its glossary.
		 *
		 * @return {Promise<void>} Resolves when loaded.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-request-must-resolve-to-exactly-one-portal-or-to-none
		 */
		async loadSite() {
			try {
				const [site, menus, glossary] = await Promise.all([
					fetchSite(this.portalSlug),
					this.unlessSignInNeeded(fetchMenus(this.portalSlug), []),
					this.unlessSignInNeeded(fetchGlossary(this.portalSlug), []),
				])
				this.site = site
				this.menus = menus
				this.glossary = glossary
			} catch (error) {
				this.error = error
				return
			}

			// Contributions load SEPARATELY and never reject into the block
			// above. They come from third-party apps reached through a
			// duck-typed provider (ADR-046): a leaf app that is broken, half
			// installed, or simply absent must cost the visitor a section, not
			// the whole portal. The CMS content above is the portal; this is
			// an addition to it.
			try {
				this.contributions = await fetchContributions(this.portalSlug)
			} catch {
				this.contributions = []
			}

			// The session is read last and cannot fail the page. A portal whose
			// auth edge is down must still serve its public content, which is
			// the overwhelming majority of what it serves; `fetchSession`
			// resolves null rather than throwing for exactly that reason.
			this.session = await fetchSession(authBaseFrom(resolveApiBase()))
			this.sessionKnown = true
			this.watchIdle()

			this.applyDocumentTitle()

			if (this.session) {
				await this.loadAccount()
			} else {
				this.trySilentSignIn()
			}
		},

		/**
		 * A content read that a portal behind a sign-in refuses to a visitor
		 * without a session (401) or below its trust floor (403) answers the
		 * fallback, so the door (title, theme, sign-in routes) still renders.
		 * Any other failure stays a failure.
		 *
		 * @param {Promise<*>} read The content read.
		 * @param {*} fallback What a refused read answers.
		 * @return {Promise<*>} The read's answer, or the fallback.
		 *
		 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
		 */
		async unlessSignInNeeded(read, fallback) {
			try {
				return await read
			} catch (error) {
				if (error && (error.status === 401 || error.status === 403)) {
					return fallback
				}
				throw error
			}
		},

		/**
		 * Read what the signed-in navigation is built from, as `/portal`
		 * does: the contributions aggregate, the message threads and the
		 * news feed, each fail-closed. Then follow the route the navigation
		 * implies (the default page for a bare `/mijn`).
		 *
		 * @return {Promise<void>} Resolves when loaded.
		 *
		 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
		 */
		async loadAccount() {
			if (!this.session) {
				return
			}
			this.account = { ...this.account, loading: true }
			const [contributions, threads, news] = await Promise.all([
				this.api.getContributions(),
				this.api.fetchThreads(),
				this.api.fetchNewsFeed(),
			])
			this.unreadOverride = null
			this.account = {
				loading: false,
				contributions,
				threads: threads || [],
				news: news || [],
			}
			this.followAccountRoute()
		},

		/**
		 * Forget everything the signed-in shell loaded.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
		 */
		forgetAccount() {
			this.account = {
				loading: false,
				contributions: null,
				threads: [],
				news: [],
			}
			this.unreadOverride = null
		},

		/**
		 * Open the signed-in area after a fresh sign-in on the home page,
		 * and replace a bare `/mijn` (or a page the navigation does not
		 * offer) with the default page.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
		 */
		followAccountRoute() {
			if (!this.session || this.nav.length === 0) {
				return
			}
			if (this.freshSignIn && this.route === '/') {
				this.freshSignIn = false
				this.replaceRoute(ACCOUNT_ROUTE)
			}
			const target = accountRedirect(this.nav, this.route)
			if (target) {
				this.replaceRoute(target)
			}
		},

		/**
		 * Show another in-site route in place of this history entry.
		 *
		 * @param {string} route The route.
		 * @return {void}
		 *
		 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
		 */
		replaceRoute(route) {
			this.route = route
			const url = new URL(window.location.href)
			url.searchParams.set('route', route)
			window.history.replaceState({}, '', url)
			this.applyDocumentTitle()
		},

		/**
		 * Mint a test session where the server accepts the dev login, and
		 * carry on signed in.
		 *
		 * @return {Promise<void>} Resolves when signed in, or refused.
		 *
		 * @spec openspec/changes/portal-signin-on-its-own-address/tasks.md#T2
		 */
		async devLogin() {
			this.devError = ''
			const minted = await this.api.devLogin(this.signinConfig.audience || undefined)
			if (!minted) {
				this.devError = this.t('Dev-login is disabled on this environment.')
				return
			}
			this.session = await fetchSession(authBaseFrom(resolveApiBase()))
			this.watchIdle()
			await this.loadAccount()
		},

		/**
		 * Try a silent sign-in once per browser session, where the
		 * organisation turned it on, and never after a failed sign-in or an
		 * inactivity sign-out. The login returns to this page.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T09
		 */
		trySilentSignIn() {
			if (this.signinFailed || this.idleSignedOut) {
				return
			}
			let store
			try {
				store = window.sessionStorage
			} catch {
				return
			}
			const url = silentSignInUrl(
				{
					apiBase: authBaseFrom(resolveApiBase()),
					signinOrganisation: this.signinConfig.signinOrganisation || '',
					silentSignIn: this.signinConfig.silentSignIn || '',
					organisationSlug: this.site.slug || this.portalSlug || '',
				},
				store,
			)
			if (url) {
				const back = window.location.pathname + window.location.search
				window.location.assign(`${url}&returnTo=${encodeURIComponent(back)}`)
			}
		},

		/**
		 * Put the PORTAL's name in the browser tab.
		 *
		 * Found by comparing against the portal this replaces: that one titles
		 * its tab properly and this one said "Nextcloud" — the hosting
		 * platform's name, on a white-label portal whose entire purpose is
		 * that a visitor never learns what it is built on.
		 *
		 * It is not a cosmetic detail. The tab title is the bookmark name, the
		 * history entry, the window-switcher label and the search-result
		 * heading. A municipality's portal filed under "Nextcloud" is wrong in
		 * every one of those places at once, and none of them appear in a
		 * screenshot of the page.
		 *
		 * @return {void}
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-request-must-resolve-to-exactly-one-portal-or-to-none
		 */
		applyDocumentTitle() {
			const portalName = this.site.title
			if (!portalName) {
				return
			}

			// The page's search title first (site-page-seo-history-and-media),
			// so the tab reads what the server already put in the head.
			const pageName = this.accountRoute
				? this.accountEntry?.label || this.t('My overview')
				: this.page?.seo?.title || this.page?.title
			document.title =
				pageName && pageName !== portalName
					? `${pageName} - ${portalName}`
					: portalName
		},

		/**
		 * End the portal session and return to the signed-out view.
		 *
		 * The local state is cleared even when the edge refuses, because the
		 * alternative is a page that says "signed in" to somebody who has just
		 * asked, twice, not to be.
		 *
		 * @return {Promise<void>} Resolves when signed out.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-must-offer-only-the-sign-in-routes-it-declares
		 */
		async signOut() {
			const token = adoptSessionToken()
			let answer = null
			try {
				const response = await fetch(
					`${authBaseFrom(resolveApiBase())}/session`,
					{
						method: 'DELETE',
						credentials: 'include',
						// The edge revokes the session the BEARER names. Without it
						// the request is anonymous, the server revokes nothing, and
						// only this tab forgets — a sign-out that leaves a live
						// token behind is the one failure mode that matters here.
						headers: token
							? {
									Accept: 'application/json',
									Authorization: `Bearer ${token}`,
								}
							: {},
					},
				)
				answer = response.ok ? await response.json() : null
			} catch {
				// Reported by the state change below, not by an alert.
			}

			clearSessionToken()
			this.endIdle()
			this.session = null
			this.forgetAccount()

			// The broker's own sign-out, when it offers one
			// (signin-session-idle-warning-and-sso T11).
			const target = logoutTarget(answer)
			if (target) {
				window.location.assign(target)
			}
		},

		/**
		 * Start the idle window for the session on screen: activity refreshes
		 * the bearer, idling opens the warning, expiry signs out.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T06
		 */
		watchIdle() {
			this.idleTracker?.stop()
			if (!this.session) {
				return
			}
			this.idleTracker = createIdleTracker({
				refresh: () => refreshSession(authBaseFrom(resolveApiBase())),
				onTimes: (times) => {
					this.idleTimes = times
					this.idleWarning = false
				},
				onWarn: () => {
					this.idleWarning = true
				},
				onEnd: () => {
					clearSessionToken()
					this.endIdle()
					this.session = null
					this.forgetAccount()
					this.idleSignedOut = true
				},
			})
			this.idleTimes = {
				expiresAt: Number(this.session.expiresAt),
				hardExpiresAt: Number(this.session.hardExpiresAt),
				idleTimeout: Number(this.session.idleTimeout),
			}
			this.idleSignedOut = false
			this.idleTracker.start(this.session)
		},

		/**
		 * "Stay signed in": refresh now.
		 *
		 * @return {Promise<void>} Resolves when refreshed.
		 *
		 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T06
		 */
		async staySignedIn() {
			await this.idleTracker?.extend()
		},

		/**
		 * Stop the idle window.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T06
		 */
		endIdle() {
			this.idleTracker?.stop()
			this.idleTracker = null
			this.idleTimes = null
			this.idleWarning = false
		},

		/**
		 * Load one page by route.
		 *
		 * @param {string} route The in-portal route.
		 * @return {Promise<void>} Resolves when loaded.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-unpublished-content-must-be-indistinguishable-from-absent-content
		 */
		async loadRoute(route) {
			this.signInNeeded = false
			// The signed-in area renders from the session, not from a CMS
			// page, so no page is read for it.
			if (isAccountRoute(route)) {
				this.page = null
				this.error = null
				this.routeParam = ''
				this.loading = false
				this.followAccountRoute()
				this.applyDocumentTitle()
				return
			}

			this.loading = true
			this.error = null
			this.routeParam = ''
			try {
				this.page = await fetchPage(route, this.portalSlug)
			} catch (error) {
				// A ROUTE CAN ADDRESS A THING RATHER THAN A PAGE.
				//
				// `/publicatie/8f21…` is one page and thousands of subjects; no
				// CMS holds a page per publication. So an unresolved route
				// falls back to its PARENT and hands the trailing segment to
				// the page as `routeParam`, which is how `/publicatie/<id>`
				// resolves to the `/publicatie` page rendering that id.
				//
				// Only ONE level, and only on a 404. Walking all the way up
				// would make `/does/not/exist` render the home page — a
				// mistyped URL answering with content is worse than answering
				// with "not found", because nothing tells the visitor they are
				// in the wrong place.
				const parent = this.parentRoute(route)
				if (this.isNotFound(error) === true && parent !== null) {
					try {
						this.page = await fetchPage(parent, this.portalSlug)
						this.routeParam = route.slice(parent.length + 1)
						this.loading = false
						return
					} catch (parentError) {
						this.error = parentError
						this.page = null
						this.loading = false
						return
					}
				}

				this.page = null
				// A page behind the portal's sign-in shows the way in rather
				// than an error: the signed-in area renders signed out.
				this.signInNeeded = Boolean(error && (error.status === 401 || error.status === 403))
				// A 404 is information, not a fault — an unknown route and an
				// unpublished page are answered identically by the API on
				// purpose, and both belong on screen as "not found".
				this.error = error
			} finally {
				this.loading = false
			}

			// After the page, never before it. The probe is an addition for
			// editors and must not be able to delay — or fail — the content
			// every other visitor came for.
			await this.refreshEditingContext()
		},

		/**
		 * Leave edit mode and read the page again.
		 *
		 * @return {Promise<void>} Resolves when the page is shown.
		 *
		 * @spec openspec/specs/portal-in-place-editing/spec.md#requirement-an-editor-must-be-able-to-edit-a-page-in-place-on-the-portal-req-pie-006
		 */
		async leaveEditMode() {
			if (this.unmountEditor) {
				this.unmountEditor()
				this.unmountEditor = null
			}
			this.editMode = false
			await this.loadRoute(this.route)
		},

		/**
		 * Load the editor bundle and mount it where the page was.
		 *
		 * @return {Promise<void>} Resolves when the editor is mounted.
		 *
		 * @spec openspec/specs/portal-in-place-editing/spec.md#requirement-an-editor-must-be-able-to-edit-a-page-in-place-on-the-portal-req-pie-006
		 */
		async enterEditMode() {
			this.editMode = true
			this.editorStatus = 'De editor wordt geladen…'
			try {
				const editor = await loadSiteEditor()
				await this.$nextTick()
				if (!this.$refs.editorHost) {
					return
				}
				this.unmountEditor = editor.mount(this.$refs.editorHost, {
					pageId: this.editing.pageId,
					portal: (this.site && this.site.slug) || this.portalSlug || '',
					onLeave: () => this.leaveEditMode(),
				})
				this.editorStatus = ''
			} catch {
				this.editorStatus =
					'De editor kon niet worden geladen. Laad de pagina opnieuw en probeer het nog eens.'
			}
		},

		/**
		 * Resolve whether this visitor may edit the page on screen.
		 *
		 * Asked at most ONCE for a visitor who may not: `canEdit` is a property
		 * of the session, not of the route, so a refusal settles the question
		 * for the whole visit. For an editor it is re-asked per route, because
		 * WHICH page is behind a route is exactly what changes.
		 *
		 * @return {Promise<void>} Resolves when the context is settled.
		 *
		 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-site-must-offer-an-editing-entry-point-only-to-a-visitor-who-may-edit
		 */
		async refreshEditingContext() {
			if (this.editingDenied === true) {
				return
			}

			const context = await fetchEditingContext(
				editorBaseFrom(resolveApiBase()),
				this.route,
				this.portalSlug,
			)

			if (context === null) {
				this.editingDenied = true
				this.editing = null
				return
			}

			this.editing = context
		},

		/**
		 * The parent of a multi-segment route, or null when there is none.
		 *
		 * @param {string} route The in-site route.
		 * @return {string|null} The parent route.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
		 */
		parentRoute(route) {
			const segments = String(route || '')
				.split('/')
				.filter(Boolean)

			if (segments.length < 2) {
				return null
			}

			return `/${segments.slice(0, -1).join('/')}`
		},

		/**
		 * Whether a load error was a 404 rather than a real failure.
		 *
		 * Checked explicitly: retrying the parent on a 500 would turn a broken
		 * backend into a page that renders, which hides the outage.
		 *
		 * @param {object} error The rejection from fetchPage.
		 * @return {boolean} True when the route simply does not exist.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
		 */
		isNotFound(error) {
			return (error && error.status) === 404
		},

		/**
		 * Navigate within the site without a full page load.
		 *
		 * @param {string} link The target route.
		 * @return {void}
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
		 */
		/**
		 * Navigate to an in-site route without leaving the document.
		 *
		 * The counterpart to `onPopState`: this one pushes the entry, that one
		 * consumes it, and both end in `loadRoute` so forward and back render
		 * by the same path.
		 *
		 * ONLY ROOT-RELATIVE LINKS ARE FOLLOWED HERE. Anything else — an
		 * absolute URL, a `mailto:`, a protocol-relative `//host` — is left to
		 * the browser, which is the only thing entitled to leave this origin.
		 *
		 * @param {string} link The in-portal route, e.g. `/begrippen`.
		 * @return {void}
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
		 */
		/**
		 * A real, shareable href for an in-site route.
		 *
		 * The breadcrumb intercepts the click, but the anchor still carries a
		 * working URL so middle-click, "open in new tab" and a page whose
		 * bundle failed to load all behave.
		 *
		 * @param {string} route The in-site route.
		 * @return {string} The href.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
		 */
		/**
		 * Take a term typed into the home page's hero straight to the results.
		 *
		 * The term travels in `_search`, which is the parameter the search
		 * block reads on mount — so the landing page's box and the search
		 * page's box produce the same URL, and that URL is shareable.
		 *
		 * @param {string} term The submitted term.
		 * @return {void}
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
		 */
		goSearch(term) {
			const url = new URL(window.location.href)
			// The portal the page is served as stays on the address: on an
			// instance with several portals, an address without it opens
			// another portal, or none, after a reload or in a new tab.
			const portal = url.searchParams.get('portal')
			url.search = ''
			url.hash = ''
			if (portal) {
				url.searchParams.set('portal', portal)
			}
			url.searchParams.set('route', this.searchRoute)
			if (term) {
				url.searchParams.set('_search', term)
			}

			this.route = this.searchRoute
			window.history.pushState({}, '', url)
			this.loadRoute(this.searchRoute)
		},

		/**
		 * A shell block's authored props, without `style` and `class`. The
		 * shell's own data is bound after them, so it wins.
		 *
		 * @param {object} block The region's block.
		 * @return {object} The authored props.
		 *
		 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-blocks-must-take-their-data-as-props-and-nothing-else-req-ptb-007
		 */
		authoredProps(block) {
			return withoutStyling(block.props)
		},

		/**
		 * A real, shareable href for an in-site route.
		 *
		 * The breadcrumb intercepts the click, but the anchor still carries a
		 * working URL so middle-click, "open in new tab" and a page whose
		 * bundle failed to load all behave.
		 *
		 * @param {string} route The in-site route.
		 * @return {string} The href.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
		 */
		hrefForRoute(route) {
			const url = new URL(window.location.href)
			// The portal the page is served as stays on the address: on an
			// instance with several portals, an address without it opens
			// another portal, or none, after a reload or in a new tab.
			const portal = url.searchParams.get('portal')
			url.search = ''
			url.hash = ''
			if (portal) {
				url.searchParams.set('portal', portal)
			}
			if (route && route !== '/') {
				url.searchParams.set('route', route)
			}

			return url.toString()
		},

		go(link) {
			if (!link || !link.startsWith('/')) {
				return
			}

			this.route = link
			const url = new URL(window.location.href)
			url.searchParams.set('route', link)
			window.history.pushState({}, '', url)
			this.loadRoute(link)
		},
	},
}
</script>

<!--
	THE PAGE SHELL. Unscoped on purpose: these rules target Nextcloud's own
	`body` and `#content`, which are not this component's elements and which a
	scoped block therefore cannot reach.

	MEASURED on a live instance before this block existed — a white-label
	government portal was rendering as a 451px-wide white column floating on
	Nextcloud's blue theming WALLPAPER:

	    body      position: fixed; background: rgb(0,103,158)
	                                url(/apps/theming/img/background/jo-my…)
	    #content  position: fixed; margin: 50px 8px 8px      <- app chrome
	    .pq-site  451px of 1264    <- shrink-wrapped: NC makes #content a flex
	                                  container and the site never claimed width

	Removing the header (RENDER_AS_BASE) took away the bar at the top and left
	all of that behind. A visitor still saw Nextcloud — just without its name
	on it, which is arguably worse than the header was.

	So the public site resets the page it is served in: no wallpaper, no fixed
	positioning, no app-chrome offsets, full width. Guarded by `.layout-base`
	and `#content.app-public` so it can only ever apply on the public site
	route, never to the admin SPA rendered by the same app.
-->
<style>
body.layout-base {
	position: static;
	width: 100%;
	min-height: 100vh;
	margin: 0;
	background-image: none;
	background-color: var(--nldesign-color-page-background, #fff);
	overflow: auto;
}

body.layout-base #content.app-public {
	position: static;
	display: block;
	width: 100%;
	max-width: none;
	min-height: 100vh;
	margin: 0;
	padding: 0;
	border-radius: 0;
	background: transparent;
}

body.layout-base .pq-site {
	width: 100%;
	min-height: 100vh;
}
</style>

<style scoped>
.pq-site-hero {
	display: block;
	max-width: 100%;
	height: auto;
}

/*
 * THE THEME BRIDGE. Before this block the renderer read `--pq-*` variables
 * that NOTHING EVER SET, so every portal fell through to the same hardcoded
 * fallbacks and two differently-themed portals were pixel-identical. The
 * themiq token file was already loading; it was simply never consumed.
 *
 * Each `--pq-*` is now a CHAIN, not a single lookup, because the 44 themiq
 * themes do not share one vocabulary — `vng.css` and `venray.css` have
 * literally no token name in common. A single `var(--nldesign-color-text)`
 * would theme a third of the fleet and silently miss the rest. The chain ends
 * in the original hardcoded value, so a portal with no theme, or a theme that
 * defines none of these, renders exactly as it did before.
 *
 * Portaliq still defines NO tokens of its own (ADR-086 §6). It only decides
 * which themiq token a given surface reads.
 */
.pq-site {
	/*
	 * `--nldesign-font-family` FIRST, because it is the one the themes
	 * actually define. Measured: `vng.css` contains ZERO occurrences of
	 * `--nldesign-typography-sans-serif-font-family` and defines
	 * `--nldesign-font-family: 'Avenir', …, Roboto, …`. So this chain resolved
	 * to `system-ui` on every themed portal — the theme was loaded, consumed
	 * for colour, and silently ignored for type.
	 *
	 * This is the disjoint-vocabulary problem the comment above describes,
	 * caught in the block that was written to avoid it: I picked a token name
	 * without checking it against a real theme file.
	 */
	/*
	 * `--utrecht-document-font-family` FIRST, because that is the name the
	 * NLDS component CSS and the generated token sets actually agree on, and
	 * because WITHOUT it the portal inherits Nextcloud's own theme font.
	 *
	 * MEASURED on :8080: the vng token resolved correctly to `'Avenir',
	 * sans-serif` at the portal root, no loaded stylesheet set a font on
	 * `.logo-text` at all, and the heading still rendered in **Marianne** —
	 * inherited from Nextcloud's `lasuite.css`. A themed portal was wearing
	 * the host platform's typeface while every token was present and correct.
	 * The `--nldesign-*` names below stay as the fallback chain for themes
	 * that only define those.
	 */
	--pq-font-family: var(
		--utrecht-document-font-family,
		var(
			--nldesign-font-family,
			var(--nldesign-typography-sans-serif-font-family, system-ui, sans-serif)
		)
	);
	--pq-text-color: var(
		--nldesign-color-text,
		var(--nldesign-color-black, #1a1a1a)
	);
	--pq-heading-color: var(
		--nldesign-color-primary,
		var(--nldesign-color-text, #1a1a1a)
	);
	--pq-border-color: var(--nldesign-color-border, #d0d0d0);
	--pq-muted-color: var(--nldesign-color-text-muted, #6b6b6b);
	--pq-link-color: var(
		--nldesign-color-link,
		var(--nldesign-color-primary, #0b5cab)
	);
	/*
	 * NO GLOBAL FONT. The design system assigns type PER COMPONENT:
	 * measured on the reference, `.logo-text` is Avenir 36/700 while
	 * `.ac-c-navigation__label` is Roboto 16/500 — two families on one
	 * page, both token-driven. A blanket family here overrode the nav
	 * with Avenir 600 and left the bar 1px short of the reference.
	 *
	 * This declaration existed to beat Nextcloud's own `lasuite.css`
	 * (Marianne) while the portal rendered inside the NC shell. It does
	 * not render there any more, so the workaround outlived its cause.
	 */
	/*
	 * NO DOCUMENT COLOUR. This is the THIRD rule of this shape removed from
	 * this renderer, after `.pq-site :any-link` and MarkdownBlock's heading
	 * colour, and it failed the same way.
	 *
	 * It set `#333` on the whole portal. The design system does not: measured
	 * on the reference, `html`, `body` and every ancestor of a card are
	 * rgb(0, 0, 0) — the browser default — and text that should be grey gets
	 * there through `utrecht-paragraph`, per element, on a known surface.
	 *
	 * Inheriting #333 instead put card headings at rgb(51, 51, 51) against the
	 * design's rgb(0, 0, 0). The description matched only by coincidence: #333
	 * is what `utrecht-paragraph` sets anyway, so the blanket rule looked
	 * right everywhere it happened to agree and was wrong everywhere else.
	 */
	background: var(--pq-bg-color, #ffffff);
	min-height: 100vh;

	/*
	 * FULL BLEED. Nextcloud's `#content` gives the app a padded, inset
	 * column — correct for an app, wrong for a portal that is supposed to
	 * BE the page. Measured against the reference: its container is
	 * 1280x…@0 while ours rendered 1235 wide starting 50px down, so every
	 * band (header, nav, footer) was narrower and lower than the design.
	 * Reset the inherited box rather than fighting it per-element.
	 */
	margin: 0;
	padding: 0;
	width: 100%;
	max-width: none;
}

/*
 * THERE IS DELIBERATELY NO BLANKET LINK COLOUR HERE.
 *
 * `.pq-site :any-link { color: var(--pq-link-color) }` used to sit at this
 * spot. One selector, every anchor on the portal, one colour — and because a
 * scoped style loads last it beat every context-specific rule the design
 * system has.
 *
 * MEASURED in the footer: `nlds-app.css` says `.ac-footer a { color: inherit }`
 * so footer links take the band's white; ours computed rgb(0, 68, 136) against
 * a rgb(0, 69, 137) background. Those two colours are one step apart. The links
 * were not merely off-brand, they were INVISIBLE — a contrast failure on a
 * government portal, produced by a rule whose whole purpose was to make links
 * look right.
 *
 * The design system already colours links per context — `.ac-footer a`,
 * `.ac-c-navigation__*`, `.utrecht-link` — each against the background it
 * actually sits on, which is the only way contrast can be reasoned about. A
 * portal-wide override cannot know that background and so cannot be correct.
 *
 * `--pq-link-color` is still defined above and still used by `MarkdownBlock`,
 * which renders body copy on a known light surface.
 */

/*
 * The `.pq-site__skip` rules that sat here are gone with the element they
 * styled. They were a hand-rolled `left: -9999px` / `position: static on
 * focus` pair, and they could never have applied to the skip link's new home
 * anyway: these styles are SCOPED, so they carry a `data-v-*` attribute
 * selector that markup emitted by `templates/site.php` does not have.
 *
 * `@utrecht/skip-link-css` — already imported by `main.js` — does the job
 * properly. MEASURED on the server-rendered link: `position: fixed`, parked at
 * y=-1440 while unfocused and snapping to y=0 on focus. The off-left trick
 * this file used is the older, worse version of that; some screen readers
 * treat a far-off-screen element as decorative.
 */

/*
 * NO LAYOUT HERE. These elements now also carry the reference implementation's
 * `ac-header` / `ac-app-main` / `ac-footer` classes, and `nlds-app.css` styles
 * them. Anything this file says about their box wins — scoped styles carry a
 * data-attribute and load last — so every rule below was silently overriding
 * the design system it was supposed to be adopting.
 *
 * MEASURED, ours against the reference, before this block was removed:
 *
 *     .ac-header                 padding  0        <- 24px 20px   (this file)
 *     .ac-header__navigation-main  width  1280px   <- 1232px      (consequence)
 *     .ac-app-main               width    1280px   <- 1088px, max-width 1088
 *     .ac-footer                 padding  0        <- 24px 16px
 *
 * Every delta traced back here, not to a missing rule in the vendored CSS. So
 * the fix is deletion: let the design system own the layout, and keep this
 * file for what is genuinely portal-specific.
 */

dt {
	font-weight: 600;
	margin-top: 0.75rem;
}

dd {
	margin: 0.25rem 0 0;
}

/*
 * THE BREADCRUMB IS ONE LINE.
 *
 * `nlds-app.css` carries no rule for `.ac-breadcrumb`, so its list items were
 * block-level and the separator dropped onto a line of its own — a three-line
 * trail where the reference has one.
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
