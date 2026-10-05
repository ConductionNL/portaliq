<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<!--
		BANDS ARE NOT LAID OUT IN THE GRID, and that is the whole reason this
		template has two arms.

		A band paints edge to edge and brings its own `.container`. Putting one
		in a grid cell inside the page's content column clamps it: measured
		against the reference, a hero rendered that way came out 1168px wide
		against the design's 1280, and nothing inside the hero could recover the
		width because the limit was an ancestor.

		The reference's own structure is the model — `main` is full-bleed, every
		`section` carries a container — so bands are emitted as direct children
		here and only the remaining widgets get grid geometry, inside a
		container of their own.
	-->
	<div data-testid="widget-grid">
		<template v-for="(run, runIndex) in runs" :key="`run-${runIndex}`">
			<!-- A BAND: full bleed, brings its own container. -->
			<component
				:is="componentFor(run.widget.widgetKey)"
				v-if="run.band && componentFor(run.widget.widgetKey)"
				:data-testid="`widget-${run.widget.id || run.widget.widgetKey}`"
				:data-widget-key="run.widget.widgetKey"
				v-bind="propsFor(run.widget)"
				@navigate="$emit('navigate', $event)"
				@search="$emit('search', $event)" />

			<!-- A RUN of ordinary widgets: one grid, inside one container. The
			     container is here rather than around the whole component so a
			     band between two runs still reaches the viewport edge. -->
			<div v-else-if="!run.band" class="container">
				<div class="pq-grid">
					<div
						v-for="widget in run.widgets"
						:key="widget.id || `${widget.gridX}-${widget.gridY}`"
						class="pq-grid__cell"
						:data-testid="`widget-${widget.id || widget.widgetKey}`"
						:data-widget-key="widget.widgetKey"
						:style="cellStyle(widget, run.rowOffset)">
						<component
							:is="componentFor(widget.widgetKey)"
							v-if="componentFor(widget.widgetKey)"
							v-bind="propsFor(widget)"
							@navigate="$emit('navigate', $event)"
							@search="$emit('search', $event)" />

						<!-- Anything not public, or not known, degrades to an inert
						     placeholder. It does NOT throw: a public page with one bad
						     widget must still show its other three, and a page that blanks
						     is a worse failure than a visibly missing tile. -->
						<p
							v-else
							class="pq-grid__placeholder"
							data-testid="widget-placeholder">
							{{ placeholderText(widget.widgetKey) }}
						</p>
					</div>
				</div>
			</div>
		</template>
	</div>
</template>

<script>
import { siteBlockIsBand, siteBlockRegistry } from '@conduction/nextcloud-vue/public'
import { defineAsyncComponent } from 'vue'
import HeroBlock from './HeroBlock.vue'
import MarkdownBlock from './MarkdownBlock.vue'
import { withoutStyling } from '../lib/blockProps.js'
import { cellStyle, runsFor } from '../lib/gridPlacement.js'
import { loaders as siteWidgetLoaders } from '../widgets/loaders.js'

/**
 * LOADED ON DEMAND, and that is a budget decision rather than a style one.
 *
 * `webpack.site.js` sets `performance.hints: 'error'` at 400 KiB because this
 * bundle is downloaded by a first-time visitor on a phone before anything
 * renders. Bundling the detail block eagerly pushed the entrypoint to 445 KiB
 * and FAILED THE BUILD — which is the budget doing its job.
 *
 * The block is reachable from exactly one route (`/publicatie/<id>`), so every
 * visitor who only searches was paying for a page they never opened.
 */
const PublicationDetailBlock = defineAsyncComponent(
	() => import('./PublicationDetailBlock.vue'),
)

/**
 * Loaded on demand for the same reason, and it saves more.
 *
 * The search block is reachable from `/zoeken`, not from the landing page —
 * where the hero's box only collects a term and navigates. So the visitor who
 * arrives at `/` was downloading the entire search implementation, its
 * pagination, its facet handling and its date formatting before seeing a
 * heading.
 */
