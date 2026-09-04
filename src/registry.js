// SPDX-License-Identifier: EUPL-1.2
// Copyright (C) 2026 Conduction B.V.
//
// v2 component registry for the manifest-driven app shell.
//
// This file is the v2 replacement for customComponents.js. Where
// customComponents.js only supported `type: "custom"` page components,
// this registry supports all five kinds defined in hydra ADR-036:
//
//   - widget       — placeable in any allowed slot via grid coords
//   - modal        — opened by action reference; not gridded externally
//   - page         — full-page custom component (escape hatch; keep near-zero)
//   - form-field   — custom property editor (auto-bound by format/property)
//   - cell-renderer — custom table-cell rendering (auto-bound by schema/property)
//
// Each entry: { kind, component, ...kindMetadata }
//
// Resolution at runtime:
//   1. Built-in widgets    (object-table, form-renderer, wiki-renderer, …)
//   2. This registry       ← consumer-injected components
//
// How to add a new widget:
//   1. Create src/widgets/<YourWidget>.vue.
//   2. Add an entry here with kind: "widget" + required metadata.
//   3. Reference it in src/manifest.json via widgetKey: "<your-key>".
//
// How to add a new modal:
//   1. Create src/modals/<YourModal>.vue.
//   2. Add an entry here with kind: "modal" + propsSchema.
//   3. Trigger it in manifest actions via type: "open-modal", target: "<your-key>".
//
// How to add a custom page:
//   1. Create src/views/<YourPage>.vue.
//   2. Add an entry here with kind: "page".
//   3. Add a manifest page entry with type: "custom", component: "<your-key>",
//      and a _note explaining why a standard page type was not feasible.
//
// See: https://github.com/ConductionNL/hydra → openspec/architecture/adr-036-universal-widget-manifest.md

import StatusBadge from './cellRenderers/StatusBadge.vue'
import EmailField from './formFields/EmailField.vue'
import ExampleModal from './modals/ExampleModal.vue'
import CustomExample from './views/CustomExample.vue'
import FlowDetailSidebar from './views/flows/FlowDetailSidebar.vue'
import PageLayoutDesigner from './views/PageLayoutDesigner.vue'
import TrafficDaily from './widgets/TrafficDaily.vue'
import TrafficJourneys from './widgets/TrafficJourneys.vue'
import TrafficOverview from './widgets/TrafficOverview.vue'
import TrafficPages from './widgets/TrafficPages.vue'
import TrafficSources from './widgets/TrafficSources.vue'

// The Traffic page's widgets share one shape: a full-width panel on the
// dashboard body. See the kind: "widget" block below for why they are
// custom at all.
const TRAFFIC_WIDGET_META = {
	defaultSize: { w: 12, h: 3 },
	minSize: { w: 6, h: 2 },
	maxSize: { w: 12, h: 8 },
	allowedSlots: ['body'],
	propsSchema: null,
}

