<!-- SPDX-License-Identifier: EUPL-1.2 -->
<!-- Copyright (C) 2026 Conduction B.V. -->
<!--
  PortalDomains: "Domeinen", a card of a portal's Instellingen tab (screens
  PtPortalInstellingen). Every domain with its state, the end of its
  certificate, the TXT record a pending domain must publish, "Opnieuw
  controleren" and "Domein toevoegen". A domain that is not verified does not
  serve the portal.

  Custom because no library widget edits a list inside one object field and
  runs a server-side check per entry; the check itself is the existing
  /api/cms/domains endpoints.

  @spec openspec/changes/portal-domains-and-editors/specs/portal-settings/spec.md
-->
<template>
	<div class="portal-domains" data-testid="portal-domains">
		<p v-if="rows.length === 0" class="portal-domains__empty">
			{{ t('portaliq', 'No domain yet.') }}
		</p>
		<ul v-else class="portal-domains__list">
			<li v-for="row in rows" :key="row.hostname" class="portal-domains__row" :data-testid="`portal-domain-${row.hostname}`">
				<div class="portal-domains__main">
					<span class="portal-domains__host">{{ row.hostname }}</span>
					<span class="portal-domains__pill" :class="{ 'portal-domains__pill--ok': row.verified }">
						{{ row.verified ? t('portaliq', 'Verified') : t('portaliq', 'Waiting for verification') }}
					</span>
					<span class="portal-domains__cert">{{ certificateText(row) }}</span>
					<NcButton
						v-if="!row.verified"
						variant="tertiary"
						:disabled="busy === row.hostname"
						@click="check(row.hostname)">
						{{ t('portaliq', 'Check again') }}
					</NcButton>
				</div>
				<p v-if="!row.verified && row.name" class="portal-domains__record">
					{{ t('portaliq', 'Publish this TXT record:') }}
					<code>{{ row.name }}</code> = <code>{{ row.value }}</code>
				</p>
			</li>
		</ul>
		<p class="portal-domains__hint">
			{{ t('portaliq', 'A domain only works once it is verified.') }}
		</p>
		<form class="portal-domains__add" @submit.prevent="add">
			<NcTextField
				v-model="hostname"
				:label="t('portaliq', 'Domain')"
				:error="Boolean(error)"
				:helperText="error ? t('portaliq', error) : ''"
				data-testid="portal-domains-input" />
			<NcButton type="submit" variant="secondary" :disabled="busy === '*'" data-testid="portal-domains-add">
				{{ t('portaliq', 'Add domain') }}
			</NcButton>
		</form>
		<NcNoteCard v-if="notice" :type="noticeType">
			{{ notice }}
		</NcNoteCard>
	</div>
</template>