const FederatedSearchBlock = defineAsyncComponent(
	() => import('./FederatedSearchBlock.vue'),
)

/**
 * Loaded on demand as well. A landing page's form is reachable only from the
 * page that carries it, and the block brings its submission client along;
 * the visitor who reads any other page paid for both, and the public
 * first-load budget (S18, 400 KiB) is what noticed.
 */
const FormBlock = defineAsyncComponent(() => import('./FormBlock.vue'))

/**
 * On demand too: the contributions list is one block on one kind of page,
 * and the same first-load budget applies.
 */
const ContributionsBlock = defineAsyncComponent(
	() => import('./ContributionsBlock.vue'),
)

/**
 * On demand as well: the three intake blocks live on the request pages only
 * (portal-intake-form-as-an-object, T09), and the first-load budget applies.
 */
const IntakeCatalogueBlock = defineAsyncComponent(
	() => import('./IntakeCatalogueBlock.vue'),
)
const IntakeFormBlock = defineAsyncComponent(() => import('./IntakeFormBlock.vue'))
const IntakeStatusBlock = defineAsyncComponent(
	() => import('./IntakeStatusBlock.vue'),
)

/**
 * The widget keys this renderer will mount at a PUBLIC origin, mapped to the
 * component that renders each.
 *
 * ADR-084 §5: public exposure is opt-in and default-closed. It is an
 * allow-list, never a deny-list: a widget added to the shared catalog must not
 * become anonymously mountable simply by existing.
 *
 * THE MAP IS THE GATE. An earlier version of this component kept a separate
 * `Set` of allowed keys and then rendered on a hard-coded
 * `widgetKey === 'markdown'` check. The allow-list therefore decided nothing:
 * a mutation test that added `files` to it still passed, because the second
 * condition kept the placeholder in place. The gate looked like it was there
 * and was not. Keeping the decision and the rendering in ONE structure is what
 * makes it real — and what makes a mutation of it observable.
 *
 * CHAIN LINK 2 HAS LANDED. This file used to say the shared registry carried no
 * `public` flag, so a one-key map stood in for it. `@conduction/nextcloud-vue/public`
 * is that flag, expressed as a separate ENTRY POINT rather than a boolean:
 * everything reachable from it is verified — by a transitive import walk in the
 * library's own CI — to pull no `@nextcloud/*` runtime, which is precisely what
 * "safe to mount at a public origin" means here.
 *
 * That distinction is not academic. A DIRECT import check on the library's
 * existing widgets said 12 of 13 were clean; following relative imports through
 * the tree showed 12 of 13 reach `@nextcloud/l10n`, and one also reaches
 * `@nextcloud/vue`, `@nextcloud/auth` and `@nextcloud/event-bus`. A boolean
 * flag on the shared catalog would have been set from the first check.
 *
 * `markdown` stays owned here: it renders untrusted authored content and its
 * sanitisation posture is this app's decision, not a library default.
 *
 * `contributions` stays owned here for the same class of reason: a
 * contribution is portaliq's own contract (ADR-046), described by this app's
 * provider protocol rather than by the design system.
 *
 * `form` stays owned here too (landing-page-provisioning): it submits
 * through Portaliq's own anonymous contribution-create endpoint and is
 * described by this app's `form` schema, not the design system.
 */
/**
 * On demand as well: the menu block is placed on the pages of portals that
 * want a side menu (site-navigation-block), and the first-load budget applies.
 */
const SiteNavigationBlock = defineAsyncComponent(
	() => import('./SiteNavigationBlock.vue'),
)

/**
 * THE NL DESIGN SYSTEM WIDGETS, one chunk each
 * (site-nlds-widget-palette REQ-SNW-011).
 *
 * Spread below under their own `nl`-prefixed keys, so the gate stays one
 * structure: a widget renders publicly if and only if it is in this map. The
 * loaders come from `src/site/widgets/index.js`, which is the only place a
 * widget is registered, and each import keeps its component and its CSS
 * package out of the site entry.
 *
 * @type {Record<string, object>}
 */
