<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The steps still to do, and the ones already done (design D1 row 95).

	No upstream CSS, so tokens only (design D5). The state is in WORDS as well
	as in colour: a tick and a tint are invisible to somebody who cannot see
	them, and colour alone fails WCAG 1.4.1.
-->
<template>
	<nav class="nl-tasknav" :aria-label="label" data-testid="nl-task-nav">
		<ol class="nl-tasknav__list">
			<li
				v-for="(item, index) in safeItems"
				:key="`${index}-${item.label}`"
				class="nl-tasknav__item"
				:class="`nl-tasknav__item--${item.state}`">
				<a
					v-if="item.href"
					class="utrecht-link nl-tasknav__link"
					:href="item.href"
					:aria-current="item.state === 'current' ? 'step' : null"
					:data-testid="`nl-task-nav-${index}`">
					{{ item.label }}
				</a>
				<span
					v-else
					class="nl-tasknav__link"
					:data-testid="`nl-task-nav-${index}`">
					{{ item.label }}
				</span>
				<!--
					THE STATE IN WORDS. A colour and a tick say nothing to a screen
					reader and nothing to somebody who cannot tell the tints apart,
					so each step carries its state as text.
				-->
				<span class="nl-tasknav__state">{{ stateLabels[item.state] }}</span>
			</li>
		</ol>
	</nav>
</template>

<script>
import '@utrecht/link-css/dist/index.css'

export default {
	name: 'NlTaskNav',

	props: {
		/** The steps: `{label, href, state}`, state one of done, current, todo. */
		items: { type: Array, default: () => [] },
		/** The name of the navigation, for a screen reader. */
		label: { type: String, default: 'Stappen' },
		/** What each state is called. */
		stateLabels: {
			type: Object,
			default: () => ({
				done: 'Afgerond',
				current: 'Nu',
				todo: 'Nog te doen',
			}),
		},
	},

	computed: {
		/**
		 * The steps that have a label, each with a state this widget knows.
		 *
		 * @return {Array<object>} The steps.
		 *
		 * @spec openspec/changes/site-nlds-widget-palette/specs/portaliq-cms/spec.md#requirement-every-nl-design-system-component-must-be-placeable-or-carry-a-reason-req-snw-010
		 */
		safeItems() {
			return (this.items || [])
				.map((item) => {
					const state = String(item?.state ?? 'todo')
					const href = String(item?.href ?? '').trim()
					return {
						label: String(item?.label ?? '').trim(),
						href: /^\/(?!\/)/.test(href) ? href : '',
						state: ['done', 'current', 'todo'].includes(state)
							? state
							: 'todo',
					}
				})
				.filter((item) => item.label !== '')
		},
	},
}
</script>

<style scoped>
/*
 * TOKENS ONLY (design D5). The states differ in weight and in the text
 * beside them, not in colour alone.
 */
.nl-tasknav__list {
	display: flex;
	flex-direction: column;
	gap: var(--utrecht-space-block-xs, 0.25rem);
	margin: 0;
	padding: 0;
	list-style: none;
	counter-reset: step;
}

.nl-tasknav__item {
	display: flex;
	align-items: baseline;
	justify-content: space-between;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
	padding-block: var(--utrecht-space-block-xs, 0.25rem);
	border-block-end: 1px solid var(--utrecht-color-grey-20, currentcolor);
}

.nl-tasknav__item--current .nl-tasknav__link {
	font-weight: bold;
}

.nl-tasknav__state {
	color: var(--utrecht-document-color, CanvasText);
	font-size: 0.875em;
}
</style>
