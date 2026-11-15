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
	A native modal dialog, as WithdrawCaseConfirm is: `showModal()` keeps Tab
	inside it and makes the page behind it inert while the countdown runs.
	Escape does not dismiss it; the visitor chooses one of its actions.
-->
<template>
	<dialog
		ref="dialog"
		role="alertdialog"
		aria-labelledby="pq-idle-title"
		aria-describedby="pq-idle-body"
		class="pq-idle-warning"
		data-testid="site-idle-warning"
		@cancel.prevent>
		<div class="pq-idle-warning__body">
			<h2 id="pq-idle-title" class="utrecht-heading-3">
				{{ t('You will be signed out soon') }}
			</h2>
			<div id="pq-idle-body">
				<p class="utrecht-paragraph">
					{{ sentence(left) }}
					<template v-if="extendable">
						{{ t('What you have not sent yet is lost then.') }}
					</template>
				</p>
			</div>
			<p
				class="pq-idle-warning__countdown"
				aria-hidden="true"
				data-testid="site-idle-countdown">
				<svg
					class="pq-idle-warning__hourglass"
					viewBox="0 0 16 16"
					focusable="false">
					<path
						d="M4 2h8M4 14h8M5 2c0 3 6 3 6 6s-6 3-6 6M11 2c0 3-6 3-6 6s6 3 6 6"
						fill="none"
						stroke="currentColor"
						stroke-width="1.5"
						stroke-linecap="round" />
				</svg>
				{{ t('{time} left', { time: countdown(left) }) }}
			</p>
			<p class="pq-idle-spoken" aria-live="polite">
				{{ sentence(Math.ceil(Math.max(0, left) / 60) * 60) }}
			</p>
		</div>
		<div class="pq-idle-buttons">
			<button
				v-if="extendable"
				type="button"
				class="utrecht-button utrecht-button--secondary-action"
				data-testid="site-idle-signout"
				@click="$emit('signout')">
				{{ t('Sign out') }}
			</button>
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
				v-else
				ref="first"
				type="button"
				class="utrecht-button utrecht-button--primary-action"
				data-testid="site-idle-sign-in-again"
				@click="$emit('signout')">
				{{ t('Sign in again') }}
			</button>
		</div>
	</dialog>
</template>

<script>
import nl from '../../shared/i18n/nl.json'
import { canExtend, countdownText, remainingText } from '../../shared/idleSession.js'

// English is the key itself (en.json maps every key to itself), so its bundle
// is empty and `t()` falls back to the key; see src/shared/i18n/index.js.
const STRINGS = { en: {}, nl }

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
	 * Open as a modal, start the countdown and focus the first action.
	 *
	 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T06
	 */
	mounted() {
		const dialog = this.$refs.dialog
		if (typeof dialog?.showModal === 'function') {
			dialog.showModal()
		} else {
			dialog?.setAttribute('open', '')
		}
		this.tick = setInterval(() => {
			this.left = this.times.expiresAt - Math.floor(Date.now() / 1000)
		}, 1000)
		this.$nextTick(() => this.$refs.first?.focus())
	},

	/**
	 * Stop the countdown and close the modal.
	 *
	 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T06
	 */
	beforeUnmount() {
		clearInterval(this.tick)
		if (
			this.$refs.dialog?.open
			&& typeof this.$refs.dialog.close === 'function'
		) {
			this.$refs.dialog.close()
		}
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
		 * The countdown on screen, to the second.
		 *
		 * @param {number} seconds Seconds left.
		 * @return {string} The time.
		 *
		 * @spec openspec/changes/archive/2026-09-30-signin-session-idle-warning-and-sso/tasks.md#T06
		 */
		countdown(seconds) {
			return countdownText(seconds, this.t)
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
.pq-idle-warning {
	background: var(--utrecht-document-background-color, Canvas);
	border: none;
	border-radius: var(--nldesign-website-border-radius-large, 8px);
	box-shadow: 0 8px 32px rgb(0 0 0 / 20%);
	color: var(--utrecht-document-color, CanvasText);
	max-width: min(34.375rem, calc(100vw - 32px));
	padding: 0;
}

.pq-idle-warning::backdrop {
	background: rgb(0 0 0 / 40%);
}

.pq-idle-warning__body {
	display: flex;
	flex-direction: column;
	gap: var(--utrecht-space-block-sm, 12px);
	padding: 24px 28px 20px;
}

.pq-idle-warning__body > * {
	margin: 0;
}

/* The countdown as a warning chip; screen readers hear the minute-by-minute
   sentence in the live region instead of every second. */
.pq-idle-warning__countdown {
	align-items: center;
	align-self: flex-start;
	background: var(
		--nldesign-component-status-badge-warning-background-color,
		#fff4de
	);
	border: 1px solid var(--nldesign-color-warning-border, #e8c77d);
	border-radius: var(--nldesign-website-border-radius, 4px);
	color: var(--nldesign-component-status-badge-warning-color, #7a4f00);
	display: inline-flex;
	font-weight: var(--utrecht-typography-weight-scale-bold, 600);
	gap: 8px;
	padding: 8px 14px;
}

.pq-idle-warning__hourglass {
	block-size: 1em;
	inline-size: 1em;
}

/* The actions in a footer under a rule, the one that keeps the visitor
   signed in last. */
.pq-idle-buttons {
	border-block-start: 1px solid var(--nldesign-color-border, #d3d8df);
	display: flex;
	flex-wrap: wrap;
	gap: 12px;
	justify-content: flex-end;
	padding: 20px 28px 24px;
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
