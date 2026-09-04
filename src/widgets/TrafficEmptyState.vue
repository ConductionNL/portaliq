<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- SPDX-FileCopyrightText: 2026 Conduction B.V. -->

<!--
  TrafficEmptyState — the two ways a Traffic widget can have nothing to
  draw, rendered DIFFERENTLY on purpose (portal-traffic-analytics).

  "Not measured" is a portal whose operator never switched measurement
  on. "No traffic recorded yet" is a measured portal the aggregation job
  has not written a rollup for. A chart showing zero for the first would
  be the more convincing of the two lies, so neither state renders a
  chart, and each carries its own test id.

  @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-the-traffic-page-must-show-what-was-measured-and-say-when-it-was-not
-->
<template>
	<CnWidgetEmptyState
		v-if="state === 'not-measured'"
		:name="t('portaliq', 'Not measured for this portal')"
		:description="
			t(
				'portaliq',
				'Traffic measurement is switched off for this portal. Turn it on in the portal settings under traffic to start counting.',
			)
		"
		variant="neutral"
		compact
		data-testid="traffic-not-measured" />
	<CnWidgetEmptyState
		v-else-if="state === 'no-data'"
		:name="t('portaliq', 'No traffic recorded yet')"
		:description="
			t(
				'portaliq',
				'Measurement is on, but no daily figures exist for the last 30 days. The first figures appear within fifteen minutes of the first visit.',
			)
		"
		variant="neutral"
		compact
		data-testid="traffic-empty" />
</template>

<script>
import { CnWidgetEmptyState } from '@conduction/nextcloud-vue'

export default {
	name: 'TrafficEmptyState',

	components: {
		CnWidgetEmptyState,
	},

	props: {
		/**
		 * 'not-measured', 'no-data', or '' for nothing.
		 */
		state: {
			type: String,
			default: '',
		},
	},
}
</script>
