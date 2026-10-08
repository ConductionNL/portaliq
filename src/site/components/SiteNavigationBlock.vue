<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<template>
	<!--
		THE MENU BLOCK (`siteNavigation`). The portal's navigation as one
		vertical list in groups, for a page that places it in its side region
		(or its grid). The header then leaves its own menu out, so every link
		is on the page once.

		On a phone the groups collapse behind one button. The button controls
		the list (`aria-controls`, `aria-expanded`); on a wide screen it is not
		shown and the list always is.
	-->
	<nav
		class="pq-sitenav"
		:class="{ 'pq-sitenav--open': open }"
		:aria-label="label"
		data-testid="site-navigation">
		<button
			type="button"
			class="utrecht-button utrecht-button--secondary-action pq-sitenav__toggle"
			:aria-expanded="String(open)"
			:aria-controls="listId"
			data-testid="site-navigation-toggle"
			@click="open = !open">
			{{ toggleLabel }}
		</button>
		<div :id="listId" class="pq-sitenav__groups">
			<section
				v-for="group in groups"
				:key="group.id"
				class="pq-sitenav__group"
				:aria-labelledby="`${listId}-${group.id}`"
				data-testid="site-navigation-group">
				<h2
					:id="`${listId}-${group.id}`"
					class="utrecht-heading-5 pq-sitenav__heading">
					{{ group.title }}
				</h2>
				<ul class="pq-sitenav__list">
					<li
						v-for="item in group.items"
						:key="`${group.id}-${item.link}-${item.name}`"
						class="pq-sitenav__item">
						<a
							class="utrecht-link pq-sitenav__link"
							:href="item.href || item.link"
							:aria-current="
								item.link === currentRoute ? 'page' : undefined
							"
							data-testid="site-navigation-link"
							@click.prevent="select(item.link)">
							<span>{{ item.name }}</span>
							<span v-if="item.badge" class="pq-sitenav__badge">
								<span aria-hidden="true">{{ item.badge }}</span>
								<span class="pq-sitenav__sr">{{
									item.badgeLabel || item.badge
								}}</span>
							</span>
						</a>
					</li>
				</ul>
			</section>
		</div>
	</nav>
</template>

<script>
let instances = 0

/**
 * The portal's menu block (`siteNavigation`).
 *
 * The groups and every string are props: the shell derives the groups from
 * the same answers the header menu reads (src/site/lib/siteNavigation.js),
 * and nothing here reads a router, a translator or a Nextcloud global, so
 * the block mounts at a public origin and in an editor canvas alike.
 *
 * @spec openspec/changes/site-navigation-block/specs/portaliq-cms/spec.md#requirement-a-menu-block-must-show-the-portals-navigation-in-groups
 */
export default {
	name: 'SiteNavigationBlock',

	props: {
		/** `{id, title, items: [{name, link, href, badge?, badgeLabel?}]}` groups. */
		groups: { type: Array, default: () => [] },
		/** The route on screen, marked as the current page. */
		currentRoute: { type: String, default: '/' },
		/** The navigation landmark's accessible name. */
		label: { type: String, default: 'Menu' },
		/** The phone-width button's text. */
		toggleLabel: { type: String, default: 'Menu' },
	},

	emits: ['navigate'],

	data() {
		instances += 1
		return {
			// Closed by default: on a phone the content comes first. On a
			// wide screen the CSS shows the list whatever this says.
			open: false,
			listId: `pq-sitenav-${instances}`,
		}
	},

	methods: {
		/**
		 * Navigate in place, and close the phone menu.
		 *
		 * @param {string} link The route.
		 * @return {void}
		 */
		select(link) {
			this.open = false
			this.$emit('navigate', link)
		},
	},
}
</script>

<style scoped>
.pq-sitenav__toggle {
	display: none;
}

.pq-sitenav__group + .pq-sitenav__group {
	margin-block-start: 1.5rem;
}

.pq-sitenav__heading {
	margin-block: 0 0.5rem;
}

.pq-sitenav__list {
	list-style: none;
	margin: 0;
	padding: 0;
}

.pq-sitenav__link {
	display: flex;
	align-items: center;
	justify-content: space-between;
	gap: 0.5rem;
	padding-block: 0.5rem;
	padding-inline: 0.75rem;
	border-inline-start: 4px solid transparent;
	text-decoration: none;
}

.pq-sitenav__link:hover {
	text-decoration: underline;
}

.pq-sitenav__link[aria-current='page'] {
	border-inline-start-color: currentcolor;
	font-weight: 700;
}

/* An outline in the link's own colour, so the count reads on every theme. */
.pq-sitenav__badge {
	min-inline-size: 1.5rem;
	padding-inline: 0.4rem;
	border: 1px solid currentcolor;
	border-radius: 999px;
	text-align: center;
	font-size: 0.875em;
}

.pq-sitenav__sr {
	position: absolute;
	inline-size: 1px;
	block-size: 1px;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
}

@media (width < 768px) {
	.pq-sitenav__toggle {
		display: inline-flex;
	}

	.pq-sitenav__groups {
		display: none;
		margin-block-start: 1rem;
	}

	.pq-sitenav--open .pq-sitenav__groups {
		display: block;
	}
}
</style>
