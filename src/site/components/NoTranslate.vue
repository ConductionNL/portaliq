<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<!--
		A personal value the browser's translator must leave alone: a name, an
		address, an e-mail address, a phone number, a licence plate, a
		reference number (personal-data-left-untranslated REQ-PDU-001). Wrap
		the VALUE only, never the label or the sentence around it.
	-->
	<span translate="no" class="pq-no-translate"
		><slot>{{ value }}</slot></span
	>
</template>

<script>
/**
 * Marks a value `translate="no"`. Give the value as the `value` prop or as the slot.
 *
 * @spec openspec/changes/personal-data-left-untranslated/specs/portaliq-cms/spec.md#requirement-browser-translation-leaves-names-and-personal-data-alone-req-pdu-001
 */
export default {
	name: 'NoTranslate',

	props: {
		/** The value to show when no slot is given. */
		value: { type: [String, Number], default: '' },
	},
}
</script>
