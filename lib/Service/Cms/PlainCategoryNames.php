<?php

/**
 * The names of the Woo information categories, for the plain publication page.
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
 * @spec openspec/changes/site-honest-without-javascript/tasks.md#3.3
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

/**
 * The information category of a publication by name, the way
 * `src/site/lib/facetLabels.js` names it from `src/site/config/valueLists.js`.
 *
 * A copy of that file's `wooCategory` list, because the plain page is rendered
 * by the server. `tests/site-plain.spec.mjs` fails when the two differ. A code
 * without an entry reads as itself, and a language without a name in Dutch.
 *
 * @spec openspec/changes/site-honest-without-javascript/specs/site-without-javascript/spec.md#requirement-a-publication-can-be-read-without-javascript-req-shj-004
 */
class PlainCategoryNames {

	/**
	 * The statutory categories (Woo art. 3.3), keyed by TOOI code.
	 *
	 * @var array<string, array{nl: string, en: string}>
	 */
	public const WOO_CATEGORIES = [
		'infocat001' => ['nl' => 'Wetten en algemeen verbindende voorschriften', 'en' => 'Laws and generally binding regulations'],
		'infocat002' => ['nl' => 'Overige besluiten van algemene strekking', 'en' => 'Other decisions of general scope'],
		'infocat003' => ['nl' => 'Ontwerpen van wet- en regelgeving met adviesaanvraag', 'en' => 'Draft legislation sent out for advice'],
		'infocat004' => ['nl' => 'Organisatie en werkwijze', 'en' => 'Organisation and working methods'],
		'infocat005' => ['nl' => 'Bereikbaarheidsgegevens', 'en' => 'Contact details'],
		'infocat006' => ['nl' => 'Bij vertegenwoordigende organen ingekomen stukken', 'en' => 'Documents received by representative bodies'],
		'infocat007' => ['nl' => 'Vergaderstukken Staten-Generaal', 'en' => 'Meeting documents of the States General'],
		'infocat008' => ['nl' => 'Vergaderstukken decentrale overheden', 'en' => 'Meeting documents of local and regional governments'],
		'infocat009' => ['nl' => 'Agenda\'s en besluitenlijsten bestuurscolleges', 'en' => 'Agendas and decision lists of executive boards'],
		'infocat010' => ['nl' => 'Adviezen', 'en' => 'Advice'],
		'infocat011' => ['nl' => 'Convenanten', 'en' => 'Covenants'],
		'infocat012' => ['nl' => 'Jaarplannen en jaarverslagen', 'en' => 'Annual plans and annual reports'],
		'infocat013' => ['nl' => 'Subsidieverplichtingen anders dan met beschikking', 'en' => 'Subsidy obligations other than by decision'],
		'infocat014' => ['nl' => 'Woo-verzoeken en -besluiten', 'en' => 'Woo requests and decisions'],
		'infocat015' => ['nl' => 'Onderzoeksrapporten', 'en' => 'Research reports'],
		'infocat016' => ['nl' => 'Beschikkingen', 'en' => 'Individual decisions'],
		'infocat017' => ['nl' => 'Klachtoordelen', 'en' => 'Complaint rulings'],
	];

	/**
	 * The name of one category code in a language.
	 *
	 * @param string $code   The stored code, e.g. `infocat014`.
	 * @param string $locale The document language.
	 *
	 * @return string The name, or the code itself.
	 *
	 * @spec openspec/changes/site-honest-without-javascript/tasks.md#3.3
	 */
	public function name(string $code, string $locale): string {
		if (array_key_exists($code, self::WOO_CATEGORIES) === false) {
			return $code;
		}

		$names = self::WOO_CATEGORIES[$code];
		if (str_starts_with(strtolower($locale), 'en') === true) {
			return $names['en'];
		}

		return $names['nl'];
	}//end name()
}//end class
