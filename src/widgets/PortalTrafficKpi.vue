<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  PortalTrafficKpi: one traffic KPI card on a portal's detail page
  (portal-traffic-kpi-cards).

  A thin wrapper around nc-vue's CnStatWidget, which already draws the
  canonical KPI card and its per-card period picker. The wrapper adds
  three things a manifest `stat` widget cannot do on its own:

  - "Not measured" for a portal with measurement off. The card then gets
    no endpoint at all, so it asks for nothing and cannot draw a zero.
  - The link to the Traffic page with THIS portal selected. A card's
    route tokens resolve only global values, never `@object.*`, so the
    slug is written into the link here.
  - The period. CnStatWidget draws the picker and documents `@range.*`
    tokens for its endpoint, but nc-vue 2.56.0 never resolves them: the
    literal token stays in the params and blocks the request. So the
    wrapper watches the card's chosen preset and sends it as `days`.

  The manifest supplies `metric` (a key of the summary response), the
  label, the variant and the period presets.

  @spec openspec/changes/portal-traffic-kpi-cards/specs/portal-traffic-kpi-cards/spec.md#requirement-the-portal-page-must-open-with-four-traffic-kpi-cards
-->
<template>
	<CnStatWidget ref="card" :content="cardContent" :data-testid="testId" />
</template>

<script>
import { CnStatWidget } from '@conduction/nextcloud-vue'
import { isMeasured } from '../lib/trafficSummary.js'

export default {
	name: 'PortalTrafficKpi',

	components: {
		CnStatWidget,
	},

	props: {
		/**
		 * The widget's manifest content: `metric`, `label`, `variant`,
		 * `dateRange` and an optional `testId`.
		 */
		content: {
			type: Object,
			default: () => ({}),
		},

		/** The portal the detail page shows. */
		objectData: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			// The preset id the card's period picker holds; '' is the
			// default preset, 30 days.
			days: '',
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
		 * The CnStatWidget content: the endpoint for a measured portal,
		 * "Not measured" for one that is not, the link either way.
		 *
		 * @spec openspec/changes/portal-traffic-kpi-cards/specs/portal-traffic-kpi-cards/spec.md#requirement-the-portal-page-must-open-with-four-traffic-kpi-cards
		 * @return {object} The content.
		 */
		cardContent() {
			const slug = (this.objectData && this.objectData.slug) || ''
			const card = {
				label: this.content.label,
				variant: this.content.variant,
				caption: this.content.caption,
				route: slug
					? { name: 'Traffic', query: { portal: slug } }
					: { name: 'Traffic' },
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
					params: { portal: slug, days: this.days },
				},

				valueField: this.content.metric,
			}
		},
	},

	mounted() {
		// The picker's choice lives in the card's own setup state.
		this.$watch(
			() => (this.$refs.card && this.$refs.card.tileRange) || null,
			(range) => {
				this.days = (range && range.preset) || ''
			},
		)
	},
}
</script>
