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
							v-if="site.logo"
							class="pq-site__logo-mark"
							:src="site.logo"
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
							{{ site.title || '…' }}
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
					v-if="singleBarHeader"
					class="ac-c-navigation__container pq-site__header-nav"
					data-testid="site-header-nav">
					<SiteMenu
						v-for="menu in headerMenus"
						:key="menu.title"
						:menu="menu"
						:currentRoute="route"
						@navigate="go" />
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
								@click="signOut">
								Uitloggen
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
						<nav v-else aria-label="Gebruikersmenu">
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

			<div v-if="!singleBarHeader" class="ac-header__navigation-secondary">
				<div class="container">
					<div class="ac-c-navigation__container">
						<SiteMenu
							v-for="menu in headerMenus"
							:key="menu.title"
							:menu="menu"
							:currentRoute="route"
							@navigate="go" />
					</div>
				</div>
			</div>

			<div class="ac-header__navigation-breadcrumb">
				<div class="container" />
			</div>
		</header>

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
				<p v-if="loading" class="container" data-testid="site-loading">
					Bezig met laden…
				</p>

				<!-- A failed load says so. Rendering an empty page instead would
			     make a broken deployment look exactly like an empty site — the
			     one confusion this surface can least afford. -->
				<div
					v-else-if="error"
					class="container"
					role="alert"
					data-testid="site-error">
					<h2>
						{{
							error.status === 404
								? 'Pagina niet gevonden'
								: 'Er ging iets mis'
						}}
					</h2>
					<p>
						{{
							error.status === 404
								? 'Deze pagina bestaat niet (meer).'
								: 'De inhoud kon niet worden geladen.'
						}}
					</p>
				</div>

				<!--
					A CONTRIBUTED PAGE, when the route names one. Checked before
					the CMS page because the two can never both be set: a
					`/diensten/…` route never reaches the content API.
				-->
				<ContributionPage
					v-else-if="contributionPage"
					:page="contributionPage.page"
					:contribution="contributionPage.contribution"
					:session="session"
					:apiBase="authBase" />

				<article
					v-else-if="page"
					class="utrecht-article"
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
					<!--
						AN `h1`, NOT AN `h2` — this is the page's own heading.

						It was an `h2` because the site name in the header was the
						`h1`, which put the portal's name above the page's subject
						in the outline of every page. With the header title
						demoted to a span, a page whose body declares no heading
						of its own had NO level-one heading at all: measured 0 on
						three of four pages, which is a worse outline than the two
						competing ones it replaced.
					-->
					<div v-if="!bodyProvidesHeading" class="container">
						<h1 class="utrecht-heading-1" data-testid="page-title">
							{{ page.title }}
						</h1>
					</div>

					<WidgetGrid
						v-if="page.body && page.body.type === 'grid'"
						:widgets="page.body.widgets || []"
						:glossary="glossary"
						:contributions="contributions"
						@navigate="go" />

					<div v-else class="container">
						<MarkdownBlock
							data-testid="page-markdown"
							:source="(page.body && page.body.markdown) || ''" />
					</div>
				</article>

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

		<!--
			TWO SECTIONS, AND THE COUNT IS LOAD-BEARING.

			`nlds-app.css` styles this footer by POSITION, not by class:

			  .ac-footer section:first-of-type              { 96px band, blue-600 }
			  .ac-footer section:first-of-type .container   { display: grid, 4 cols }
			  .ac-footer section:last-of-type:not(:only-of-type)
			                                               { 28px band, blue-500 }

			`.ac-footer__sub-footer` appears in NO rule. This markup used to be a
			single `<section class="ac-footer__sub-footer">`, which looked right
			and rendered wrong: being the only section it was `:only-of-type`, so
			it picked up the FIRST band's 96px padding and dark blue, and the
			`:not(:only-of-type)` guard deliberately excluded it from the strip
			rule it was named after. Measured against the reference: 211px against
			368px, one band where there are two.

			So the sub-footer strip exists only when a second section does. Both
			are emitted unconditionally.
		-->
		<footer class="ac-footer pq-site__footer" data-testid="site-footer">
			<!-- The reference labels its footer for assistive tech and hides the
			     heading visually; a landmark with no name is announced as just
			     "footer". -->
			<h2 class="sr-only">Footer</h2>

			<!--
				THE DECORATION IS OPT-IN, BY NAME.

				A canal scene is Conduction's own illustration. A municipality
				running this same renderer must not inherit it because it shares
				a footer component, so the portal names the decoration it wants
				and the default is none.
			-->
			<FooterCanal v-if="footerContent.decoration === 'canal'" />

			<section>
				<div class="container ac-footer__container">
					<!--
						THE BRAND COLUMN COMES FIRST.

						It used to be last, after the link menus, which is the one
						order the reference does not use: measured on
						docs.conduction.nl the grid is brand-then-columns, at
						297px against three of 198px. A footer that opens with
						"Documentation" and names its owner in the far right
						column reads as three menus and an afterthought.
					-->
					<div class="ac-footer__logo pq-footer__brand">
						<div class="con-logo-container footer" />
						<span class="pq-footer__wordmark">{{ site.title }}</span>

						<!-- The line under the wordmark. Portal CONTENT, from the
						     portal record — a renderer cannot know what a portal
						     is for. -->
						<p
							v-if="footerContent.description"
							class="pq-footer__brand-text"
							data-testid="site-footer-description">
							{{ footerContent.description }}
						</p>

						<!-- The tagline predates the brand line and is kept: a
						     portal that set one must not lose it. -->
						<p
							v-else-if="site.tagline"
							class="pq-footer__brand-text"
							data-testid="site-footer-tagline">
							{{ site.tagline }}
						</p>

						<!--
							SOCIALS CARRY A VISIBLE-TO-SCREEN-READER NAME, ALWAYS.

							The glyph is decorative and the anchor has no text of
							its own, so without the `sr-only` label these are five
							links announced as "link". The reference uses brand
							glyphs we do not ship; `external-link` is the honest
							stand-in rather than an invented logo.
						-->
						<ul
							v-if="footerContent.socials.length"
							class="pq-footer__socials"
							data-testid="site-footer-socials">
							<li
								v-for="social in footerContent.socials"
								:key="social.href">
								<a
									:href="social.href"
									:aria-label="social.label"
									target="_blank"
									rel="noopener noreferrer">
									<CnSiteIcon
										:name="social.icon || 'external-link'"
										:size="18" />
									<span class="sr-only">{{ social.label }}</span>
								</a>
							</li>
						</ul>
					</div>

					<nav
						v-for="menu in footerMenus"
						:key="menu.title"
						class="ac-footer__links"
						:aria-label="menu.title"
						data-testid="site-footer-menu">
						<h3 class="ac-footer__menu-title">{{ menu.title }}</h3>
						<ul>
							<li v-for="item in menu.items" :key="item.name">
								<!--
									The reference marks every footer link with an
									external-link glyph. It is DECORATIVE here —
									`aria-hidden` — because the link already has
									its own text; announcing "external link"
									twice per item helps nobody.
								-->
								<a
									class="ac-footer__link"
									:href="item.link"
									:target="
										isExternal(item.link) ? '_blank' : undefined
									"
									:rel="
										isExternal(item.link)
											? 'noopener noreferrer'
											: undefined
									"
									@click="onFooterLink($event, item.link)">
									<CnSiteIcon
										v-if="isExternal(item.link)"
										name="external-link"
										:size="18" />
									<span>{{ item.name }}</span>
								</a>
							</li>
						</ul>
					</nav>
				</div>
			</section>

			<section class="ac-footer__sub-footer">
				<div class="container pq-footer__legal">
					<!--
						THE LEGAL BAR HAS TWO SIDES, and the left one is not a menu.

						This used to be a horizontal nav of legal links and nothing
						else. The reference puts the COLOPHON first — the legal
						entity, its chamber-of-commerce number and its VAT number —
						then the legal links inline after it, and certification
						badges on the right. The links alone name who to complain
						to but not who is responsible.
					-->
					<div class="pq-footer__legal-left">
						<!--
							THE PORTAL TITLE IS THE FALLBACK COLOPHON, and removing
							it was a regression: a portal that has never set a
							`footer` block — which is every portal that existed
							before this field — showed its name here, and for one
							build showed nothing at all. An empty legal bar on a
							government site names nobody as responsible.
						-->
						<span
							v-if="footerContent.colophon || site.title"
							class="pq-footer__colophon"
							data-testid="site-footer-colophon">
							{{ footerContent.colophon || site.title }}
						</span>

						<!--
							The separator is a decorative character between links,
							so it is `aria-hidden`: read aloud, a list of four
							links becomes "Privacy middle dot Terms middle dot".
						-->
						<span
							v-if="legalLinks.length"
							class="pq-footer__legal-links"
							data-testid="site-footer-legal-links">
							<template
								v-for="(item, i) in legalLinks"
								:key="item.href">
								<span v-if="i > 0" aria-hidden="true">·</span>
								<a
									:href="item.href"
									@click.prevent="go(item.href)"
									>{{ item.label }}</a
								>
							</template>
						</span>
					</div>

					<!--
						BADGES ARE ONLY LINKS WHEN THEY LEAD SOMEWHERE. A
						certification badge with no certificate behind it is a
						claim; rendering it as an inert `span` rather than an
						anchor at least does not promise evidence that is missing.
					-->
					<ul
						v-if="footerContent.badges.length"
						class="pq-footer__badges"
						data-testid="site-footer-badges">
						<li
							v-for="badge in footerContent.badges"
							:key="badge.mark + badge.value">
							<component
								:is="badge.href ? 'a' : 'span'"
								class="pq-footer__badge"
								:href="badge.href || null"
								:target="badge.href ? '_blank' : null"
								:rel="badge.href ? 'noopener noreferrer' : null">
								<span class="pq-footer__badge-mark">{{
									badge.mark
								}}</span>
								<span class="pq-footer__badge-value">{{
									badge.value
								}}</span>
							</component>
						</li>
					</ul>
				</div>
			</section>
		</footer>
	</div>
