<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The header `src/site/App.vue` hard-coded on development fc17d3fb, before the
	header became the `brandHeader` block. Copied verbatim (lines 54-164) so
	tests/site-shell-blocks.spec.mjs can render both and compare. Only the
	bindings that reached into App.vue are replaced by props of the same name.
-->
<template>
	<header class="ac-header pq-site__header" data-testid="site-header">
		<div class="ac-header__navigation-main">
			<div class="ac-header__logo">
				<div>
					<div class="con-logo-container header" />
					<span class="sr-only">Logo</span>
					<h1 class="logo-text" data-testid="site-title">
						{{ site.title || '…' }}
					</h1>
				</div>
			</div>

			<!--
				The sign-in affordance appears ONLY when the portal declares
				a mode other than `public`. A portal with no accounts must
				show no login button: an inert one is a support ticket from
				every visitor who presses it.

				It sits in the reference's `__right-section` / `ac-navigation`
				slot, which is where that implementation puts
				Aanmelden/Inloggen.
			-->
			<div class="ac-header__right-section">
				<div
					v-if="session || signInRoutes.length"
					class="ac-navigation pq-site__auth"
					data-testid="site-auth">
					<template v-if="session">
						<span data-testid="site-auth-subject">{{
							sessionLabel
						}}</span>
						<button
							type="button"
							data-testid="site-signout"
							@click="$emit('signout')">
							Uitloggen
						</button>
					</template>
					<nav v-else aria-label="Gebruikersmenu">
						<ul>
							<li v-for="entry in signInRoutes" :key="entry.mode">
								<a
									:href="entry.href"
									:data-mode="entry.mode"
									data-testid="site-signin">
									{{ entry.label }}
								</a>
							</li>
						</ul>
					</nav>
				</div>
			</div>
		</div>

		<div class="ac-header__navigation-secondary">
			<div class="container">
				<div class="ac-c-navigation__container">
					<SiteMenu
						v-for="menu in headerMenus"
						:key="menu.title"
						:menu="menu"
						:currentRoute="route"
						@navigate="$emit('navigate', $event)" />
				</div>
			</div>
		</div>

		<!--
			THE BREADCRUMB, matching the reference's `Kruimelpad` landmark.

			It renders only BELOW the home route: a trail whose only entry
			is the page you are on tells the visitor nothing and adds a
			landmark for a screen reader to step through.

			The last crumb is the current page and is NOT a link — an
			anchor to where you already are is a control that does nothing.
		-->
		<div class="ac-header__navigation-breadcrumb">
			<div class="container">
				<nav
					v-if="breadcrumbs.length > 1"
					class="ac-breadcrumb"
					aria-label="Kruimelpad"
					data-testid="site-breadcrumb">
					<ul class="ac-breadcrumb__list">
						<li
							v-for="(crumb, index) in breadcrumbs"
							:key="crumb.route"
							class="ac-breadcrumb__item">
							<a
								v-if="index < breadcrumbs.length - 1"
								class="utrecht-link"
								:href="crumb.href"
								@click.prevent="$emit('navigate', crumb.route)">
								{{ crumb.label }}
							</a>
							<span v-else aria-current="page">{{ crumb.label }}</span>
							<span
								v-if="index < breadcrumbs.length - 1"
								class="ac-breadcrumb__separator"
								aria-hidden="true">
								›
							</span>
						</li>
					</ul>
				</nav>
			</div>
		</div>
	</header>
</template>

<script>
import SiteMenu from '../../src/site/components/SiteMenu.vue'

export default {
	name: 'ShellHeaderBaseline',
	components: { SiteMenu },
	props: {
		site: { type: Object, default: () => ({}) },
		headerMenus: { type: Array, default: () => [] },
		route: { type: String, default: '/' },
		breadcrumbs: { type: Array, default: () => [] },
		session: { type: Object, default: null },
		sessionLabel: { type: String, default: '' },
		signInRoutes: { type: Array, default: () => [] },
	},

	emits: ['navigate', 'signout'],
}
</script>