export default {
	// --- Flows (ADR-110 Decision 4). Only the SIDEBAR is an app component;
	//     the list and the canvas are the shared `flows` / `flow-detail`
	//     manifest page types. CnFlowSidebar has to mount in the NC app
	//     sidebar for the canvas to keep full width. ---
	FlowDetailSidebar: { kind: 'page', component: FlowDetailSidebar },

	// -------------------------------------------------------------------------
	// kind: "widget" — placeable in any allowed slot via grid coordinates
	//
	// The five Traffic widgets (portal-traffic-analytics). They are custom
	// because no built-in widget can say "not measured": a stats-block or a
	// chart over `portalTrafficDaily` renders a ZERO for a portal whose
	// operator never switched measurement on, and a zero and an unmeasured
	// are different facts. They also share one portal selector through a
	// store, which a dataSource-driven widget has no way to do.
	// -------------------------------------------------------------------------

	TrafficOverview: {
		kind: 'widget',
		component: TrafficOverview,
		...TRAFFIC_WIDGET_META,
		_note: 'Portal selector plus four CnStatsBlock tiles (page views, sessions, visitors, engaged sessions, 30 days) read from portalTrafficDaily through the OR object API. Custom because it must render "Not measured for this portal" DIFFERENTLY from "No traffic recorded yet" and warn about the sensitive switches; a stats-block dataSource shows a zero for both.',
	},
	TrafficDaily: {
		kind: 'widget',
		component: TrafficDaily,
		...TRAFFIC_WIDGET_META,
		_note: 'CnChartWidget area chart of page views, sessions and visitors per day. Custom because a chart dataSource would draw an empty chart for an unmeasured portal, which the spec forbids, and because it follows the portal selected on TrafficOverview.',
	},
	TrafficPages: {
		kind: 'widget',
		component: TrafficPages,
		...TRAFFIC_WIDGET_META,
		_note: 'Top pages with entrances and exits, merged across the daily rollups of the selected portal. Custom because the rows live inside each rollup object (pages[]), which object-table cannot unfold or sum across objects.',
	},
	TrafficJourneys: {
		kind: 'widget',
		component: TrafficJourneys,
		...TRAFFIC_WIDGET_META,
		_note: 'Top page-to-page transitions, merged across the daily rollups. Custom for the same reason as TrafficPages: transitions[] is nested per rollup.',
	},
	TrafficSources: {
		kind: 'widget',
		component: TrafficSources,
		...TRAFFIC_WIDGET_META,
		_note: 'Referrers grouped by channel with the busiest hosts, merged across the daily rollups. Custom for the same reason as TrafficPages: referrers[] is nested per rollup.',
	},

	// -------------------------------------------------------------------------
	// kind: "modal" — opened via actions[].type: "open-modal"
	// -------------------------------------------------------------------------

	/**
	 * Example confirm-action modal. Keep or delete when scaffolding.
	 * Trigger via manifest action: { type: "open-modal", target: "example-modal" }.
	 */
	'example-modal': {
		kind: 'modal',
		component: ExampleModal,
		propsSchema: {
			type: 'object',
			properties: {
				title: { type: 'string' },
				message: { type: 'string' },
			},
		},
	},

	// -------------------------------------------------------------------------
	// kind: "page" — full-page custom components (escape hatch; keep near-zero)
	//
	// PascalCase keys match the manifest's `component` field so the v1
	// customComponents.js entries work unchanged during the v1 → v2 transition.
	// -------------------------------------------------------------------------

	/**
	 * Example custom page. The manifest does NOT reference this by default;
	 * it is included so the registry's role is visible to first-time cloners.
	 * Wire it up by adding a type: "custom" page entry to src/manifest.json
	 * with component: "CustomExample" and a _note field.
	 */
	CustomExample: {
		kind: 'page',
		component: CustomExample,
	},

	/**
	 * The page layout designer — direct-manipulation editing of a portal
	 * page's widget grid, reached from `/pages/:id/layout` and from the
	 * floating editing control on the site itself.
	 *
	 * A custom page rather than a page type: what it edits is one schema's
	 * `body.widgets`, on a grid whose geometry belongs to this app's CMS, and
	 * the escape hatch is what ADR-036 offers for exactly that.
	 */
	PageLayoutDesigner: {
		kind: 'page',
		component: PageLayoutDesigner,
	},

	// -------------------------------------------------------------------------
	// kind: "form-field" — custom property editors
	// -------------------------------------------------------------------------

	/**
	 * Email address input. Auto-bound by the form renderer to any JSON Schema
	 * property with format: "email". Replace or extend for your app's fields.
	 */
	'email-field': {
		kind: 'form-field',
		component: EmailField,
		appliesTo: {
			format: 'email',
		},
	},

	// -------------------------------------------------------------------------
	// kind: "cell-renderer" — custom table-cell rendering
	// -------------------------------------------------------------------------

	/**
	 * Status badge renderer. Auto-bound by the object table to the "status"
	 * property column on "example" schema rows. Adjust appliesTo for your schema.
	 */
	'status-badge': {
		kind: 'cell-renderer',
		component: StatusBadge,
		appliesTo: {
			schema: 'example',
			property: 'status',
		},
	},
}
