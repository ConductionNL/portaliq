<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	Maintenance and warning notices above every page of the public site
	(operate-maintenance-notice), signed in and signed out, through the shared
	helper the React portal used. Deliberately not an alert role:
	a notice that is there on every page load must not interrupt a screen
	reader each time.
-->
<template>
	<section
		v-if="shown.length > 0"
		class="pq-site-notices"
		:aria-label="label.notice"
		data-testid="site-notices">
		<div class="container">
			<div
				v-for="notice in shown"
				:key="notice.id"
				class="utrecht-alert"
				:class="
					notice.level === 'warning'
						? 'utrecht-alert--warning'
						: 'utrecht-alert--info'
				"
				data-testid="site-notice">
				<p class="utrecht-paragraph">
					{{ notice.message }}
					<a
						v-if="notice.linkUrl"
						class="utrecht-link"
						:href="notice.linkUrl"
						>{{ notice.linkLabel || label.more }}</a
					>
				</p>
				<button
					type="button"
					class="utrecht-button utrecht-button--subtle"
					@click="close(notice.id)">
					{{ label.close }}
				</button>
			</div>
		</div>
	</section>
</template>

<script>
import {
	closedNotices,
	closeNotice,
	sessionStore,
	visibleNotices,
} from '../../shared/notices.js'

import '@utrecht/alert-css/dist/index.css'

const LABELS = {
	en: { notice: 'Notice', close: 'Close this notice', more: 'More information' },
	nl: {
		notice: 'Melding',
		close: 'Deze melding sluiten',
		more: 'Meer informatie',
	},
}

/**
 * @spec openspec/specs/portal-notices/spec.md#requirement-a-visitor-can-close-a-notice-for-the-visit-req-omn-002
 */
export default {
	name: 'SiteNotices',

	props: {
		notices: {
			type: Array,
			default: () => [],
		},

		locale: {
			type: String,
			default: 'nl',
		},
	},

	data() {
		return { closed: closedNotices(sessionStore()) }
	},

	computed: {
		/**
		 * @return {Array<object>} The notices to render now.
		 *
		 * @spec openspec/specs/portal-notices/spec.md#requirement-a-notice-shows-on-every-page-during-its-window-req-omn-001
		 */
		shown() {
			return visibleNotices(this.notices, this.closed, Date.now())
		},

		/**
		 * @return {{notice: string, close: string, more: string}} The labels in the site's language.
		 *
		 * @spec openspec/specs/portal-notices/spec.md#requirement-a-visitor-can-close-a-notice-for-the-visit-req-omn-002
		 */
		label() {
			return LABELS[this.locale] || LABELS.nl
		},
	},

	methods: {
		/**
		 * Hide one notice for the rest of the visit.
		 *
		 * @param {string} id The notice id.
		 * @return {void}
		 *
		 * @spec openspec/specs/portal-notices/spec.md#requirement-a-visitor-can-close-a-notice-for-the-visit-req-omn-002
		 */
		close(id) {
			closeNotice(sessionStore(), id)
			this.closed = [...this.closed, id]
		},
	},
}
</script>

<style scoped>
.pq-site-notices .utrecht-alert {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	justify-content: space-between;
	gap: var(--utrecht-space-inline-md, 12px);
	margin-block: var(--utrecht-space-block-sm, 8px);
}
</style>