const nldsWidgets = Object.fromEntries(
	Object.entries(siteWidgetLoaders).map(([key, loader]) => [
		key,
		defineAsyncComponent(loader),
	]),
)

const PUBLIC_WIDGETS = {
	markdown: MarkdownBlock,
	// site-navigation-block: the portal's own navigation, which the shell
	// derives from the public menus and the signed-in navigation and hands
	// over as `navigation`; the block fetches nothing.
	siteNavigation: SiteNavigationBlock,
	contributions: ContributionsBlock,
	// `federatedSearch` stays owned here rather than coming from the shared
	// registry, because what it is allowed to query is this app's decision.
	// It reads OpenCatalogi's `@PublicPage` federation endpoint and applies no
	// visibility rule of its own — the schema's authorization block, evaluated
	// by OpenRegister inside OpenCatalogi, is the only one. A block in the
	// shared catalog pointed at an arbitrary endpoint would make that
	// guarantee a property of page configuration instead of code.
	federatedSearch: FederatedSearchBlock,
	// Owned here for the same reason: it reads OpenCatalogi's public endpoint
	// and applies no visibility rule of its own.
	publicationDetail: PublicationDetailBlock,
	// landing-page-provisioning: submits through this app's OWN anonymous
	// contribution-create endpoint; see propsFor() for the portal it is
	// handed and buildBody() (LandingPageProvisioningService) for why its
	// fields/submitLabel/consentText arrive as authored props rather than a
	// second fetch.
	form: FormBlock,
	// portal-intake-form-as-an-object: the citizen's entry point. They call
	// this app's own intake endpoints, which apply the binding's sign-in level
	// and validate before anything is recorded; nothing here decides access.
	intakeCatalogue: IntakeCatalogueBlock,
	intakeForm: IntakeFormBlock,
	intakeStatus: IntakeStatusBlock,
	...nldsWidgets,
	...siteBlockRegistry,
	// After the spread, under the library's own key: `siteBlockIsBand('hero')`
	// still answers true, and the band keeps CnSiteHero's props while gaining
	// an eyebrow and capped calls to action (REQ-PTB-006, eed4c3b).
	hero: HeroBlock,
}

/**
 * The public vocabulary, as data.
 *
 * ONE STRUCTURE, TWO READERS. The map above is the gate — a widget renders at
 * a public origin if and only if it is in it — and the page designer has to
 * offer exactly that set as "safe to place here". Copying the key list into the
 * designer would create a second grammar that drifts from this one silently:
 * a block added here would go on rendering publicly while the designer went on
 * calling it unavailable, and neither side would be wrong on its own terms.
 *
 * So the designer reads THIS, derived from the same object the renderer
 * resolves against.
 *
 * @return {Array<string>} The widget keys that render at a public origin.
 */
export function publicWidgetKeys() {
	return Object.keys(PUBLIC_WIDGETS)
}

/**
 * The component a public origin will mount for a widget key.
 *
 * @param {string} key The registry key.
 * @return {object|null} The component, or null when the key is not public.
 */
export function publicWidgetFor(key) {
	return Object.hasOwn(PUBLIC_WIDGETS, key) ? PUBLIC_WIDGETS[key] : null
}

