<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<!-- What a signed-in section shows until its page is registered in
	     pages/registry.js: the section is in the menu, and says plainly that
	     it is not on this site yet rather than rendering an empty page. -->
	<div class="pq-account-placeholder" data-testid="site-account-placeholder">
		<p class="utrecht-paragraph">
			{{ t('This part of your portal is not available on this site yet.') }}
		</p>
	</div>
</template>

<script>
/**
 * The page a signed-in section renders while nothing is registered for it.
 *
 * @spec openspec/changes/portal-shared-runtime/specs/portal-shared-runtime/spec.md#requirement-the-portal-must-boot-the-shared-runtime-and-ship-no-react
 */
export default {
	name: 'PlaceholderPage',

	// Every page is handed the whole contract (entry, api, session, nav, …);
	// this one needs only the translator, and the rest must not land on the
	// DOM as attributes.
	inheritAttrs: false,

	props: {
		/** The site translator. */
		t: { type: Function, required: true },
	},
}
</script>