</template>

<script>
import { CnSiteIcon } from '@conduction/nextcloud-vue/public'
import ContributionPage from './components/ContributionPage.vue'
import FooterCanal from './components/FooterCanal.vue'
import MarkdownBlock from './components/MarkdownBlock.vue'
import SiteMenu from './components/SiteMenu.vue'
import WidgetGrid from './components/WidgetGrid.vue'
import {
	adoptSessionToken,
	authBaseFrom,
	clearSessionToken,
	fetchSession,
	signInRoutes,
} from './lib/authApi.js'
import {
	fetchContributions,
	fetchGlossary,
	fetchMenus,
	fetchPage,
	fetchSite,
	resolveApiBase,
} from './lib/contentApi.js'
import { parseContributionRoute } from './lib/contributionApi.js'
import { trafficClientFor } from './lib/traffic.js'

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
		CnSiteIcon,
		ContributionPage,
		FooterCanal,
		MarkdownBlock,
		SiteMenu,
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
			site: {},
			menus: [],
			glossary: [],
			contributions: [],
			/** The resolved contributed page, when the route names one. */
			contributionPage: null,
			session: null,
			page: null,
			route: '/',
			loading: true,
			error: null,
			/**
			 * The traffic client, or null until the portal's config arrives.
			 *
			 * NOT REACTIVE STATE IN ANY MEANINGFUL SENSE — nothing renders from
			 * it. It sits here so `beforeUnmount` can flush what is queued.
			 */
			traffic: null,
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
		bodyProvidesHeading() {
			const body = this.page.body || {}
			if (body.type !== 'grid') {
				return false
			}

			return (body.widgets || []).some((w) => w.widgetKey === 'hero')
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
			return this.menus.filter((menu) => (menu.position || 0) === 0)
		},

		/**
		 * Whether the header is one bar or two.
		 *
		 * `double` — a title bar above a separate navigation bar — is the
		 * government pattern this renderer was built against and stays the
		 * default, so no existing portal changes shape. `single` puts the logo,
		 * the navigation and the sign-in controls on one line, which is what
		 * documentation and product sites do.
		 *
		 * The portal decides, not the theme: two portals can share a palette
		 * and disagree about their chrome, and a renderer that inferred
		 * structure from colour would be unpredictable to whoever picks the
		 * colour.
		 *
		 * @return {boolean} True for the one-bar header.
		 *
		 * @spec openspec/changes/portal-page-composition/specs/portal-page-composition/spec.md#requirement-every-region-of-a-portal-page-must-be-composed-from-widgets
		 */
		singleBarHeader() {
			return this.site?.headerVariant === 'single'
		},

		/**
		 * The menus shown as columns in the footer's first band.
		 *
		 * The counterpart of `headerMenus`: every menu the header does not
		 * claim. Before this split, EVERY menu rendered in the header bar and
		 * the footer had no links at all — a portal could not express a footer
		 * column even though its data model already had the field to do it.
		 *
		 * The band is a four-column grid, so a portal declaring more than three
		 * footer menus wraps rather than overflowing; the logo occupies the
		 * fourth cell.
		 *
		 * @return {Array} The footer menus.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-content-api-must-be-sufficient-without-the-built-in-renderer
		 */
		footerMenus() {
			return this.menus.filter(
				(menu) => (menu.position || 0) !== 0 && menu !== this.subFooterMenu,
			)
		},

		/**
		 * The legal strip at the very bottom, if the portal declares one.
		 *
		 * CONVENTION, read off the existing `position` field rather than added
		 * to the schema: the HIGHEST position is the sub-footer. The reference
		 * puts Privacy / Algemene voorwaarden / Disclaimer / FAQ there, visually
		 * separate from the link columns above, and a portal needs some way to
		 * say which menu that is.
		 *
		 * Requires at least two footer menus, so a portal with a single footer
		 * menu keeps it as a COLUMN rather than having it silently demoted to
		 * the strip — one menu is far more likely to be links than legalese.
		 *
		 * @return {object|null} The sub-footer menu, or null.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-content-api-must-be-sufficient-without-the-built-in-renderer
		 */
		subFooterMenu() {
			const footers = this.menus.filter((menu) => (menu.position || 0) !== 0)
			if (footers.length < 2) {
				return null
			}

			return footers.reduce((highest, menu) =>
				(menu.position || 0) > (highest.position || 0) ? menu : highest,
			)
		},

		/**
		 * The portal's initial, for the fallback logo mark.
		 *
		 * One character, uppercased, and empty when there is no title yet — a
		 * mark reading "…" while the contract loads is worse than an empty one,
		 * because it settles into a letter a moment later.
		 *
		 * @return {string} A single character, or ''.
		 */
		logoInitial() {
			const title = this.site && this.site.title ? String(this.site.title) : ''
			return title.trim().slice(0, 1).toUpperCase()
		},

		/**
		 * Where a visitor without an account registers, if the portal says.
		 *
		 * DECLARED, never derived. There is no way to infer a registration
		 * destination from the sign-in modes — `nextcloud` accounts are created
		 * by an administrator, DigiD accounts by the state — so a portal that
		 * has not named one offers no register control at all.
		 *
		 * @return {object|null} `{label, href}` or null.
		 */
		registerRoute() {
			const auth = (this.site && this.site.authentication) || {}
			const href = String(auth.register || '').trim()
			if (href === '') {
				return null
			}

			return { href, label: String(auth.registerLabel || 'Registreren') }
		},

		/**
		 * The footer's authored content, always the same SHAPE.
		 *
		 * The contract's `footer` object is optional and every list inside it
		 * is optional, so the template would otherwise guard each one
		 * separately — and the first guard anybody forgets is the one that
		 * throws on a portal that has never set a footer.
		 *
		 * @return {object} `{description, colophon, socials, badges}`.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-content-api-must-be-sufficient-without-the-built-in-renderer
		 */
		footerContent() {
			const footer = (this.site && this.site.footer) || {}
			const list = (value) => (Array.isArray(value) ? value : [])

			return {
				description: String(footer.description || ''),
				colophon: String(footer.colophon || ''),
				decoration: String(footer.decoration || ''),
				socials: list(footer.socials),
				badges: list(footer.badges),
			}
		},

		/**
		 * The legal links, from the portal's footer or from its last menu.
		 *
		 * PREFERS THE PORTAL RECORD but falls back to the sub-footer menu,
		 * because that menu is where every portal's legal links live today. A
		 * new field that silently empties an existing footer is a regression
		 * dressed as a feature.
		 *
		 * @return {Array} `{label, href}` entries.
		 */
		legalLinks() {
			const authored =
				(this.site && this.site.footer && this.site.footer.legalLinks) || []
			if (Array.isArray(authored) && authored.length) {
				return authored.map((item) => ({
					label: String(item.label || ''),
					href: String(item.href || ''),
				}))
			}

			const menu = this.subFooterMenu
			if (!menu) {
				return []
			}

			return (menu.items || []).map((item) => ({
				label: String(item.name || ''),
				href: String(item.link || ''),
			}))
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
		/**
		 * The portal auth/API base.
		 *
		 * Derived once rather than at each call site — three methods already
		 * re-derived it, and a contributed action posting to a different base
		 * than the session was minted against is a 401 nobody would look for in
		 * this file.
		 *
		 * @return {string} The base.
		 */
		authBase() {
			return authBaseFrom(resolveApiBase())
		},

		signInRoutes() {
			return signInRoutes(this.site, this.authBase)
		},

		/**
		 * @return {string} How to name the signed-in visitor.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-portal-must-offer-only-the-sign-in-routes-it-declares
		 */
		sessionLabel() {
			return (
				this.session?.name
				|| this.session?.subject
				|| this.session?.sub
				|| 'Ingelogd'
			)
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
		this.route = this.routeFromLocation()
		window.addEventListener('popstate', this.onPopState)
		await this.loadSite()

		// BUILT AFTER THE SITE LOADS, because the portal's own configuration is
		// what decides whether it exists at all. A client constructed before
		// the config arrives would have to default to something, and the only
		// safe default — measure nothing — would then need un-defaulting,
		// which is a race nobody wins.
		this.traffic = trafficClientFor({
			config: this.site.traffic,
			apiBase: resolveApiBase(),
			portal: this.portalSlug,
		})

		// The queue must survive the page going away: the last page of a visit
		// is the one that tells you where visitors leave, and it is exactly the
		// one still in the queue when they do.
		window.addEventListener('pagehide', this.flushTraffic)
		document.addEventListener('visibilitychange', this.onVisibilityChange)

		await this.loadRoute(this.route)
	},

	beforeUnmount() {
		window.removeEventListener('popstate', this.onPopState)
		window.removeEventListener('pagehide', this.flushTraffic)
		document.removeEventListener('visibilitychange', this.onVisibilityChange)
		this.flushTraffic()
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
		 * Send whatever is queued.
		 *
		 * @return {void}
		 */
		flushTraffic() {
			if (this.traffic) {
				this.traffic.flush()
			}
		},

		/**
		 * Flush when the page is hidden.
		 *
		 * `visibilitychange` fires where `pagehide` does not — a tab switch on
		 * mobile that never comes back is the common way a visit ends, and it
		 * produces no unload at all.
		 *
		 * @return {void}
		 */
		onVisibilityChange() {
			if (document.visibilityState === 'hidden') {
				this.flushTraffic()
			}
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
					fetchMenus(this.portalSlug),
					fetchGlossary(this.portalSlug),
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

			this.applyDocumentTitle()
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

			const pageName = this.page?.title
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
			try {
				await fetch(`${authBaseFrom(resolveApiBase())}/session`, {
					method: 'DELETE',
					credentials: 'include',
					// The edge revokes the session the BEARER names. Without it
					// the request is anonymous, the server revokes nothing, and
					// only this tab forgets — a sign-out that leaves a live
					// token behind is the one failure mode that matters here.
					headers: token ? { Authorization: `Bearer ${token}` } : {},
				})
			} catch {
				// Reported by the state change below, not by an alert.
			}

			clearSessionToken()
			this.session = null
		},

		/**
		 * Load one page by route.
		 *
		 * @param {string} route The in-portal route.
		 * @return {Promise<void>} Resolves when loaded.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-unpublished-content-must-be-indistinguishable-from-absent-content
		 */
		/**
		 * Find a contributed page in the contributions already loaded.
		 *
		 * Returns the page AND its contribution, because rendering an action needs
		 * the sibling `actions` list — a block names an action by id and nothing
		 * else.
		 *
		 * @param {object} ref The `{appId, pageId}` from the route.
		 * @return {object|null} `{page, contribution}` or null.
		 */
		resolveContribution(ref) {
			const contribution = (this.contributions || []).find(
				(c) => c && c.app === ref.appId,
			)
			if (!contribution) {
				return null
			}

			const page = (contribution.pages || []).find(
				(pg) => pg && pg.id === ref.pageId,
			)
			if (!page) {
				return null
			}

			return { page, contribution }
		},

		async loadRoute(route) {
			this.loading = true
			this.error = null

			// A CONTRIBUTED PAGE IS RESOLVED FROM DATA ALREADY LOADED, not from
			// a second request: the contributions contract carries every page a
			// leaf app publishes, so asking the CMS for `/diensten/…` would be a
			// guaranteed 404 followed by the lookup that should have come first.
			const contributed = parseContributionRoute(route)
			if (contributed !== null) {
				this.contributionPage = this.resolveContribution(contributed)
				this.page = null
				this.error = this.contributionPage === null ? { status: 404 } : null
				this.loading = false
				this.recordPageView(this.contributionPage === null)
				return
			}

			this.contributionPage = null
			try {
				this.page = await fetchPage(route, this.portalSlug)
			} catch (error) {
				this.page = null
				// A 404 is information, not a fault — an unknown route and an
				// unpublished page are answered identically by the API on
				// purpose, and both belong on screen as "not found".
				this.error = error
			} finally {
				this.loading = false
				this.recordPageView(this.page === null)
			}
		},

		/**
		 * Report that a page was shown.
		 *
		 * A MISSING PAGE IS STILL A PAGE VIEW, and it is flagged rather than
		 * dropped: the routes visitors reach and find nothing at are the ones
		 * an editor most needs to see, and silently not counting them makes a
		 * broken link look like a link nobody follows.
		 *
		 * Deferred to the next tick so the document title the renderer sets for
		 * this route is the one reported, rather than the previous route's.
		 *
		 * @param {boolean} notFound Whether the route resolved to nothing.
		 * @return {void}
		 */
		recordPageView(notFound = false) {
			if (this.traffic === null) {
				return
			}

			this.$nextTick(() => {
				this.traffic.pageView(notFound === true ? { notFound: '1' } : {})
			})
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
		 * Whether a link leaves this portal.
		 *
		 * An absolute URL to another origin is external; everything else is an
		 * in-site route this renderer handles itself. The distinction decides
		 * both the icon and whether the click is intercepted — calling
		 * `preventDefault` on an outbound link would strand the visitor on a
		 * dead control.
		 *
		 * @param {string} link The href.
		 * @return {boolean} True when it points off-site.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-content-api-must-be-sufficient-without-the-built-in-renderer
		 */
		isExternal(link) {
			return /^https?:\/\//i.test(String(link || ''))
		},

		/**
		 * Follow a footer link, in-site or out.
		 *
		 * @param {MouseEvent} event The click.
		 * @param {string} link The href.
		 * @return {void}
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-the-content-api-must-be-sufficient-without-the-built-in-renderer
		 */
		onFooterLink(event, link) {
			if (this.isExternal(link)) {
				return
			}

			event.preventDefault()
			this.go(link)
		},

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
</style>
