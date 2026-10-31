/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * The name an author reads for a widget, in the palette and on the page
 * being edited alike. A plain module, so the editor, the palette and a node
 * test share one answer (resident-sees-words-not-codes).
 */

/**
 * Human labels for the public blocks, in the language the portal is authored
 * in. A key with no entry here falls back to the key itself rather than to
 * nothing: an unlabelled but placeable widget beats a widget that is missing.
 *
 * @type {Record<string, string>}
 */
const PUBLIC_LABELS = {
	markdown: 'Tekst',
	hero: 'Hero',
	search: 'Zoekbalk',
	section: 'Sectie',
	cardGrid: 'Kaartenraster',
	card: 'Kaart',
	emptyState: 'Lege staat',
	glossary: 'Begrippenlijst',
	contributions: 'Bijdragen',
	federatedSearch: 'Federatief zoeken',
	publicationDetail: 'Publicatiedetail',
	intakeCatalogue: 'Aanvragen per onderwerp',
	intakeForm: 'Aanvraagformulier',
	intakeStatus: 'Status van een aanvraag',
	contactForm: 'Vraagformulier',
	assistant: 'Vraag het de assistent',
	publicRecords: 'Openbare overzichten',
	report: 'Misstand melden',
	reportThread: 'Uw melding met meldcode',
	sharedBlock: 'Gedeeld blok',
	// The NL Design System blocks, named as the boards name them (PtPagina).
	nlAccordion: 'Uitklapblokken',
	nlActionGroup: 'Knoppengroep',
	nlAlert: 'Meldingsbalk',
	nlBanner: 'Banner',
	nlButtonLink: 'Knoplink',
	nlCalendar: 'Kalender',
	nlCases: 'Zaken',
	nlCatalogue: 'Catalogus',
	nlCodeBlock: 'Codeblok',
	nlDescriptionList: 'Gegevenslijst',
	nlDialog: 'Dialoog',
	nlDrawer: 'Uitschuifpaneel',
	nlEventList: 'Agenda',
	nlFaqList: 'Veelgestelde vragen',
	nlFeaturedSubjects: 'Uitgelichte onderwerpen',
	nlFigures: 'Cijfers',
	nlFileList: 'Bestanden',
	nlHeading: 'Kop',
	nlImage: 'Afbeelding',
	nlLanguageNav: 'Taalkeuze',
	nlLink: 'Link',
	nlLinkColumns: 'Linkkolommen',
	nlLinkList: 'Lijst met links',
	nlList: 'Lijst',
	nlLocationMap: 'Kaart met locatie',
	nlLookupForm: 'Opzoekformulier',
	nlNewsArticle: 'Nieuwsbericht',
	nlNewsList: 'Nieuwslijst',
	nlNote: 'Notitie',
	nlParagraph: 'Alinea',
	nlPortalCounts: 'Aantallen',
	nlProductFinder: 'Productzoeker',
	nlProgressBar: 'Voortgangsbalk',
	nlProgressCircle: 'Voortgangscirkel',
	nlPublicDetail: 'Openbaar detail',
	nlPublicTable: 'Openbare tabel',
	nlQuickTasks: 'Snelle taken',
	nlQuote: 'Citaat',
	nlSeparator: 'Scheidingslijn',
	nlSignIn: 'Inloggen',
	nlStartTiles: 'Starttegels',
	nlSteps: 'Stappen',
	nlSubjectLanding: 'Onderwerppagina',
	nlTable: 'Tabel',
	nlTabs: 'Tabbladen',
	nlTaskNav: 'Takenmenu',
	nlTasks: 'Taken',
	nlTimeline: 'Tijdlijn',
	nlToggletip: 'Uitleg',
	nlVideo: 'Video',
	nlYouTube: 'YouTube-video',
}

/**
 * Humanise a camelCase prop or widget key for a label.
 *
 * @param {string} name The name.
 * @return {string} The label.
 * @spec openspec/changes/resident-sees-words-not-codes/specs/portal-in-place-editing/spec.md#requirement-the-editor-names-a-block-by-its-widgets-name
 */
export function humanise(name) {
	const spaced = String(name)
		.replace(/([a-z0-9])([A-Z])/g, '$1 $2')
		.replace(/[-_]+/g, ' ')
		.trim()

	return spaced.charAt(0).toUpperCase() + spaced.slice(1)
}

/**
 * The name of a widget: the public block's label, else the name the shared
 * dashboard registry gives it, else its key written as words. Never the bare
 * key ("markdown") when a name exists.
 *
 * @param {string} key The widget key.
 * @param {Record<string, {displayName?: string}>} registry The dashboard widget registry.
 * @return {string} The name.
 * @spec openspec/changes/resident-sees-words-not-codes/specs/portal-in-place-editing/spec.md#requirement-the-editor-names-a-block-by-its-widgets-name
 */
export function widgetLabel(key, registry) {
	if (Object.hasOwn(PUBLIC_LABELS, key)) {
		return PUBLIC_LABELS[key]
	}
	const name = registry?.[key]?.displayName
	if (typeof name === 'string' && name.trim() !== '') {
		return name
	}
	return humanise(key)
}

/**
 * The width line under a block's name on its editor card: "over de hele
 * breedte" at the grid's full 12 columns, else "{n} kolommen" (PtPagina).
 *
 * @param {number} columns The block's width in grid columns.
 * @return {string} The line, or '' for a width that is not a positive number.
 * @spec openspec/specs/portal-page-designer/spec.md#requirement-the-editor-toolbar-and-block-cards-read-as-the-board
 */
export function widgetWidthLine(columns) {
	const width = Number(columns)
	if (!Number.isFinite(width) || width <= 0) {
		return ''
	}
	if (width >= 12) {
		return 'over de hele breedte'
	}
	return width === 1 ? '1 kolom' : `${width} kolommen`
}
