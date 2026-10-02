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
	</dialog>
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
	max-width: min(32rem, calc(100vw - 32px));
	padding: 24px;
	border: none;
	background: var(--utrecht-document-background-color, Canvas);
	color: var(--utrecht-document-color, CanvasText);
}

.pq-idle-warning::backdrop {
	background: rgb(0 0 0 / 40%);
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
