<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The languages this portal serves (design D1 row 52).

	IT ASKS NOBODY WHICH LANGUAGES TO OFFER. The locales come from the portal
	the page is served as, handed down by the shell, so a placement cannot
	advertise a language the portal does not have. An author has no field here
	for the same reason.
-->
<template>
	<nav
		v-if="locales.length > 1"
		class="utrecht-alternate-lang-nav"
		:aria-label="label"
		data-testid="nl-language-nav">
		<ul class="utrecht-alternate-lang-nav__list">
			<li
				v-for="entry in locales"
				:key="entry.locale"
				class="utrecht-alternate-lang-nav__item">
				<a
					class="utrecht-alternate-lang-nav__link"
					:href="entry.href"
					:hreflang="entry.locale"
					:lang="entry.locale"
					:aria-current="entry.locale === current ? 'true' : null"
					:data-testid="`nl-language-nav-${entry.locale}`">
					{{ entry.label }}
				</a>
			</li>
		</ul>
	</nav>
</template>

<script>
import '@utrecht/alternate-lang-nav-css/dist/index.css'

export default {
	name: 'NlLanguageNav',

	props: {
		/** The portal's locales, as the shell hands them: `{locale, label, href}`. */
		locales: { type: Array, default: () => [] },
		/** Which one is in effect. */
		current: { type: String, default: '' },
		/** The name of the navigation, for a screen reader. */
		label: { type: String, default: 'Taal' },
	},
}
</script>
