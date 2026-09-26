<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  PortalTrafficKpi: one traffic KPI card on a portal's detail page
  (portal-traffic-kpi-cards), or on a portal page's detail page with
  `content.scope: 'page'` (portal-page-traffic).

  A thin wrapper around nc-vue's CnStatWidget, which already draws the
  canonical KPI card and its per-card period picker. The wrapper adds
  three things a manifest `stat` widget cannot do on its own:

  - "Not measured" for a portal with measurement off, never a zero. On a
    portal's page the card then gets no endpoint at all, so it asks for
    nothing. A page does not carry its portal's `traffic` block, so on a
    page the endpoint answers `measured` and every figure null, and the
    card reads its answer.
  - The link to the Traffic page with THIS portal selected. A card's
    route tokens resolve only global values, never `@object.*`, so the
    slug is written into the link here.
  - The period. CnStatWidget draws the picker and documents `@range.*`
    tokens for its endpoint, but nc-vue 2.56.0 never resolves them: the
    literal token stays in the params and blocks the request. So the
    wrapper watches the card's chosen preset and sends it as `days`.

  On a page, a figure that no day of the period counted (days older than
  the back-filled retention window) reads "Not available for this
  period", and one that only some days counted says on how many.

  The manifest supplies `metric` (a key of the endpoint's answer), the
  label, the variant, the period presets and, for a page, `scope`.

  @spec openspec/changes/portal-traffic-kpi-cards/specs/portal-traffic-kpi-cards/spec.md#requirement-the-portal-page-must-open-with-four-traffic-kpi-cards
  @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-the-page-detail-must-open-with-four-traffic-kpi-cards
-->
<template>
	<CnStatWidget ref="card" :content="cardContent" :data-testid="testId" />
</template>

<script>
import { CnStatWidget } from '@conduction/nextcloud-vue'
import { pageRoute } from '../lib/pageTraffic.js'
import { isMeasured } from '../lib/trafficSummary.js'

/**
 * The figures of the page answer that only back-filled days carry.
 */
const DETAIL_METRICS = ['sessions', 'visitors', 'engagedSessions']

export default {
	name: 'PortalTrafficKpi',

	components: {
		CnStatWidget,
	},

	props: {
		/**
		 * The widget's manifest content: `metric`, `label`, `variant`,
		 * `dateRange`, an optional `testId` and an optional `scope`
		 * (`portal`, the default, or `page`).
		 */
		content: {
			type: Object,
			default: () => ({}),
		},

		/** The portal, or the page, the detail page shows. */
		objectData: {
			type: Object,
			default: null,
		},
	},

	/**
	 * The picked period and, on a page, the endpoint's answer.
	 *
	 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-the-page-detail-must-open-with-four-traffic-kpi-cards
	 * @return {object} The state.
	 */
	data() {
		return {
			// The preset id the card's period picker holds; '' is the
			// default preset, 30 days.
			days: '',
			// The page endpoint's answer, read off the card (page scope).
			answer: null,
		}
	},

	computed: {
		/**
		 * The test id the manifest gave this card, or a default.
		 *
		 * @spec openspec/changes/portal-traffic-kpi-cards/specs/portal-traffic-kpi-cards/spec.md#requirement-the-portal-page-must-open-with-four-traffic-kpi-cards
		 * @return {string} The test id.
		 */
		testId() {
			return (
				this.content.testId
				|| 'portal-traffic-kpi-' + (this.content.metric || '')
			)
		},

		/**
		 * Whether this card shows one page rather than a whole portal.
		 *
		 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-the-page-detail-must-open-with-four-traffic-kpi-cards
		 * @return {boolean} True for page scope.
		 */
		pageScope() {
			return this.content.scope === 'page'
		},

		/**
		 * The portal slug: the portal's own, or the page's `portal`.
		 *
		 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-the-page-detail-must-open-with-four-traffic-kpi-cards
		 * @return {string} The slug, '' when unknown.
		 */
		slug() {
			const object = this.objectData || {}
			return String((this.pageScope ? object.portal : object.slug) || '')
		},

		/**
		 * The CnStatWidget content: the endpoint for a measured portal,
		 * "Not measured" for one that is not, the link either way.
		 *
		 * @spec openspec/changes/portal-traffic-kpi-cards/specs/portal-traffic-kpi-cards/spec.md#requirement-the-portal-page-must-open-with-four-traffic-kpi-cards
		 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-the-page-detail-must-open-with-four-traffic-kpi-cards
		 * @return {object} The content.
		 */
		cardContent() {
			const card = {
				label: this.content.label,
				variant: this.content.variant,
				caption: this.content.caption,
				route: this.slug
					? { name: 'Traffic', query: { portal: this.slug } }
					: { name: 'Traffic' },
			}
			if (this.pageScope) {
				return this.pageCard(card)
			}
			if (!isMeasured(this.objectData)) {
				return {
					...card,
					emptyText: this.t('portaliq', 'Not measured'),
				}
			}
			return {
				...card,
				dateRange: this.content.dateRange,
				endpointSource: {
					url: '/apps/portaliq/api/traffic/summary',
					params: { portal: this.slug, days: this.days },
				},

				valueField: this.content.metric,
			}
		},
	},

	/**
	 * Follow the card's period picker: its choice lives in the card's
	 * own setup state, which the endpoint params cannot read. On a page,
	 * follow the card's answer too: it says whether the portal is
	 * measured and on how many days a figure was counted.
	 *
	 * @spec openspec/changes/portal-traffic-kpi-cards/specs/portal-traffic-kpi-cards/spec.md#requirement-the-portal-page-must-open-with-four-traffic-kpi-cards
	 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-the-page-detail-must-open-with-four-traffic-kpi-cards
	 * @return {void}
	 */
	mounted() {
		this.$watch(
			() => (this.$refs.card && this.$refs.card.tileRange) || null,
			(range) => {
				this.days = (range && range.preset) || ''
			},
		)
		this.$watch(
			() => (this.$refs.card && this.$refs.card.epData) || null,
			(answer) => {
				this.answer = answer
			},
		)
	},

	methods: {
		/**
		 * The content of a page-scope card.
		 *
		 * A page without a portal or a route has nothing to ask for and
		 * reads "Not measured". Otherwise the card asks the page endpoint;
		 * its empty text follows the answer: "Not measured" when the
		 * portal is not, "Not available for this period" when no day of
		 * the period counted the figure. A figure only some days counted
		 * says on how many.
		 *
		 * @spec openspec/changes/portal-page-traffic/specs/portal-page-traffic/spec.md#requirement-the-page-detail-must-open-with-four-traffic-kpi-cards
		 * @param {object} card The shared part: label, variant, link.
		 * @return {object} The content.
		 */
		pageCard(card) {
			const route = pageRoute(this.objectData)
			if (!this.slug || route === '') {
				return { ...card, emptyText: this.t('portaliq', 'Not measured') }
			}
			const answer = this.answer || {}
			let emptyText = this.t('portaliq', 'Not available for this period')
			let caption = card.caption
			if (answer.measured === false) {
				emptyText = this.t('portaliq', 'Not measured')
			} else if (
				DETAIL_METRICS.includes(this.content.metric)
				&& answer.detailDays > 0
				&& answer.detailDays < answer.recordedDays
			) {
				caption = this.t(
					'portaliq',
					'Counted on {counted} of {recorded} days',
					{ counted: answer.detailDays, recorded: answer.recordedDays },
				)
			}
			return {
				...card,
				caption,
				emptyText,
				dateRange: this.content.dateRange,
				endpointSource: {
					url: '/apps/portaliq/api/traffic/page',
					params: { portal: this.slug, route, days: this.days },
				},

				valueField: this.content.metric,
			}
		},
	},
}
</script>
