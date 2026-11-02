// SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
// SPDX-License-Identifier: EUPL-1.2

/**
 * Value lists the site names without asking the app that stores the record.
 *
 * This file is configuration, not code: one entry per field, keyed by the
 * name the field has in the stored record, and under it one entry per code
 * with its name in each language the site ships. `src/site/lib/facetLabels.js`
 * reads it and knows nothing about any one list (decision 182: a procedure is
 * configuration, the code that reads it is generic).
 *
 * `wooCategory` holds the 17 information categories of the Wet open overheid
 * (Woo, art. 3.3). A publication stores its category as the TOOI code
 * (`infocat014`), and the search facet answers with the same code. The list is
 * the statutory one, the same in every municipality, and it is the list
 * opencatalogi files publications under.
 *
 * To add a list: add a key named after the stored field. A code without an
 * entry reads as itself, and a language without a name reads in Dutch.
 */
export const VALUE_LISTS = {
	wooCategory: {
		infocat001: {
			nl: 'Wetten en algemeen verbindende voorschriften',
			en: 'Laws and generally binding regulations',
		},
		infocat002: {
			nl: 'Overige besluiten van algemene strekking',
			en: 'Other decisions of general scope',
		},
		infocat003: {
			nl: 'Ontwerpen van wet- en regelgeving met adviesaanvraag',
			en: 'Draft legislation sent out for advice',
		},
		infocat004: {
			nl: 'Organisatie en werkwijze',
			en: 'Organisation and working methods',
		},
		infocat005: { nl: 'Bereikbaarheidsgegevens', en: 'Contact details' },
		infocat006: {
			nl: 'Bij vertegenwoordigende organen ingekomen stukken',
			en: 'Documents received by representative bodies',
		},
		infocat007: {
			nl: 'Vergaderstukken Staten-Generaal',
			en: 'Meeting documents of the States General',
		},
		infocat008: {
			nl: 'Vergaderstukken decentrale overheden',
			en: 'Meeting documents of local and regional governments',
		},
		infocat009: {
			nl: "Agenda's en besluitenlijsten bestuurscolleges",
			en: 'Agendas and decision lists of executive boards',
		},
		infocat010: { nl: 'Adviezen', en: 'Advice' },
		infocat011: { nl: 'Convenanten', en: 'Covenants' },
		infocat012: {
			nl: 'Jaarplannen en jaarverslagen',
			en: 'Annual plans and annual reports',
		},
		infocat013: {
			nl: 'Subsidieverplichtingen anders dan met beschikking',
			en: 'Subsidy obligations other than by decision',
		},
		infocat014: {
			nl: 'Woo-verzoeken en -besluiten',
			en: 'Woo requests and decisions',
		},
		infocat015: { nl: 'Onderzoeksrapporten', en: 'Research reports' },
		infocat016: { nl: 'Beschikkingen', en: 'Individual decisions' },
		infocat017: { nl: 'Klachtoordelen', en: 'Complaint rulings' },
	},
}
