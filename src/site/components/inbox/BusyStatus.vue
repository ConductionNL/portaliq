<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	A loading indicator in a polite status region: a sighted reader sees "…",
	a screen reader hears "Loading…" in the page language.
-->
<template>
	<p class="utrecht-paragraph pq-busy" role="status" aria-live="polite">
		<span aria-hidden="true">…</span>
		<span class="sr-only">{{ t('Loading…') }}</span>
	</p>
</template>

<script>
/**
 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-the-inbox-must-merge-every-apps-messages-req-srp-030
 */
export default {
	name: 'BusyStatus',

	props: {
		/** The translator. */
		t: { type: Function, required: true },
	},
}
</script>
