<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->

<!--
  NewsTargetCell: who a news item is for, in the News page's table
  (staff-news-screen T4). A news item's `target` is an object of references;
  this shows it as one line, "Whole school: De Wilgenboom" or
  "Groups: Groep 7, Groep 8", with the names from GET /api/news/audiences,
  fetched once per page load and shared by every row.

  The column widget `news-target` (src/App.vue cellWidgets, used by the News
  page's `target` column in src/manifest.json).

  @spec openspec/changes/staff-news-screen/tasks.md#T4
  @visual exclude one line of text in a table cell; its wording is pinned by tests/news-authoring.spec.mjs (audienceOf, audienceLine)
-->
<template>
	<span data-testid="news-target-cell">{{ line }}</span>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { audienceLine, createNewsApi } from '../lib/newsAuthoring.js'

/** The choices, fetched once and shared by every cell. */
let choices = null

/**
 * The school and group choices, fetched on first use.
 *
 * @return {Promise<{schools: Array, groups: Array}>}
 */
function loadChoices() {
	if (choices === null) {
		const api = createNewsApi({
			get: (url) => axios.get(url),
			post: (url, body) => axios.post(url, body),
			put: (url, body) => axios.put(url, body),
			generateUrl,
		})
		choices = api.audiences().catch(() => ({ schools: [], groups: [] }))
	}
	return choices
}

export default {
	name: 'NewsTargetCell',

	props: {
		/** The news item's `target`. */
		value: {
			type: [Object, String],
			default: null,
		},
	},

	data() {
		return { options: { schools: [], groups: [] } }
	},

	computed: {
		/**
		 * The item's audience as one line.
		 *
		 * @return {string}
		 * @spec openspec/changes/staff-news-screen/tasks.md#T4
		 */
		line() {
			return audienceLine({ target: this.value }, this.options, (text, vars) =>
				t('portaliq', text, vars),
			)
		},
	},

	async mounted() {
		this.options = await loadChoices()
	},
}
</script>
