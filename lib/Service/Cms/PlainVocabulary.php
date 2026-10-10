<?php

/**
 * Portaliq plain vocabulary
 *
 * The words the plain version of a site page copies from the site's own
 * tables: a widget's name and a Woo information category's name.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Cms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-the-server-renders-a-plain-version-of-every-public-page-req-shj-002
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

/**
 * Copies, not a second source: `tests/site-plain.spec.mjs` fails when a
 * widget the site registers has no name here, or a name here differs from
 * `src/lib/widgetLabels.js` (with the NL Design System widgets' own `meta.js`
 * labels), or a category differs from `src/site/lib/wooCategories.js`.
 *
 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-the-server-renders-a-plain-version-of-every-public-page-req-shj-002
 */
class PlainVocabulary {

	/**
	 * Every public widget key with the name an author reads for it.
	 */
	public const WIDGET_LABELS = [
		'markdown'           => 'Tekst',
		'siteNavigation'     => 'Site Navigation',
		'sharedBlock'        => 'Gedeeld blok',
		'contributions'      => 'Bijdragen',
		'federatedSearch'    => 'Federatief zoeken',
		'publicationDetail'  => 'Publicatiedetail',
		'form'               => 'Form',
		'intakeCatalogue'    => 'Aanvragen per onderwerp',
		'intakeForm'         => 'Aanvraagformulier',
		'contactForm'        => 'Vraagformulier',
		'assistant'          => 'Vraag het de assistent',
		'publicRecords'      => 'Openbare overzichten',
		'intakeStatus'       => 'Status van een aanvraag',
		'nlHeading'          => 'Kop',
		'nlParagraph'        => 'Tekst',
		'nlLink'             => 'Link',
		'nlLinkList'         => 'Lijst met links',
		'nlLinkColumns'      => 'Kolommen met links',
		'nlLookupForm'       => 'Opzoekformulier',
		'nlList'             => 'Opsomming',
		'nlQuote'            => 'Citaat',
		'nlButtonLink'       => 'Knop',
		'nlActionGroup'      => 'Groep knoppen',
		'nlDescriptionList'  => 'Gegevens op een rij',
		'nlImage'            => 'Afbeelding',
		'nlTable'            => 'Tabel',
		'nlSeparator'        => 'Scheidingslijn',
		'nlCodeBlock'        => 'Codeblok',
		'nlAccordion'        => 'Uitklapbare tekst',
		'nlVideo'            => 'Video',
		'nlYouTube'          => 'YouTube-video',
		'nlAlert'            => 'Melding',
		'nlNote'             => 'Kanttekening',
		'nlBanner'           => 'Meldingsbalk',
		'nlDialog'           => 'Venster',
		'nlDrawer'           => 'Zijpaneel',
		'nlProgressBar'      => 'Voortgangsbalk',
		'nlProgressCircle'   => 'Voortgangscirkel',
		'nlToggletip'        => 'Uitleg bij een woord',
		'nlLanguageNav'      => 'Taalkeuze',
		'nlSignIn'           => 'Inloggen',
		'nlTaskNav'          => 'Stappen om te doen',
		'nlTabs'             => 'Tabbladen',
		'nlQuickTasks'       => 'Direct regelen',
		'nlNewsList'         => 'Nieuws',
		'nlNewsArticle'      => 'Nieuwsbericht',
		'nlEventList'        => 'Agenda',
		'nlCatalogue'        => 'Zoeken in het aanbod',
		'nlPublicTable'      => 'Tabel uit een app',
		'nlPublicDetail'     => 'Pagina van een item uit een app',
		'nlFaqList'          => 'Veelgestelde vragen',
		'nlProductFinder'    => 'Productzoeker',
		'nlFeaturedSubjects' => 'Uitgelichte onderwerpen',
		'nlPortalCounts'     => 'Wat we publiceren, in aantallen',
		'nlSubjectLanding'   => 'Pagina van een onderwerp',
		'nlStartTiles'       => 'Starttegels',
		'hero'               => 'Hero',
		'search'             => 'Zoekbalk',
		'section'            => 'Sectie',
		'cardGrid'           => 'Kaartenraster',
		'card'               => 'Kaart',
		'emptyState'         => 'Lege staat',
		'glossary'           => 'Begrippenlijst',
	];

	/**
	 * The 17 Woo information categories (Woo art. 3.3): Dutch, English.
	 */
	public const WOO_CATEGORIES = [
		'infocat001' => ['Wetten en algemeen verbindende voorschriften', 'Laws and generally binding regulations'],
		'infocat002' => ['Overige besluiten van algemene strekking', 'Other decisions of general scope'],
		'infocat003' => ['Ontwerpen van wet- en regelgeving met adviesaanvraag', 'Draft legislation sent out for advice'],
		'infocat004' => ['Organisatie en werkwijze', 'Organisation and working methods'],
		'infocat005' => ['Bereikbaarheidsgegevens', 'Contact details'],
		'infocat006' => ['Bij vertegenwoordigende organen ingekomen stukken', 'Documents received by representative bodies'],
		'infocat007' => ['Vergaderstukken Staten-Generaal', 'Meeting documents of the States General'],
		'infocat008' => ['Vergaderstukken decentrale overheden', 'Meeting documents of local and regional governments'],
		'infocat009' => ['Agenda\'s en besluitenlijsten bestuurscolleges', 'Agendas and decision lists of executive boards'],
		'infocat010' => ['Adviezen', 'Advice'],
		'infocat011' => ['Convenanten', 'Covenants'],
		'infocat012' => ['Jaarplannen en jaarverslagen', 'Annual plans and annual reports'],
		'infocat013' => ['Subsidieverplichtingen anders dan met beschikking', 'Subsidy obligations other than by decision'],
		'infocat014' => ['Woo-verzoeken en -besluiten', 'Woo requests and decisions'],
		'infocat015' => ['Onderzoeksrapporten', 'Research reports'],
		'infocat016' => ['Beschikkingen', 'Individual decisions'],
		'infocat017' => ['Klachtoordelen', 'Complaint rulings'],
	];

	/**
	 * A widget's name; the key itself for a key without one.
	 *
	 * @param string $key The widget key.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-the-server-renders-a-plain-version-of-every-public-page-req-shj-002
	 */
	public function widgetLabel(string $key): string {
		return (self::WIDGET_LABELS[$key] ?? $key);
	}//end widgetLabel()

	/**
	 * A Woo category's name in the document language; the code itself for an
	 * unknown code.
	 *
	 * @param string $code   The TOOI code, e.g. `infocat014`.
	 * @param string $locale The document language.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-a-publication-can-be-read-without-javascript-req-shj-004
	 */
	public function wooCategory(string $code, string $locale): string {
		if (isset(self::WOO_CATEGORIES[$code]) === false) {
			return $code;
		}

		if (str_starts_with($locale, 'en') === true) {
			return self::WOO_CATEGORIES[$code][1];
		}

		return self::WOO_CATEGORIES[$code][0];
	}//end wooCategory()
}//end class
