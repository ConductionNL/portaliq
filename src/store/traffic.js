// SPDX-License-Identifier: EUPL-1.2
// SPDX-FileCopyrightText: 2026 Conduction B.V.
//
// The Traffic page's shared state (portal-traffic-analytics): which portal
// is selected and the daily rollups for it. Five widgets read this one
// store so the selector on the first of them drives the other four, and
// the records are fetched once, not five times.
//
// Everything is read through OpenRegister's object API via the shared
// object store (ADR-022): the rollups are ordinary objects, so the
// reporting API and the export come for free and this app adds no
// endpoint.

import { useObjectStore } from '@conduction/nextcloud-vue'
import { defineStore } from 'pinia'
import { lastDays, summarise } from '../lib/trafficSummary.js'

/**
 * The register every Portaliq schema lives in.
 */
const REGISTER = 'portaliq'

/**
 * Days the page covers.
 */
const DAYS = 30

export const useTrafficReportStore = defineStore('portaliq-traffic-report', {
	state: () => ({
		portals: [],
		portalSlug: '',
		records: [],
		loadingPortals: false,
		loadingRecords: false,
		error: '',
	}),

	getters: {
		/**
		 * The selected portal object, or null.
		 *
		 * @param {object} state The state.
		 * @return {object|null} The portal.
		 */
		portal: (state) =>
			state.portals.find((p) => p && p.slug === state.portalSlug) || null,

		/**
		 * The dates the page covers, oldest first.
		 *
		 * @return {Array<string>} YYYY-MM-DD.
		 */
		dates: () => lastDays(DAYS, new Date()),

		/**
		 * The folded summary of the loaded records.
		 *
		 * @return {object} See `summarise`.
		 */
		summary() {
			return summarise(this.records, this.dates)
		},
	},

	actions: {
		/**
		 * Point the shared object store at the two schemas, once.
		 *
		 * @return {object} The shared object store.
		 */
		objects() {
			const store = useObjectStore()
			if (!store.objectTypeRegistry || !store.objectTypeRegistry.portal) {
				store.registerObjectType('portal', 'portal', REGISTER)
			}
			if (
				!store.objectTypeRegistry
				|| !store.objectTypeRegistry.portalTrafficDaily
			) {
				store.registerObjectType(
					'portalTrafficDaily',
					'portalTrafficDaily',
					REGISTER,
				)
			}
			return store
		},

		/**
		 * Load the portals and select the first measured one (else the
		 * first), then its records.
		 *
		 * @return {Promise<void>}
		 */
		async load() {
			this.loadingPortals = true
			this.error = ''
			try {
				const rows = await this.objects().fetchCollection('portal', {
					_limit: 200,
				})
				this.portals = (rows || []).filter(
					(p) => p && typeof p.slug === 'string',
				)
				if (!this.portal) {
					const measured = this.portals.find(
						(p) => p.traffic && p.traffic.enabled === true,
					)
					this.portalSlug = (measured || this.portals[0] || {}).slug || ''
				}
			} catch (error) {
				this.error = String((error && error.message) || error)
			} finally {
				this.loadingPortals = false
			}
			await this.loadRecords()
		},

		/**
		 * Select a portal and load its records.
		 *
		 * @param {string} slug The portal slug.
		 * @return {Promise<void>}
		 */
		async select(slug) {
			if (slug === this.portalSlug) {
				return
			}
			this.portalSlug = slug
			await this.loadRecords()
		},

		/**
		 * Load the selected portal's daily records for the range.
		 *
		 * The filter is by portal; the date window is applied here, so the
		 * request needs no operator syntax the object API might spell
		 * differently across versions. A year of records is under four
		 * hundred rows.
		 *
		 * @return {Promise<void>}
		 */
		async loadRecords() {
			this.records = []
			if (!this.portalSlug) {
				return
			}
			this.loadingRecords = true
			this.error = ''
			try {
				const rows = await this.objects().fetchCollection(
					'portalTrafficDaily',
					{
						portal: this.portalSlug,
						_limit: 400,
					},
				)
				const from = this.dates[0]
				this.records = (rows || []).filter(
					(r) =>
						r
						&& r.portal === this.portalSlug
						&& typeof r.date === 'string'
						&& r.date >= from,
				)
			} catch (error) {
				this.error = String((error && error.message) || error)
			} finally {
				this.loadingRecords = false
			}
		},
	},
})
