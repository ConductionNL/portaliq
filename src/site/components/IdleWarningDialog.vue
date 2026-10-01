<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The warning before an inactivity sign-out on the public site
	(signin-session-idle-warning-and-sso T06, REQ-SIS-003). The same wording
	and the same rules as the portal SPA's IdleWarningDialog.jsx: a countdown
	on screen, the remaining time spoken once a minute, focus on the action
	that keeps the visitor signed in, and near the cap only "Sign in again".
-->
<template>
	<div class="pq-idle-backdrop">
		<section
			role="alertdialog"
			aria-modal="true"
			aria-labelledby="pq-idle-title"
			aria-describedby="pq-idle-body"
			class="pq-idle-warning"
			data-testid="site-idle-warning">
			<h2 id="pq-idle-title" class="utrecht-heading-2">
				{{ t('You will be signed out soon') }}
			</h2>
			<p id="pq-idle-body" class="utrecht-paragraph">
				{{ sentence(left) }}
			</p>
			<p class="pq-idle-spoken" aria-live="polite">
				{{ sentence(Math.ceil(Math.max(0, left) / 60) * 60) }}
			</p>
			<div class="pq-idle-buttons">
				<button
					v-if="extendable"
					ref="first"
					type="button"
					class="utrecht-button utrecht-button--primary-action"
					data-testid="site-idle-stay"
					@click="$emit('stay')">
					{{ t('Stay signed in') }}
				</button>
				<button
					v-if="extendable"
					type="button"
					class="utrecht-button utrecht-button--secondary-action"
					@click="$emit('signout')">
					{{ t('Sign out') }}
				</button>
				<button
					v-else
					ref="first"
					type="button"
					class="utrecht-button utrecht-button--primary-action"
					data-testid="site-idle-sign-in-again"
					@click="$emit('signout')">
					{{ t('Sign in again') }}
				</button>
			</div>
		</section>
	</div>
</template>

<script>
import en from '../../shared/i18n/en.json'
import nl from '../../shared/i18n/nl.json'
import { canExtend, remainingText } from '../../shared/idleSession.js'

const STRINGS = { en, nl }

/**
 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T06
 */
export default {
	name: 'IdleWarningDialog',

	props: {
		times: {
			type: Object,
			required: true,
		},

		locale: {
			type: String,
			default: 'nl',
		},
	},

	emits: ['stay', 'signout'],

	data() {
		return {
			left: this.times.expiresAt - Math.floor(Date.now() / 1000),
			tick: null,
		}
	},

	computed: {
		/**
		 * @return {boolean} Whether "Stay signed in" can still work.
		 *
		 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T06
		 */
		extendable() {
			return canExtend(this.times, Math.floor(Date.now() / 1000))
		},
	},

	/**
	 * Start the countdown and focus the first action.
	 *
	 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T06
	 */
	mounted() {
		this.tick = setInterval(() => {
			this.left = this.times.expiresAt - Math.floor(Date.now() / 1000)
		}, 1000)
		this.$nextTick(() => this.$refs.first?.focus())
	},

	/**
	 * Stop the countdown.
	 *
	 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T06
	 */
	beforeUnmount() {
		clearInterval(this.tick)
	},

	methods: {
		/**
		 * One string in the site's language.
		 *
		 * @param {string} key The English source string.
		 * @param {object} [vars] Placeholder values.
		 * @return {string} The string.
		 *
		 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T06
		 */
		t(key, vars = {}) {
			const strings = STRINGS[this.locale] || STRINGS.nl
			return (strings[key] || key).replace(/\{(\w+)\}/g, (_, name) =>
				String(vars[name] ?? ''),
			)
		},

		/**
		 * The warning sentence for a number of seconds left.
		 *
		 * @param {number} seconds Seconds left.
		 * @return {string} The sentence.
		 *
		 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T06
		 */
		sentence(seconds) {
			const time = remainingText(seconds, this.t)
			return this.extendable
				? this.t(
						'You will be signed out in {time} because you have been inactive.',
						{ time },
					)
				: this.t(
						'Your session ends in {time}. Sign in again to keep going.',
						{ time },
					)
		},
	},
}
</script>

<style scoped>
.pq-idle-backdrop {
	position: fixed;
	inset: 0;
	z-index: 1000;
	display: flex;
	align-items: center;
	justify-content: center;
	padding: 16px;
	background: rgb(0 0 0 / 40%);
}

.pq-idle-warning {
	max-width: 32rem;
	padding: 24px;
	background: var(--utrecht-document-background-color, Canvas);
	color: var(--utrecht-document-color, CanvasText);
}

.pq-idle-buttons {
	display: flex;
	flex-wrap: wrap;
	gap: 8px;
}

.pq-idle-spoken {
	position: absolute;
	width: 1px;
	height: 1px;
	overflow: hidden;
	clip-path: inset(50%);
	white-space: nowrap;
}
</style>
