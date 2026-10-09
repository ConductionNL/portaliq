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
				:showNavigation="!menuOnPage"
				:currentRoute="route"
				:breadcrumbs="breadcrumbs"
				:session="session"
				:sessionLabel="sessionLabel"
				:accountLink="ownAreaLink"
				:signInRoutes="signInRoutes"
				:registerRoute="registerRoute"
				:signinFailedMessage="signinFailed ? signinFailedMessage : ''"
				:registerLabel="t('Register')"
				:signOutLabel="t('Sign out')"
				:userMenuLabel="t('User menu')"
				:breadcrumbLabel="t('Breadcrumb')"
				:logoLabel="t('Logo')"
				:searchBox="headerSearch"
				:searchLabel="t('Search')"
				:accountLabel="site.accountLabel || ''"
				:accountHref="hrefForRoute('/mijn')"
				:menuLabel="t('Menu')"
				@navigate="go"
				@search="goSearch"
				@signout="signOut">
				<template #account>
					<ActingForSwitcher :t="t" />
					<!-- The eHerkenning branch in effect, or the choice of one (REQ-SRP-011). -->
					<BranchSwitcher
						v-if="session"
						:t="t"
						:api="api"
						:session="session" />
				</template>
			</BrandHeader>
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

		<!-- The answer to a `#confirm-email=` link (identity-profile-page T08). -->
		<p
			v-if="confirmMessage"
			class="container utrecht-paragraph"
			:role="confirmMessage.role"
			data-testid="site-confirm-email">
			{{ confirmMessage.text }}
		</p>

		<!-- What came of an invitation link (`#claim=`), or the ask to sign
		     in for it (invitation-secret-joins-the-signed-in-account). -->
		<p
			v-if="claimMessage"
			class="container utrecht-paragraph"
			:role="claimMessage.role"
			data-testid="site-claim-invitation">
			{{ claimMessage.text }}
		</p>

		<!--
			The ask for an e-mail address while the account has none. On a
			`/mijn` page the signed-in area shows it in its own content column,
			above the page heading (see AccountArea's `prompt` slot below);
			here it stands above any other page.
		-->
		<div
			v-if="session && contactPrompt && !accountRoute"
			class="container pq-site__contact-prompt">
			<ContactPrompt
				:t="t"
				:navigate="goSection"
				:texts="site.contactPrompt || {}"
				@dismiss="contactPrompt = false" />
		</div>

		<!--
			The install offer (REQ-SRP-046). Renders nothing until the browser
			offers installation, so no empty box stands here otherwise.
		-->
		<InstallBanner class="container" :t="t" />

		<!-- Maintenance and warning notices running now (operate-maintenance-notice). -->
		<SiteNotices
			v-if="shownNotices.length > 0"
			:notices="shownNotices"
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
					v-if="
						!guestLink
						&& !loading
						&& !error
						&& page
						&& regions.hero.length
					"
					data-testid="site-region-hero"
					:widgets="regions.hero"
					v-bind="gridContext"
					@navigate="go"
					@search="goSearch" />

				<!--
					THE SIDE MENU (site-navigation-block). When the side region
					holds a menu block it renders as a column LEFT of the content,
					and first in the document, so the reading order and the tab
					order match what is seen. On a phone the column stacks above
					the content and the block collapses behind its own button.
				-->
				<div
					class="pq-site__layout"
					:class="{ 'pq-site__layout--side-menu': showSideMenu }"
					data-testid="site-layout">
					<aside
						v-if="showSideMenu"
						class="pq-site__aside pq-site__aside--menu"
						data-testid="site-region-aside">
						<WidgetGrid
							:widgets="regions.aside"
							v-bind="gridContext"
							@navigate="go"
							@search="goSearch" />
					</aside>
					<div class="pq-site__content">
						<!-- A signed link opens its one act before any page (REQ-GST-002). -->
						<GuestActionPage
							v-if="guestLink"
							:authBase="guestAuthBase"
							:portal="site.slug || portalSlug" />

						<!-- A mailed way in (an activation, an invitation, one case by
				     its number) opens before any page (identity-ways-in-screens). -->
						<WayInLink
							v-else-if="wayInLink"
							:authBase="guestAuthBase"
							:portal="site.slug || portalSlug"
							:portalName="site.title || ''"
							:emailSignIn="waysIn.emailSignIn"
							:t="waysInT" />

						<!-- The signed-in area owns every `/mijn` route; no CMS page is
				     read for it (src/shared/portalNav.js). -->
						<AccountArea
							v-else-if="accountRoute || (signInNeeded && !session)"
							:sessionKnown="sessionKnown"
							:session="session"
							:loading="account.loading"
							:nav="nav"
							:entry="accountEntry"
							:contributions="account.contributions"
							:api="api"
							:signInRoutes="signInRoutes"
							:ways="waysIn"
							:waysT="waysInT"
							:authBase="guestAuthBase"
							:portalSlug="site.slug || portalSlug"
							:devLogin="signinConfig.devLogin === true"
							:devError="devError"
							:t="t"
							:locale="locale"
							:portal="site"
							:menuGroups="residentMenu"
							:menuPerson="residentMenuPerson"
							:menuSubline="residentMenuSubline"
							:currentRoute="route"
							@devlogin="devLogin"
							@navigate="goSection"
							@unread="unreadOverride = $event"
							@refresh="loadAccount"
							@claimed="onCodeClaimed"
							@signout="signOut">
							<template v-if="session && contactPrompt" #prompt>
								<ContactPrompt
									:t="t"
									:navigate="goSection"
									:texts="site.contactPrompt || {}"
									@dismiss="contactPrompt = false" />
							</template>
						</AccountArea>

						<!-- A shared dossier link is public: anyone who has it reads the
				     documents in it that are public now (site-shared-dossier). -->
						<SharedDossierPage
							v-else-if="sharedDossierRoute"
							:token="sharedDossierToken"
							:instanceRoot="instanceRoot"
							:t="t"
							@loaded="onSharedDossierLoaded" />

						<p
							v-else-if="loading"
							class="container"
							role="status"
							data-testid="site-loading">
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
							:data-portaliq-status="
								error.status === 404 ? '404' : null
							"
							:data-portaliq-path="
								error.status === 404 ? route : null
							">
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
							:lang="contentLocale"
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
								<h1
									class="utrecht-heading-2"
									data-testid="page-title">
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
									:source="
										(page.body && page.body.markdown) || ''
									" />
							</div>
						</article>
					</div>
				</div>

				<aside
					v-if="
						!showSideMenu
						&& !loading
						&& !error
						&& page
						&& regions.aside.length
					"
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
import {
	codeOutcome,
	forgetClaimSecret,
	keepClaimSecret,
	redeemKeptClaim,
} from '../shared/claimInvitation.js'
import { createTranslator } from '../shared/i18n/index.js'
import { logoutTarget, silentSignInUrl } from '../shared/idleSession.js'
import { noticesFor } from '../shared/notices.js'
import { consumeOpenTarget, OPEN_STORAGE_KEY } from '../shared/openRecord.js'
import { createPortalApi } from '../shared/portalApi.js'
import {
	ACCOUNT_ROUTE,
	buildNav,
	isAccountRoute,
	navEntryForRoute,
	routeForNav,
	shellSections,
} from '../shared/portalNav.js'
import { forgetActingFor, learnMandates } from './components/e/actingFor.js'
import { ActingForSwitcher, ContactPrompt } from './components/e/index.js'
import { InstallBanner } from './components/f/index.js'
import { accountCrumbs, accountRedirect, loggedInAs } from './lib/accountArea.js'
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
import { instanceRootFrom } from './lib/instanceRoot.js'
import { languageEntries, requestedLocale } from './lib/languageNav.js'
import { loadSiteEditor } from './lib/loadSiteEditor.js'
import { blocksOwnHeading } from './lib/pageHeading.js'
import { pageRegionsOf, resolveRegions } from './lib/regions.js'
import {
	loadPerRecordRows,
	menuPerson,
	menuSubline,
	ownAreaLink as ownAreaLinkFor,
	residentMenuGroups,
	showsResidentMenu,
	withAreaName,
	withLayoutLabel,
} from './lib/residentMenu.js'
import { isSharedDossierRoute, sharedDossierToken } from './lib/sharedDossier.js'
import {
	footerMenusOf,
	headerMenusOf,
	headerSearchOf,
	headerVariantOf,
	legalLinksOf,
	menuLabelFor,
	registerRouteOf,
} from './lib/shellData.js'
import {
	hasNavigationBlock,
	navigationGroups,
	sideMenuOf,
} from './lib/siteNavigation.js'
import { hasWayInLink, waysInFrom, waysInTranslator } from './lib/waysIn.js'
import { openRecordEntry } from './pages/collections/index.js'
import { confirmEmailFromLink, contactPromptWanted } from './pages/e/index.js'
import { TASK_STORAGE_KEY } from './pages/inbox/inbox.js'

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

// The branch line in the header, loaded only for a signed-in session, so an
// anonymous visitor pays nothing for it (site-reaches-portal-parity REQ-SRP-011).
const BranchSwitcher = defineAsyncComponent(
	() => import('./components/BranchSwitcher.vue'),
)

// Loaded only when a notice is running, so a portal without one pays nothing
// for it in the site bundle (operate-maintenance-notice).
const SiteNotices = defineAsyncComponent(
	() => import('./components/SiteNotices.vue'),
)

// Loaded only when somebody opens a shared dossier link, so every other
// visitor pays nothing for it in the site bundle (site-shared-dossier).
const SharedDossierPage = defineAsyncComponent(
	() => import('./components/SharedDossierPage.vue'),
)

// The guest page for a signed link (identity-guest-page-for-signed-links),
// loaded only when the address carries one.
const GuestActionPage = defineAsyncComponent(
	() => import('./pages/GuestActionPage.vue'),
)

// What a mailed way-in link opens (identity-ways-in-screens), loaded only
// when the address carries one.
const WayInLink = defineAsyncComponent(() => import('./components/WayInLink.vue'))

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
		ActingForSwitcher,
		BranchSwitcher,
		ContactPrompt,
		BrandHeader,
		FooterColumns,
		GuestActionPage,
		WayInLink,
		IdleWarningDialog,
		InstallBanner,
		MarkdownBlock,
		SharedDossierPage,
		SiteEditButton,
		SiteNotices,
		WidgetGrid,
	},

	/**
	 * The language the page's content is written in, for the blocks that
	 * format a date inside it (site-dates-in-content-language). A function,
	 * so a block reads the current page's language after a navigation.
	 *
	 * @return {{siteContentLocale: () => string}} The provided values.
	 * @spec openspec/changes/site-dates-in-content-language/specs/site-look/spec.md#requirement-a-date-inside-page-content-must-read-in-the-content-language
	 */
	provide() {
		return { siteContentLocale: () => this.contentLocale }
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

			// The rows a page lists itself per row of (`perRecord`), by
			// `<app>:<collection>` (site-mijn-omgeving-components REQ-SMO-020).
			recordRows: {},

			// The inbox's unread count after a page changed it, else null.
			unreadOverride: null,
			devError: '',
			// The page on screen is behind the portal's sign-in.
			signInNeeded: false,
			// The answer to a `#confirm-email=` link, or null.
			confirmMessage: null,
			// What came of an invitation link (`#claim=`), or null.
			claimMessage: null,
			// Whether to ask for an e-mail address (slice e's ContactPrompt).
			contactPrompt: false,
			// A signed link for one guest act (`#guest/...`); the page reads it.
			guestLink: String(window.location.hash).startsWith('#guest/'),
			// A mailed way in (`#activate=`, `#invitation=`, `#reference=`).
			wayInLink: hasWayInLink(window.location),
			site: {},
			// The language the visitor chose with the language switch
			// (`?lang=`), sent on every content read. '' asks for the
			// portal's default.
			chosenLocale: requestedLocale(window.location.search),
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
			// The title of the shared dossier on screen, once it is read.
			sharedDossierTitle: '',
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
			// The token is not a word and no page sits at its parent.
			if (this.sharedDossierRoute) {
				return [
					{
						route: '/',
						label: this.t('Home'),
						href: this.hrefForRoute('/'),
					},
					{
						route: this.route,
						label: this.sharedDossierTitle || this.t('Shared dossier'),
						href: this.hrefForRoute(this.route),
					},
				]
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
				// The header menu's own words for a route it names, so the trail
				// reads like the menu ("Home › Afval"), on every crumb.
				const fromMenu = menuLabelFor(this.menus, route)
				if (fromMenu !== '') {
					label = fromMenu
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
		 * @spec openspec/changes/site-page-layout/specs/site-look/spec.md#requirement-a-page-must-have-one-title-heading
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
			// page then keeps one h1 (REQ-PTB-009). So does an `nlHeading` at
			// level 1 (site-page-layout).
			return blocksOwnHeading([...this.regions.hero, ...main])
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
				navigation: this.navigation,
				languages: this.languages,
				// The portal's sign-in ways, for the nlSignIn block (lane L2, G-13).
				signInRoutes: this.signInRoutes,
			}
		},

		/**
		 * The language switch's data: the portal's own locales as links to
		 * this page in each, and the one in effect. The content API answered
		 * both on `/site` (ContentController::site), so nothing here invents
		 * a language.
		 *
		 * @return {{locales: Array<object>, current: string}} The switch's props.
		 *
		 * @spec openspec/changes/language-switch-reaches-the-content/specs/portaliq-cms/spec.md#requirement-the-language-switch-offers-the-portals-locales-and-the-choice-reaches-the-content
		 */
		languages() {
			return {
				locales: languageEntries(
					this.site.locales,
					this.hrefForRoute(this.route),
				),

				current: this.site.locale || '',
			}
		},

		/**
		 * The header's search box, and the page every search box opens
		 * (site-chrome-follows-the-design).
		 *
		 * @return {object} `{enabled, placeholder, route}`.
		 *
		 * @spec openspec/changes/site-chrome-follows-the-design/specs/site-chrome/spec.md#requirement-the-header-must-carry-the-search-box-and-one-way-to-the-own-area
		 */
		headerSearch() {
			return headerSearchOf(this.site)
		},

		/**
		 * Where a search box sends a term: the portal's search page.
		 *
		 * @return {string} The route, `/zoeken` unless the portal names another.
		 *
		 * @spec openspec/changes/site-chrome-follows-the-design/specs/site-chrome/spec.md#requirement-the-header-must-carry-the-search-box-and-one-way-to-the-own-area
		 */
		searchRoute() {
			return this.headerSearch.route
		},

		/**
		 * The menu block's data: the header menus and the signed-in
		 * navigation in groups, the route on screen, and the labels in the
		 * site's language (site-navigation-block).
		 *
		 * @return {object} `{groups, currentRoute, label, toggleLabel}`.
		 *
		 * @spec openspec/changes/site-navigation-block/specs/portaliq-cms/spec.md#requirement-a-menu-block-must-show-the-portals-navigation-in-groups
		 */
		navigation() {
			return {
				groups: navigationGroups({
					// The resident's own items in the groups of the menu beside
					// `/mijn` (site-resident-menu), so both menus read alike.
					residentGroups:
						this.session && this.nav.length > 0
							? residentMenuGroups(
									this.nav,
									this.t,
									this.unreadCount,
									this.hrefForRoute,
									this.recordRows,
									this.site?.residentMenu?.groups,
									// Items the portal leaves out (resident-menu-leave-out).
									this.site?.residentMenu?.leaveOut,
								)
							: [],
					menus: headerMenusOf(this.menus),
				}),

				currentRoute: this.route,
				label: this.t('Menu'),
				toggleLabel: this.t('Menu'),
			}
		},

		/**
		 * Whether the CMS page carries a menu block, so the header leaves
		 * its own menu out and every link is on the page once.
		 *
		 * @return {boolean}
		 *
		 * @spec openspec/changes/site-navigation-block/specs/portaliq-cms/spec.md#requirement-a-page-with-a-menu-block-must-leave-the-header-menu-out
		 */
		menuOnPage() {
			return hasNavigationBlock(this.regions) && !this.accountRoute
		},

		/**
		 * Whether the side region renders as a menu column left of the
		 * content: it holds a menu block and the screen is a CMS page, not a
		 * one-off link or the editor. The signed-in area (`/mijn`) has its
		 * own menu beside the content (ResidentMenu, site-resident-menu).
		 *
		 * @return {boolean}
		 *
		 * @spec openspec/changes/site-navigation-block/specs/portaliq-cms/spec.md#requirement-a-page-with-a-menu-block-must-leave-the-header-menu-out
		 */
		showSideMenu() {
			return (
				sideMenuOf(this.regions)
				&& !this.guestLink
				&& !this.wayInLink
				&& !this.sharedDossierRoute
				&& !this.error
				&& !(this.editMode && this.editing && this.editing.pageId)
				&& this.page !== null
				&& !this.accountRoute
			)
		},

		/**
		 * The menus shown in the header bar: the website's own pages, never
		 * the resident's items.
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
		 * THE SIGNED-IN NAVIGATION USED TO BE ONE MORE MENU HERE, and a resident
		 * with a few apps installed got a bar of twenty links: the website's
		 * pages and every app's pages in one row. It now sits beside the content
		 * on the `/mijn` pages (residentMenu below, site-resident-menu).
		 *
		 * @return {Array} The header menus.
		 *
		 * @spec openspec/changes/site-resident-menu/specs/site-resident-menu/spec.md#requirement-the-blue-bar-must-carry-the-websites-pages-only-req-srm-001
		 */
		headerMenus() {
			return headerMenusOf(this.menus)
		},

		/**
		 * The resident's own menu in groups, shown beside the content on the
		 * `/mijn` pages only (AccountArea); empty when signed out.
		 *
		 * @return {Array<object>} The groups.
		 *
		 * @spec openspec/changes/site-resident-menu/specs/site-resident-menu/spec.md#requirement-the-residents-own-items-must-sit-in-a-menu-beside-the-content-req-srm-002
		 */
		residentMenu() {
			if (!showsResidentMenu(this.session, this.route, this.nav)) {
				return []
			}
			return residentMenuGroups(
				this.nav,
				this.t,
				this.unreadCount,
				this.hrefForRoute,
				this.recordRows,
				// The portal's own groups (zuiddrecht-resident-pages-match-the-boards).
				this.site?.residentMenu?.groups,
				// Items the portal leaves out (resident-menu-leave-out).
				this.site?.residentMenu?.leaveOut,
			)
		},

		/**
		 * The person block at the top of the resident menu, when the portal
		 * declares one (resident-menu-follows-the-boards).
		 *
		 * @return {object|null} `{initials, name, subline}`.
		 *
		 * @spec openspec/changes/resident-menu-follows-the-boards/specs/site-resident-menu/spec.md#requirement-the-menu-may-open-with-the-person-and-their-class
		 */
		residentMenuPerson() {
			return menuPerson(
				this.session,
				this.site?.residentMenu?.person,
				this.recordRows,
			)
		},

		/**
		 * The second line the portal's person collection gives the menu's
		 * top card ("4 medewerkers · via eHerkenning"), or ''.
		 *
		 * @return {string}
		 *
		 * @spec openspec/changes/resident-menu-follows-the-boards/specs/site-resident-menu/spec.md#requirement-the-menu-may-open-with-the-person-and-their-class
		 */
		residentMenuSubline() {
			return menuSubline(this.site?.residentMenu?.person, this.recordRows)
		},

		/**
		 * The top right link to the resident's own area, null when signed out.
		 *
		 * @return {object|null} The link.
		 *
		 * @spec openspec/changes/site-resident-menu/specs/site-resident-menu/spec.md#requirement-the-header-must-hold-the-name-the-way-to-the-own-area-and-sign-out-req-srm-003
		 */
		ownAreaLink() {
			return ownAreaLinkFor(this.session, this.t, this.hrefForRoute)
		},

		/**
		 * The notices above every page: the public ones, and once a resident
		 * is signed in also the signed-in ones the shell carries, each once
		 * (operate-maintenance-notice, REQ-SRP-010).
		 *
		 * @return {Array<object>} The notices.
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-notices-must-show-above-every-page-req-srp-010
		 */
		shownNotices() {
			return noticesFor(
				this.site.notices,
				runtimeConfig().portalNotices,
				this.session !== null,
			)
		},

		/**
		 * The language the page's content is written in: the page record's
		 * own `locale`, else the site's. A Dutch page on a portal that also
		 * serves English stays Dutch for an English browser, so its dates read
		 * "2 oktober", not "2 October" (site-dates-in-content-language). Also
		 * the page's `lang`, so a screen reader reads the content in its own
		 * language (WCAG 3.1.2).
		 *
		 * @return {string} A two-letter language.
		 * @spec openspec/changes/site-dates-in-content-language/specs/site-look/spec.md#requirement-a-date-inside-page-content-must-read-in-the-content-language
		 */
		contentLocale() {
			const own = String(this.page?.locale || '').trim()
			return own !== '' ? own.slice(0, 2).toLowerCase() : this.locale
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
		 * The resident's own area reads under the name the portal gives its
		 * account button, when it gives one.
		 *
		 * @return {(key: string, vars?: object) => string} The translator.
		 *
		 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
		 * @spec openspec/changes/example-site-zuiddrecht/specs/example-site/spec.md#requirement-the-own-area-must-carry-the-name-the-portal-gives-it
		 */
		t() {
			return withAreaName(
				createTranslator(this.locale),
				this.site?.accountLabel,
			)
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
					language: this.locale,
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
		 * @return {boolean} Whether the route on screen is a shared dossier link.
		 *
		 * @spec openspec/changes/site-shared-dossier/specs/site-shared-dossier/spec.md#requirement-a-shared-dossier-link-must-open-a-public-page-req-ssd-001
		 */
		sharedDossierRoute() {
			return isSharedDossierRoute(this.route)
		},

		/**
		 * @return {string} The share token of the route on screen, or ''.
		 *
		 * @spec openspec/changes/site-shared-dossier/specs/site-shared-dossier/spec.md#requirement-a-shared-dossier-link-must-open-a-public-page-req-ssd-001
		 */
		sharedDossierToken() {
			return sharedDossierToken(this.route)
		},

		/**
		 * @return {string} The Nextcloud instance root other apps are reached under.
		 *
		 * @spec openspec/changes/site-shared-dossier/specs/site-shared-dossier/spec.md#requirement-a-shared-dossier-link-must-open-a-public-page-req-ssd-001
		 */
		instanceRoot() {
			return instanceRootFrom(resolveApiBase())
		},

		/**
		 * @return {object|null} The navigation entry the route names.
		 *
		 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
		 */
		accountEntry() {
			// The page carries the word the portal's menu gives it, so the
			// heading and the breadcrumb read "Berichten" where the menu does
			// (mijn-messages-follow-the-boards).
			return withLayoutLabel(
				navEntryForRoute(this.nav, this.route),
				this.site?.residentMenu?.groups,
			)
		},

		/**
		 * @return {number} The inbox's unread count.
		 *
		 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
		 */
		unreadCount() {
			return (
				this.unreadOverride ?? (this.account.contributions?.unreadCount || 0)
			)
		},

		/**
		 * @return {string} The one sentence a failed sign-in shows.
		 *
		 * @spec openspec/specs/portal-broker-envelope-login/spec.md#requirement-a-failed-login-returns-to-the-login-screen-without-a-reason-req-bel-006
		 */
		signinFailedMessage() {
			return this.t(
				'Signing in did not work. Try again or choose another way in.',
			)
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
		 * The doors the site config opens besides the sign-in buttons.
		 *
		 * @return {object} See waysInFrom().
		 *
		 * @spec openspec/changes/archive/2026-10-02-identity-ways-in-screens/tasks.md#T07
		 */
		waysIn() {
			return waysInFrom(this.signinConfig)
		},

		/**
		 * The translator of the ways in: the site's, with their own strings
		 * for the keys its bundle lacks.
		 *
		 * @return {Function}
		 *
		 * @spec openspec/changes/archive/2026-10-02-identity-ways-in-screens/tasks.md#T08
		 */
		waysInT() {
			return waysInTranslator(this.t, this.locale)
		},

		/**
		 * The portal API base the guest page posts to.
		 *
		 * @return {string} The base, `.../portal/api`.
		 *
		 * @spec openspec/changes/archive/2026-10-01-identity-guest-page-for-signed-links/tasks.md#T03
		 */
		guestAuthBase() {
			return authBaseFrom(resolveApiBase())
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
			return signInRoutes(
				this.site,
				authBaseFrom(resolveApiBase()),
				this.t,
				// One click on a demo for the example resident (example-resident-demo-login).
				this.signinConfig.exampleResident || '',
				// Its way in stays out while the demo switch is off.
				this.signinConfig.exampleResidentWayIn || '',
			)
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
		// A notification's record link (`#open=<app>/<collection>/<id>`) is
		// kept in sessionStorage before anything else reads the address, so
		// it survives the sign-in and opens once the navigation has loaded.
		this.keepOpenTarget()
		// An invitation's secret (`#claim=<secret>`) is kept the same way,
		// and handed back once the visitor is signed in.
		keepClaimSecret(window.location, window.history, this.claimStorage())
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
					fetchSite(this.portalSlug, this.chosenLocale),
					this.unlessSignInNeeded(
						fetchMenus(this.portalSlug, this.chosenLocale),
						[],
					),
					this.unlessSignInNeeded(
						fetchGlossary(this.portalSlug, this.chosenLocale),
						[],
					),
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

			// A confirmation link needs no session; it is read once, at boot.
			this.confirmMessage = await confirmEmailFromLink({
				api: this.api,
				t: this.t,
			})

			// A kept invitation is handed back before the account loads, so
			// what it shares is there on the first read.
			this.claimMessage = await redeemKeptClaim({
				api: this.api,
				session: this.session,
				t: this.t,
				storage: this.claimStorage(),
			})

			if (this.session) {
				await this.loadAccount()
			} else {
				this.trySilentSignIn()
			}
		},

		/**
		 * A code from a letter was right (invitation-code-from-a-letter).
		 * Reading the account again rebuilds the navigation and remounts the
		 * page the code was typed on, so the sentence is shown by the shell,
		 * at the top of the page, where it survives that.
		 *
		 * @return {Promise<void>} Resolves when the account is read again.
		 *
		 * @spec openspec/changes/invitation-code-from-a-letter/specs/portal-identity-space/spec.md
		 */
		async onCodeClaimed() {
			this.claimMessage = {
				role: 'status',
				text: this.t(codeOutcome({ ok: true }).text),
			}
			window.scrollTo?.({ top: 0 })
			await this.loadAccount()
		},

		/**
		 * sessionStorage for a kept invitation, or null where the browser
		 * refuses it.
		 *
		 * @return {Storage|null}
		 *
		 * @spec openspec/changes/invitation-secret-joins-the-signed-in-account/specs/portal-identity-space/spec.md
		 */
		claimStorage() {
			try {
				return window.sessionStorage
			} catch {
				return null
			}
		},

		/**
		 * A content read that a portal behind a sign-in refuses to a visitor
		 * without a session (401) or below its trust floor (403) answers the
		 * fallback, so the door (title, theme, sign-in routes) still renders.
		 * Any other failure stays a failure.
		 *
		 * @param {Promise<Array<object>>} read The content read.
		 * @param {Array<object>} fallback What a refused read answers.
		 * @return {Promise<Array<object>>} The read's answer, or the fallback.
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
			const [contributions, threads, news, contacts] = await Promise.all([
				this.api.getContributions(),
				this.api.fetchThreads(),
				this.api.fetchNewsFeed(),
				// Whom the resident may write to: the conversations page then
				// shows before the first message (mijn-messages-follow-the-boards).
				this.fetchMessageContacts(),
			])
			this.unreadOverride = null
			// A portal may leave the ask out (mijn-overview-follows-the-boards).
			this.contactPrompt =
				this.site?.contactPrompt?.show !== false
				&& (await contactPromptWanted(this.session))
			this.account = {
				loading: false,
				contributions,
				threads: threads || [],
				news: news || [],
				contacts,
			}
			this.followAccountRoute()
			this.recordRows = await loadPerRecordRows(
				contributions?.contributions,
				this.api,
				// The collection the menu's person block reads
				// (resident-menu-follows-the-boards).
				[this.site?.residentMenu?.person?.collection].filter(Boolean),
			)
			// The mandates the resident holds, so the acting-for bar can name
			// its party before Mijn zaken was opened (REQ-SMO-008). Only when
			// the portal lists cases: that answer carries the mandates.
			if (contributions?.cases?.enabled === true) {
				learnMandates(await this.api.fetchMyCases().catch(() => null))
			}
		},

		/**
		 * The contacts the resident may write to, or none when the portal
		 * offers no messaging or the read fails (mijn-messages-follow-the-boards).
		 *
		 * @return {Promise<Array<object>>}
		 *
		 * @spec openspec/changes/mijn-messages-follow-the-boards/specs/site-mijn-omgeving/spec.md#requirement-the-messages-item-opens-the-conversations-under-the-boards-title
		 */
		async fetchMessageContacts() {
			if (typeof this.api?.messaging !== 'function') {
				return []
			}
			try {
				const res = await this.api.messaging('GET', '/contacts')
				return res?.ok && Array.isArray(res.data?.contacts)
					? res.data.contacts
					: []
			} catch {
				return []
			}
		},

		/**
		 * Forget everything the signed-in shell loaded, and what this tab
		 * kept for the resident: whom they acted for, the record link and the
		 * task they were opening. On a shared device the next resident starts
		 * from none of it.
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
			this.recordRows = {}
			this.contactPrompt = false
			forgetActingFor()
			forgetClaimSecret(this.claimStorage())
			try {
				window.sessionStorage.removeItem(OPEN_STORAGE_KEY)
				window.sessionStorage.removeItem(TASK_STORAGE_KEY)
			} catch {
				// Without storage nothing was kept.
			}
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
			// A kept record link opens the page that shows its collection.
			const opened = openRecordEntry(this.nav)
			if (opened) {
				this.freshSignIn = false
				this.replaceRoute(routeForNav(opened))
				return
			}
			if (this.freshSignIn && this.route === '/') {
				this.freshSignIn = false
				this.replaceRoute(ACCOUNT_ROUTE)
			}
			const target = accountRedirect(
				this.nav,
				this.route,
				this.site?.residentMenu?.routes,
			)
			if (target) {
				this.replaceRoute(target)
			}
		},

		/**
		 * Go to a section by its key (`__account__`, `account`) or to an
		 * in-site route, the way pages and prompts ask for one.
		 *
		 * @param {string} target A navigation key, a section name or a route.
		 * @return {void}
		 *
		 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
		 */
		goSection(target) {
			const value = String(target || '')
			if (value.startsWith('/')) {
				this.go(value)
				return
			}
			const entry = this.nav.find(
				(candidate) =>
					candidate.key === value || candidate.special === value,
			)
			if (entry) {
				this.go(routeForNav(entry))
			}
		},

		/**
		 * Keep a record link from the address for after the sign-in.
		 *
		 * @return {void}
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-record-link-must-open-its-record-after-sign-in-req-srp-021
		 */
		keepOpenTarget() {
			let storage = null
			try {
				storage = window.sessionStorage
			} catch {
				// Without storage the link lives as long as this page view.
			}
			consumeOpenTarget(window.location, window.history, storage)
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
			const minted = await this.api.devLogin(
				this.signinConfig.audience || undefined,
			)
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
			let pageName = this.accountRoute
				? this.accountEntry?.label || this.t('My area')
				: this.page?.seo?.title || this.page?.title
			if (this.sharedDossierRoute) {
				pageName = this.sharedDossierTitle || this.t('Shared dossier')
			}
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
		 * Take the shared dossier's title for the tab and the breadcrumb.
		 *
		 * @param {string} title The dossier's title, or ''.
		 * @return {void}
		 *
		 * @spec openspec/changes/site-shared-dossier/specs/site-shared-dossier/spec.md#requirement-a-shared-dossier-link-must-open-a-public-page-req-ssd-001
		 */
		onSharedDossierLoaded(title) {
			this.sharedDossierTitle = title || ''
			this.applyDocumentTitle()
		},

		/**
		 * Load one page by route.
		 *
		 * @param {string} route The in-portal route.
		 * @param {{fresh?: boolean}} [options] `fresh` to read past the browser cache.
		 * @return {Promise<void>} Resolves when loaded.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-unpublished-content-must-be-indistinguishable-from-absent-content
		 * @spec openspec/changes/site-shows-what-was-published/specs/portal-in-place-editing/spec.md#requirement-the-site-must-show-what-an-editor-published-not-a-cached-copy-req-ssp-001
		 */
		async loadRoute(route, { fresh = false } = {}) {
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

			// A shared dossier link renders from opencatalogi's answer, not
			// from a CMS page, so it opens on every portal without one.
			if (isSharedDossierRoute(route)) {
				this.page = null
				this.error = null
				this.routeParam = ''
				this.sharedDossierTitle = ''
				this.loading = false
				this.applyDocumentTitle()
				return
			}

			this.loading = true
			this.error = null
			this.routeParam = ''
			try {
				this.page = await fetchPage(route, this.portalSlug, {
					fresh,
					locale: this.chosenLocale,
				})
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
						this.page = await fetchPage(parent, this.portalSlug, {
							fresh,
							locale: this.chosenLocale,
						})
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
				this.signInNeeded = Boolean(
					error && (error.status === 401 || error.status === 403),
				)
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
			// Fresh: the page may have been published a moment ago, and an
			// ordinary read answers from the browser cache for five minutes.
			await this.loadRoute(this.route, { fresh: true })
		},

		/**
		 * After the editor published, read the page on screen again past the
		 * browser cache, quietly: the editor stays open, and leaving it shows
		 * the published page, not the copy the cache still held.
		 *
		 * @return {Promise<void>} Resolves when the page is read.
		 *
		 * @spec openspec/changes/site-shows-what-was-published/specs/portal-in-place-editing/spec.md#requirement-the-site-must-show-what-an-editor-published-not-a-cached-copy-req-ssp-001
		 */
		async refreshShownPage() {
			if (this.routeParam !== '') {
				return
			}
			try {
				this.page = await fetchPage(this.route, this.portalSlug, {
					fresh: true,
					locale: this.chosenLocale,
				})
			} catch {
				// Leaving edit mode reads the page again anyway; a failed
				// refresh here must not disturb the editor.
			}
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
					onSaved: () => this.refreshShownPage(),
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
			// The chosen language stays as well (`?lang=`), so the next page
			// opens in the language the visitor picked.
			const lang = url.searchParams.get('lang')
			url.search = ''
			url.hash = ''
			if (portal) {
				url.searchParams.set('portal', portal)
			}
			if (lang) {
				url.searchParams.set('lang', lang)
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
			// The chosen language stays as well (`?lang=`), so the next page
			// opens in the language the visitor picked.
			const lang = url.searchParams.get('lang')
			url.search = ''
			url.hash = ''
			if (portal) {
				url.searchParams.set('portal', portal)
			}
			if (lang) {
				url.searchParams.set('lang', lang)
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
/*
 * THE SIDE MENU LAYOUT (site-navigation-block). The layout row is the
 * reading column the header and footer use, split into a menu column and
 * the content. Inside it, the content's own `.container`s would add a second
 * margin, so they stretch to the column instead.
 */
.pq-site__layout--side-menu {
	max-width: 1200px;
	margin-inline: auto;
	padding-inline: 16px;
	display: grid;
	grid-template-columns: minmax(14rem, 18rem) minmax(0, 1fr);
	gap: 2rem;
	align-items: start;
}

/*
 * The e-mail prompt above a page outside `/mijn`. Its container is a direct
 * child of the column-flex `.pq-site`, where the container's auto side margins
 * stop the stretch every other container gets inside `<main>`: it shrank to
 * its text and stood off-centre. Full width up to the container's own
 * maximum, and a step down from the navigation, as in the account column.
 */
.pq-site__contact-prompt {
	box-sizing: border-box;
	width: 100%;
	padding-block-start: var(--utrecht-space-block-md, 1rem);
}

.pq-site__layout--side-menu .container {
	max-width: none;
	margin-inline: 0;
	padding-inline: 0;
}

.pq-site__aside--menu {
	padding-block: 1.5rem;
}

@media (width < 768px) {
	.pq-site__layout--side-menu {
		grid-template-columns: minmax(0, 1fr);
		gap: 0;
	}

	.pq-site__aside--menu {
		padding-block: 1rem 0;
	}
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
	--pq-muted-color: var(
		--thematiq-website-text-muted,
		var(--nldesign-color-text-muted, #6b6b6b)
	);
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
