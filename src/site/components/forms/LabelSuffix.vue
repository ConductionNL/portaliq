<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<span class="pq-label-suffix" data-testid="label-suffix"> {{ text }}</span>
</template>

<script>
/**
 * The NL Design System "Form Field Label Suffix": "(niet verplicht)" after the
 * name of a field the resident may leave empty. It sits inside the `<label>`
 * or `<legend>`, so a screen reader reads it with the field's name. Required
 * fields carry no mark at all.
 *
 * @spec openspec/changes/site-multi-step-forms/specs/site-forms/spec.md#requirement-a-site-form-must-mark-the-fields-that-are-not-required-req-smf-001
 */
export default {
	name: 'LabelSuffix',

	props: {
		/** The suffix, in the site's language. */
		text: { type: String, default: '(niet verplicht)' },
	},
}
</script>

<style scoped>
.pq-label-suffix {
	color: var(--utrecht-form-label-color, var(--utrecht-document-color, inherit));
	font-weight: var(--utrecht-typography-weight-scale-normal, normal);
}
</style>
