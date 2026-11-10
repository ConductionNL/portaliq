<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<svg
		class="pq-error-icon"
		viewBox="0 0 20 20"
		aria-hidden="true"
		focusable="false">
		<circle
			cx="10"
			cy="10"
			r="8"
			fill="none"
			stroke="currentColor"
			stroke-width="1.75" />
		<path
			d="M10 5.5v5.5M10 13.75v.5"
			stroke="currentColor"
			stroke-width="2"
			stroke-linecap="round" />
	</svg>
</template>

<script>
/**
 * The error icon beside a field's error message and in the error summary, so
 * an error never rests on colour alone. It carries no style of its own: the
 * component that draws it sizes it under `.pq-error-icon`, and its scoped
 * style reaches this root element.
 *
 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-failed-submit-must-show-an-error-summary-that-takes-focus-req-smf-002
 */
export default {
	name: 'ErrorIcon',
}
</script>
