<?php

/**
 * The names an author reads for the public widgets, for the plain site page.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Cms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/site-honest-without-javascript/tasks.md#2.4
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

/**
 * The label of a widget, the way `src/lib/widgetLabels.js` names it.
 *
 * A copy of that file's `PUBLIC_LABELS` table, because the plain page is
 * rendered by the server and cannot import a browser module.
 * `tests/site-plain.spec.mjs` fails when the two tables differ. A key with no
 * entry reads as its key written as words, as `humanise()` there does.
 *
 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-the-server-renders-a-plain-version-of-every-public-page-req-shj-002
 */
class PlainWidgetLabels {

	/**
	 * The labels, keyed by widget key. Kept in step with `PUBLIC_LABELS`.
	 *
	 * @var array<string, string>
	 */
	public const LABELS = [
		'markdown'           => 'Tekst',
		'hero'               => 'Hero',
		'search'             => 'Zoekbalk',
		'section'            => 'Sectie',
		'cardGrid'           => 'Kaartenraster',
		'card'               => 'Kaart',
		'emptyState'         => 'Lege staat',
		'glossary'           => 'Begrippenlijst',
		'contributions'      => 'Bijdragen',
		'federatedSearch'    => 'Federatief zoeken',
		'publicationDetail'  => 'Publicatiedetail',
		'intakeCatalogue'    => 'Aanvragen per onderwerp',
		'intakeForm'         => 'Aanvraagformulier',
		'intakeStatus'       => 'Status van een aanvraag',
		'contactForm'        => 'Vraagformulier',
		'assistant'          => 'Vraag het de assistent',
		'publicRecords'      => 'Openbare overzichten',
		'report'             => 'Misstand melden',
		'reportThread'       => 'Uw melding met meldcode',
		'sharedBlock'        => 'Gedeeld blok',
		'nlAccordion'        => 'Uitklapblokken',
		'nlActionGroup'      => 'Knoppengroep',
		'nlAlert'            => 'Meldingsbalk',
		'nlBanner'           => 'Banner',
		'nlButtonLink'       => 'Knoplink',
		'nlCalendar'         => 'Kalender',
		'nlCases'            => 'Zaken',
		'nlCatalogue'        => 'Catalogus',
		'nlCodeBlock'        => 'Codeblok',
		'nlDescriptionList'  => 'Gegevenslijst',
		'nlDialog'           => 'Dialoog',
		'nlDrawer'           => 'Uitschuifpaneel',
		'nlEventList'        => 'Agenda',
		'nlFaqList'          => 'Veelgestelde vragen',
		'nlFeaturedSubjects' => 'Uitgelichte onderwerpen',
		'nlFigures'          => 'Cijfers',
		'nlFileList'         => 'Bestanden',
		'nlHeading'          => 'Kop',
		'nlImage'            => 'Afbeelding',
		'nlLanguageNav'      => 'Taalkeuze',
		'nlLink'             => 'Link',
		'nlLinkColumns'      => 'Linkkolommen',
		'nlLinkList'         => 'Lijst met links',
		'nlList'             => 'Lijst',
		'nlLocationMap'      => 'Kaart met locatie',
		'nlLookupForm'       => 'Opzoekformulier',
		'nlNewsArticle'      => 'Nieuwsbericht',
		'nlNewsList'         => 'Nieuwslijst',
		'nlNote'             => 'Notitie',
		'nlParagraph'        => 'Alinea',
		'nlPortalCounts'     => 'Aantallen',
		'nlProductFinder'    => 'Productzoeker',
		'nlProgressBar'      => 'Voortgangsbalk',
		'nlProgressCircle'   => 'Voortgangscirkel',
		'nlPublicDetail'     => 'Openbaar detail',
		'nlPublicTable'      => 'Openbare tabel',
		'nlQuickTasks'       => 'Snelle taken',
		'nlQuote'            => 'Citaat',
		'nlSeparator'        => 'Scheidingslijn',
		'nlSignIn'           => 'Inloggen',
		'nlStartTiles'       => 'Starttegels',
		'nlSteps'            => 'Stappen',
		'nlSubjectLanding'   => 'Onderwerppagina',
		'nlTable'            => 'Tabel',
		'nlTabs'             => 'Tabbladen',
		'nlTaskNav'          => 'Takenmenu',
		'nlTasks'            => 'Taken',
		'nlTimeline'         => 'Tijdlijn',
		'nlToggletip'        => 'Uitleg',
		'nlVideo'            => 'Video',
		'nlYouTube'          => 'YouTube-video',
	];

	/**
	 * Keys the site renders that `PUBLIC_LABELS` does not name, because the
	 * editor reads their name from the shared dashboard registry instead.
	 *
	 * @var array<string, string>
	 */
	public const SITE_ONLY_LABELS = [
		'form' => 'Formulier',
	];

	/**
	 * The label of one widget key.
	 *
	 * @param string $key The widget key.
	 *
	 * @return string The label, never ''.
	 *
	 * @spec openspec/changes/site-honest-without-javascript/tasks.md#2.4
	 */
	public function label(string $key): string {
		if (array_key_exists($key, self::LABELS) === true) {
			return self::LABELS[$key];
		}

		if (array_key_exists($key, self::SITE_ONLY_LABELS) === true) {
			return self::SITE_ONLY_LABELS[$key];
		}

		$spaced = trim((string)preg_replace(['/([a-z0-9])([A-Z])/', '/[-_]+/'], ['$1 $2', ' '], $key));
		if ($spaced === '') {
			return 'Blok';
		}

		return ucfirst($spaced);
	}//end label()
}//end class
