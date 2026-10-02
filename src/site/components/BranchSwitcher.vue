<!--
  - SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
  - SPDX-License-Identifier: EUPL-1.2
  -->

<!--
	The branch (vestiging) in the site header (site-reaches-portal-parity
	REQ-SRP-011, signin-eherkenning-branch REQ-SEB-001 and REQ-SEB-003),
	ported from the React portal's BranchSwitcher and the header line next to
	it.

	A business user signed in for the whole company with more than one branch
	gets a choice: the whole company, or one branch. A session the login
	restricted to a branch never asks; it shows its branch as text, and so
	does a whole-company session that has narrowed to one but has nothing else
	to choose. A refused choice says so and keeps the bearer it had.
-->
<template>
	<span
		v-if="offersChoice || inEffect || refused"
		class="pq-branch"
		data-testid="branch">
		<span
			v-if="offersChoice"
			class="pq-branch__choice"
			data-testid="branch-choice">
			<label for="pq-branch-choice" class="utrecht-form-label">{{
				t('Acting for branch')
			}}</label>
			<select
				id="pq-branch-choice"
				class="utrecht-select"
				:value="current"
				:disabled="busy"
				@change="choose($event.target.value)">
				<option
					v-for="option in options"
					:key="option.id || 'whole'"
					:value="option.id"
					:selected="option.id === current">
					{{ option.label }}
				</option>
			</select>
		</span>
		<span v-else-if="inEffect" data-testid="branch-in-effect">{{
			inEffect
		}}</span>
		<span
			v-if="refused"
			class="utrecht-paragraph pq-branch__refused"
			role="alert"
			data-testid="branch-refused">
			{{ t('That branch could not be chosen.') }}
		</span>
	</span>
</template>

<script>
import { branchInEffect, branchOptions } from '../../shared/branch.js'

export default {
	name: 'BranchSwitcher',

	props: {
		/** The translator `t(key, vars)`. */
		t: { type: Function, required: true },
		/** The portal API adapter (`fetchBranches`, `chooseBranch`). */
		api: { type: Object, required: true },
		/** The answer of GET /portal/api/session. */
		session: { type: Object, default: null },
		/**
		 * The company's branches, when the host already has them; the
		 * component then reads nothing itself.
		 */
		initialBranches: { type: Array, default: null },
		/** What to do once a branch is in effect; reloads the page by default. */
		reload: { type: Function, default: null },
	},

	data() {
		return {
			branches: this.initialBranches || [],
			refused: false,
			busy: false,
		}
	},

	computed: {
		/** @return {boolean} Whether the login restricted the session to one branch. */
		restricted() {
			return !this.session || this.session.branchRestricted === true
		},

		/** @return {boolean} Whether to offer the choice. */
		offersChoice() {
			return !this.restricted && this.branches.length > 1
		},

		/** @return {Array<{id: string, label: string}>} The options. */
		options() {
			return branchOptions(this.branches, this.t)
		},

		/** @return {string} The branch in effect, '' for the whole company. */
		current() {
			return this.session && typeof this.session.branch === 'string'
				? this.session.branch
				: ''
		},

		/** @return {string} The branch in effect as text, or ''. */
		inEffect() {
			return branchInEffect(this.session, this.t)
		},
	},

	watch: {
		restricted() {
			this.load()
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		/**
		 * Read the company's branches, for a session that may choose one.
		 *
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-business-user-must-be-able-to-choose-a-branch-req-srp-011
		 */
		async load() {
			if (this.initialBranches) {
				return
			}
			if (this.restricted) {
				this.branches = []
				return
			}
			const answer = await this.api.fetchBranches()
			this.branches =
				answer && !answer.restricted && Array.isArray(answer.branches)
					? answer.branches
					: []
		},

		/**
		 * Narrow the session to one branch, or widen it to the whole company.
		 * The new bearer names the branch, so every list is read again.
		 *
		 * @param {string} branch The branch number, or '' for the whole company.
		 * @return {Promise<void>}
		 *
		 * @spec openspec/changes/site-reaches-portal-parity/specs/site-portal-parity/spec.md#requirement-a-business-user-must-be-able-to-choose-a-branch-req-srp-011
		 */
		async choose(branch) {
			this.refused = false
			this.busy = true
			const answer = await this.api.chooseBranch(branch)
			this.busy = false
			if (!answer || !answer.ok) {
				this.refused = true
				return
			}
			if (this.reload) {
				this.reload()
				return
			}
			window.location.reload()
		},
	},
}
</script>

<style scoped>
.pq-branch,
.pq-branch__choice {
	display: inline-flex;
	flex-wrap: wrap;
	align-items: center;
	gap: var(--utrecht-space-inline-sm, 0.5rem);
}
</style>