<script>
import axios from '@nextcloud/axios'
import { translate as t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import { NcButton, NcNoteCard, NcTextField } from '@nextcloud/vue'
import { certificateEnd, hostnameError, withDomain } from '../lib/portalDomains.js'

const DATE = { day: 'numeric', month: 'short', year: 'numeric' }

export default {
	name: 'PortalDomains',

	components: { NcButton, NcNoteCard, NcTextField },

	props: {
		/** The portal the detail page shows. */
		objectData: {
			type: Object,
			default: null,
		},
	},

	data() {
		return {
			records: null,
			hostname: '',
			error: '',
			busy: '',
			notice: '',
			noticeType: 'success',
		}
	},

	computed: {
		slug() {
			return this.objectData?.slug || ''
		},

		objectId() {
			return this.objectData?.['@self']?.id || this.objectData?.id || ''
		},

		/**
		 * The domains with their state: the server's records once loaded, the
		 * portal's own list before that.
		 *
		 * @return {Array<object>}
		 * @spec openspec/changes/portal-domains-and-editors/specs/portal-settings/spec.md
		 */
		rows() {
			if (this.records) {
				return this.records
			}
			return (Array.isArray(this.objectData?.domains) ? this.objectData.domains : [])
				.filter((d) => d && typeof d === 'object' && d.hostname)
		},
	},

	mounted() {
		this.load()
	},

	methods: {
		t,

		/**
		 * Read the records of the portal's domains.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/portal-domains-and-editors/specs/portal-settings/spec.md
		 */
		async load() {
			if (!this.slug) {
				return
			}
			try {
				const { data } = await axios.get(generateUrl('/apps/portaliq/api/cms/domains'), { params: { portal: this.slug } })
				this.records = Array.isArray(data?.domains) ? data.domains : null
			} catch {
				this.records = null
			}
		},

		/**
		 * How a domain's certificate reads.
		 *
		 * @param {object} row The domain.
		 * @return {string}
		 * @spec openspec/changes/portal-domains-and-editors/specs/portal-settings/spec.md
		 */
		certificateText(row) {
			const end = certificateEnd(row, (d) => d.toLocaleDateString('nl-NL', DATE))
			return end
				? t('portaliq', 'valid until {date}', { date: end })
				: t('portaliq', 'not checked yet')
		},

		/**
		 * Add the host name to the portal; it waits for verification.
		 *
		 * @return {Promise<void>}
		 * @spec openspec/changes/portal-domains-and-editors/specs/portal-settings/spec.md
		 */
		async add() {
			this.error = hostnameError(this.hostname, this.rows)
			if (this.error || !this.objectId) {
				return
			}
			this.busy = '*'
			this.notice = ''
			try {
				const url = generateUrl(`/apps/openregister/api/objects/portaliq/portal/${this.objectId}`)
				// Read the stored list now: the server keeps tokens and dates on it that this page never saw.
				const { data: stored } = await axios.get(url)
				await axios.patch(url, { domains: withDomain(stored.domains, this.hostname) })
				this.hostname = ''
				await this.load()
			} catch {
				this.noticeType = 'error'
				this.notice = t('portaliq', 'The domain could not be added. Try again.')
			} finally {
				this.busy = ''
			}
		},

		/**
		 * Run the check of one domain again.
		 *
		 * @param {string} hostname The domain.
		 * @return {Promise<void>}
		 * @spec openspec/changes/portal-domains-and-editors/specs/portal-settings/spec.md
		 */
		async check(hostname) {
			this.busy = hostname
			this.notice = ''
			try {
				const { data } = await axios.post(generateUrl('/apps/portaliq/api/cms/domains/verify'), { portal: this.slug, hostname })
				this.noticeType = data?.status === 'verified' ? 'success' : 'info'
				this.notice = data?.status === 'verified'
					? t('portaliq', '{hostname} is verified.', { hostname })
					: t('portaliq', 'The record of {hostname} is not there yet.', { hostname })
				await this.load()
			} catch {
				this.noticeType = 'error'
				this.notice = t('portaliq', 'The check could not run. Try again.')
			} finally {
				this.busy = ''
			}
		},
	},
}
</script>

<style scoped>
.portal-domains {
	display: flex;
	flex-direction: column;
	gap: 12px;
}
.portal-domains__list {
	list-style: none;
	margin: 0;
	padding: 0;
}
.portal-domains__row {
	padding: 8px 0;
	border-bottom: 1px solid var(--color-border);
}
.portal-domains__main {
	display: flex;
	flex-wrap: wrap;
	align-items: center;
	gap: 12px;
}
.portal-domains__host {
	font-weight: 600;
}
.portal-domains__pill {
	padding: 2px 10px;
	border-radius: 999px;
	background: var(--color-background-dark);
	font-size: 13px;
}
.portal-domains__pill--ok {
	background: var(--color-success);
	color: var(--color-success-text, #fff);
}
.portal-domains__cert,
.portal-domains__hint,
.portal-domains__empty {
	color: var(--color-text-maxcontrast);
	margin: 0;
}
.portal-domains__record {
	margin: 6px 0 0;
	word-break: break-all;
}
.portal-domains__add {
	display: flex;
	align-items: flex-end;
	gap: 12px;
}
</style>