export default {
	name: 'WidgetGrid',

	components: {
		ContributionsBlock,
		FormBlock,
		MarkdownBlock,
	},

	props: {
		/** Widget placements in the canonical manifest-v2 widgetEntry shape. */
		widgets: {
			type: Array,
			default: () => [],
		},

		/**
		 * The serving portal's slug, for a page that places a `form` block —
		 * scopes its UTM capture to this portal. Supplied by the host for the
		 * same reason `routeParam` is; see `propsFor()`.
		 */
		portal: {
			type: String,
			default: '',
		},

		/**
		 * The portal's glossary rows, for a page that places a `glossary`
		 * block. Fetched once by the host over the public contract; see
		 * `propsFor()` for why the block does not fetch its own.
		 */
		glossary: {
			type: Array,
			default: () => [],
		},

		/**
		 * The portal's contributed surfaces, for a page that places a
		 * `contributions` block. Supplied by the host for the same reason the
		 * glossary rows are; see `propsFor()`.
		 */
		contributions: {
			type: Array,
			default: () => [],
		},

		/**
		 * The trailing route segment when the route resolved to this page's
		 * PARENT — the publication id in `/publicatie/<id>`.
		 *
		 * Supplied by the host for the same reason the glossary rows are: a
		 * block that read `window.location` itself would work only at the one
		 * route an author happened to place it on.
		 */
		routeParam: {
			type: String,
			default: '',
		},

		/**
		 * Whether the shell holds a portal session. Handed to the search and
		 * publication blocks AFTER their authored props, so a page cannot
		 * switch the save actions on (woo-journey-entry-points D1).
		 */
		signedIn: {
			type: Boolean,
			default: false,
		},

		/**
		 * The menu block's data: `{groups, currentRoute, label, toggleLabel}`,
		 * derived by the host from the menus and the signed-in navigation
		 * (src/site/lib/siteNavigation.js). Supplied by the host for the same
		 * reason the glossary rows are.
		 */
		navigation: {
			type: Object,
			default: () => ({}),
		},

		/**
		 * The language switch's data: `{locales, current}`, where `locales`
		 * are the portal's own, as `{locale, label, href}` entries
		 * (src/site/lib/languageNav.js). Supplied by the host for the same
		 * reason the glossary rows are: a placement must not offer a
		 * language the portal does not have.
		 */
		languages: {
			type: Object,
			default: () => ({}),
		},

		/**
		 * The portal's sign-in routes, `{mode, label, href}` as the shell
		 * derives them from `authentication.modes` (authApi.js
		 * signInRoutes). Handed to `nlSignIn` AFTER its authored props, so a
		 * page words the card but cannot add or change a way in
		 * (site-school-blocks).
		 */
		signInRoutes: {
			type: Array,
			default: () => [],
		},
	},

	// `search` comes from the shared hero block, which renders a search box and
	// emits the term. Without this forward the box is INERT — it submits, the
	// event reaches this component, and nothing above ever hears it. Which route
	// a search goes to is the host's decision, not a block's.
	emits: ['navigate', 'search'],

	computed: {
		/**
		 * The widget list split into alternating BANDS and RUNS.
		 *
		 * A band is emitted on its own so it can paint edge to edge; the
		 * widgets between bands are grouped into one grid inside one
		 * `.container`. Grouping matters: wrapping each cell in its own
		 * container would put every widget in a separate grid and destroy the
		 * 12-column placement they were authored against.
		 *
		 * Order is preserved exactly as authored — a band does not float to the
		 * top, it splits the page where the author put it.
		 *
		 * Each run carries its `rowOffset`, so a run below a band does not
		 * reserve rows for it (REQ-PTB-006).
		 *
		 * @return {Array} Alternating `{band: true, widget}` / `{band: false, widgets, rowOffset}` entries.
		 *
		 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-hero-must-cap-its-calls-to-action-and-keep-one-outline-entry-req-ptb-006
		 */
		runs() {
			return runsFor(this.widgets, (key) => this.isBand(key))
		},
	},

	methods: {
		/**
		 * Whether a block is a full-bleed band that owns its own container.
		 *
		 * Answered by the LIBRARY rather than by a list here, so a block added
		 * upstream arrives with its layout contract instead of needing this app
		 * to learn about it separately.
		 *
		 * @param {string} key The registry key.
		 * @return {boolean} True when it must not be wrapped in a grid cell.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-a-page-body-must-be-either-a-widget-grid-or-markdown
		 */
		isBand(key) {
			return siteBlockIsBand(key)
		},

		/**
		 * The component that renders a widget key at a public origin.
		 *
		 * @param {string} key The registry key.
		 * @return {object|null} The component, or null when the key is not public.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-only-explicitly-public-widgets-must-render-at-a-public-origin
		 */
		componentFor(key) {
			return publicWidgetFor(key)
		},

		/**
		 * The props handed to a mounted widget.
		 *
		 * Only the placement's declared `props` are passed through, never the
		 * placement itself: a widget has no business reading its own grid
		 * coordinates, and forwarding the whole entry would hand it whatever
		 * else an author happened to put there.
		 *
		 * @param {object} widget The placement.
		 * @return {object} The component props.
		 *
		 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-blocks-must-take-their-data-as-props-and-nothing-else-req-ptb-007
		 * @spec openspec/changes/language-switch-reaches-the-content/specs/portaliq-cms/spec.md#requirement-the-language-switch-offers-the-portals-locales-and-the-choice-reaches-the-content
		 * @spec openspec/changes/site-school-blocks/specs/portaliq-cms/spec.md#requirement-the-sign-in-card-offers-the-portals-own-ways-in
		 */
		propsFor(widget) {
			// `style` and `class` never reach a block (REQ-PTB-007).
			const props = withoutStyling(widget.props)

			if (widget.widgetKey === 'markdown') {
				return { source: props.markdown || '' }
			}

			// The menu block: the host supplies the groups and the route on
			// screen, after the authored props, so a placement can rename the
			// landmark but cannot change where the links lead
			// (site-navigation-block).
			if (widget.widgetKey === 'siteNavigation') {
				const navigation = this.navigation || {}
				return {
					label: navigation.label,
					toggleLabel: navigation.toggleLabel,
					...props,
					groups: navigation.groups || [],
					currentRoute: navigation.currentRoute || '/',
				}
			}

			// THE GLOSSARY'S ROWS ARE DATA, NOT PAGE CONFIGURATION.
			//
			// Every other block is fully described by what its author typed.
			// This one is not: the terms live in the portal's own store and
			// this app already fetched them over the public contract. So the
			// host supplies the rows and the author supplies the wording — a
			// block that fetched for itself could not render at a public
			// origin, which is the entire premise of that entry point.
			//
			// Authored props win, so a page can override any label; `terms`
			// comes first so a page that names none still gets them.
			if (widget.widgetKey === 'glossary') {
				return { terms: this.glossary, ...props }
			}

			// SAME RULE, SECOND SUBJECT. The contributed surfaces are data the
			// host fetched over the public contract (ADR-046), not page
			// configuration, so the host supplies the rows and the author
			// supplies the wording.
			if (widget.widgetKey === 'contributions') {
				return { contributions: this.contributions, ...props }
			}

			// SAME RULE, THIRD SUBJECT. Which publication the detail block
			// shows is a property of the ROUTE, not of the placement, so the
			// host supplies it and the author supplies the rest.
			//
			// The authored props come FIRST here, unlike above: `subjectId`
			// must not be overridable from page configuration, or a placement
			// could pin the page to one publication regardless of its URL.
			if (widget.widgetKey === 'publicationDetail') {
				// Inline rather than `withSignedIn()` from residentActions.js:
				// importing that module here would pull all of it into the
				// visitor's first-load entry (webpack.site.js budget).
				return {
					...props,
					subjectId: this.routeParam,
					signedIn: this.signedIn === true,
				}
			}

			// The search block learns the signed-in state the same way
			// (woo-journey-entry-points D1).
			if (widget.widgetKey === 'federatedSearch') {
				return { ...props, signedIn: this.signedIn === true }
			}

			// SAME RULE, FOURTH SUBJECT. Which portal a form's UTM capture is
			// scoped to is a property of the SITE, not of the placement, so
			// the host supplies it and the author supplies the rest
			// (fields/submitLabel/consentText, embedded at creation time —
			// see LandingPageProvisioningService::buildBody()).
			if (widget.widgetKey === 'form') {
				return { ...props, portal: this.portal }
			}

			// SAME RULE, FIFTH SUBJECT. The intake blocks ask this portal's
			// endpoints, so the host names the portal; the form block also
			// takes the catalogue link's segment from the route. Both come
			// after the authored props so a placement cannot point elsewhere.
			if (widget.widgetKey === 'intakeForm') {
				return { ...props, portal: this.portal, routeParam: this.routeParam }
			}

			if (
				widget.widgetKey === 'intakeCatalogue'
				|| widget.widgetKey === 'intakeStatus'
			) {
				return { ...props, portal: this.portal }
			}

			// SAME RULE, SIXTH SUBJECT. The languages on offer are the
			// PORTAL's, so the host supplies them after the authored props: a
			// placement can rename the landmark but cannot add a language
			// (design D1 row 52). Without this branch the switch kept its
			// empty default and never rendered.
			if (widget.widgetKey === 'nlLanguageNav') {
				const languages = this.languages || {}
				return {
					...props,
					locales: Array.isArray(languages.locales)
						? languages.locales
						: [],

					current: languages.current || '',
				}
			}

			// site-school-blocks: the news widgets read this portal's public
			// news, and the article takes its item from the route; the sign-in
			// card gets the portal's own ways in. All after the authored props.
			if (
				widget.widgetKey === 'nlNewsList'
				|| widget.widgetKey === 'nlNewsArticle'
			) {
				return { ...props, portal: this.portal, routeParam: this.routeParam }
			}

			if (widget.widgetKey === 'nlSignIn') {
				return {
					...props,
					signedIn: this.signedIn === true,
					ways: this.signInRoutes.map((r) => ({
						id: r.mode,
						label: r.label,
						href: r.href,
					})),
				}
			}

			return props
		},

		/**
		 * Placeholder text for a widget that will not be mounted.
		 *
		 * @param {string} key The registry key.
		 * @return {string} The placeholder text.
		 *
		 * @spec openspec/specs/portaliq-cms/spec.md#requirement-only-explicitly-public-widgets-must-render-at-a-public-origin
		 */
		placeholderText(key) {
			return `Widget "${key}" is niet beschikbaar op een openbare pagina.`
		},

		/**
		 * Place one widget on the 12-column grid, re-based onto its run.
		 *
		 * @param {object} widget    The placement.
		 * @param {number} rowOffset The run's first authored row.
		 * @return {object} The style bindings.
		 *
		 * @spec openspec/changes/portal-theme-blocks-and-contributed-pages/specs/portaliq-cms/spec.md#requirement-the-hero-must-cap-its-calls-to-action-and-keep-one-outline-entry-req-ptb-006
		 */
		cellStyle(widget, rowOffset) {
			return cellStyle(widget, rowOffset)
		},
	},
}
</script>

<style scoped>
.pq-grid {
	display: grid;
	grid-template-columns: repeat(12, 1fr);
	/* Matches the shared dashboard grid's row unit so a page authored against
	   that geometry is the same height here. */
	grid-auto-rows: minmax(20px, auto);
	gap: 8px;
}

.pq-grid__cell {
	min-width: 0;
}

.pq-grid__placeholder {
	color: var(--pq-muted-color, #6b6b6b);
	font-style: italic;
	margin: 0;
}

@media (max-width: 768px) {
	/* Below the manifest grid's narrow breakpoint every cell is full width;
	   a 12-column layout on a phone is unreadable, and this is a public,
	   mobile-visited surface. */
	.pq-grid {
		display: block;
	}

	.pq-grid__cell {
		margin-bottom: 1rem;
	}
}
</style>
