<?php

/**
 * The plain-language sentence per axe-core rule that the accessibility
 * statement lists as a known issue (site-accessibility-statement).
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
 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-each-portal-publishes-a-statement-in-the-national-model-req-sas-002
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

/**
 * One table, Dutch and English side by side, so the two languages cannot
 * drift apart. A rule that is not in the table falls back to axe-core's own
 * help text, with its link.
 *
 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-each-portal-publishes-a-statement-in-the-national-model-req-sas-002
 */
class AccessibilityRuleSentences {

	/**
	 * Rule id => [nl, en].
	 */
	private const SENTENCES = [
		'color-contrast' => [
			'Sommige tekst heeft te weinig contrast met de achtergrond.',
			'Some text has too little contrast with its background.',
		],
		'link-in-text-block' => [
			'Sommige links in een tekst zijn alleen aan hun kleur te herkennen.',
			'Some links in a text can only be told apart by their colour.',
		],
		'image-alt' => [
			'Sommige afbeeldingen hebben geen tekstalternatief.',
			'Some images have no text alternative.',
		],
		'input-image-alt' => [
			'Sommige knoppen met een afbeelding hebben geen tekstalternatief.',
			'Some image buttons have no text alternative.',
		],
		'label' => [
			'Sommige formuliervelden hebben geen label.',
			'Some form fields have no label.',
		],
		'select-name' => [
			'Sommige keuzelijsten hebben geen naam.',
			'Some drop-down lists have no name.',
		],
		'link-name' => [
			'Sommige links hebben geen tekst die zegt waar ze heen gaan.',
			'Some links have no text that says where they go.',
		],
		'button-name' => [
			'Sommige knoppen hebben geen naam.',
			'Some buttons have no name.',
		],
		'html-has-lang' => [
			'Een pagina zegt niet in welke taal hij geschreven is.',
			'A page does not say which language it is written in.',
		],
		'html-lang-valid' => [
			'Een pagina noemt een taal die niet bestaat.',
			'A page names a language that does not exist.',
		],
		'document-title' => [
			'Een pagina heeft geen titel.',
			'A page has no title.',
		],
		'page-has-heading-one' => [
			'Een pagina heeft geen hoofdkop.',
			'A page has no main heading.',
		],
		'heading-order' => [
			'Op sommige pagina\'s slaan de koppen een niveau over.',
			'On some pages the headings skip a level.',
		],
		'empty-heading' => [
			'Sommige koppen zijn leeg.',
			'Some headings are empty.',
		],
		'landmark-one-main' => [
			'Een pagina heeft geen hoofdgebied.',
			'A page has no main area.',
		],
		'region' => [
			'Sommige inhoud staat buiten de herkenbare gebieden van de pagina.',
			'Some content sits outside the recognisable areas of the page.',
		],
		'bypass' => [
			'Een pagina biedt geen manier om de navigatie over te slaan.',
			'A page offers no way to skip past the navigation.',
		],
		'list' => [
			'Sommige lijsten zijn niet goed opgebouwd.',
			'Some lists are not built correctly.',
		],
		'listitem' => [
			'Sommige lijstonderdelen staan niet in een lijst.',
			'Some list items are not inside a list.',
		],
		'frame-title' => [
			'Sommige ingebedde onderdelen hebben geen titel.',
			'Some embedded parts have no title.',
		],
		'duplicate-id-aria' => [
			'Sommige elementen delen een naam die uniek moet zijn.',
			'Some elements share a name that must be unique.',
		],
		'aria-required-attr' => [
			'Sommige onderdelen missen informatie die hulpsoftware nodig heeft.',
			'Some parts lack information that assistive software needs.',
		],
		'aria-valid-attr-value' => [
			'Sommige onderdelen geven hulpsoftware een ongeldige waarde door.',
			'Some parts pass assistive software a value that is not valid.',
		],
		'aria-allowed-attr' => [
			'Sommige onderdelen geven hulpsoftware informatie die niet bij hun rol past.',
			'Some parts pass assistive software information that does not fit their role.',
		],
		'nested-interactive' => [
			'Sommige knoppen of links staan in een andere knop of link.',
			'Some buttons or links sit inside another button or link.',
		],
		'scrollable-region-focusable' => [
			'Sommige gebieden die schuiven zijn niet met het toetsenbord te bereiken.',
			'Some scrolling areas cannot be reached with the keyboard.',
		],
		'target-size' => [
			'Sommige knoppen of links zijn te klein om goed aan te raken.',
			'Some buttons or links are too small to tap reliably.',
		],
		'meta-viewport' => [
			'Een pagina verhindert inzoomen.',
			'A page prevents zooming in.',
		],
	];

	/**
	 * The sentence for a rule, in Dutch unless the locale is English.
	 *
	 * @param string $rule     The axe-core rule id.
	 * @param string $locale   The statement's language.
	 * @param string $fallback axe-core's own help text for the rule.
	 *
	 * @return string The sentence; axe-core's help text, else the rule id, when unmapped.
	 *
	 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-each-portal-publishes-a-statement-in-the-national-model-req-sas-002
	 */
	public function sentence(string $rule, string $locale, string $fallback): string {
		$pair = self::SENTENCES[$rule] ?? null;
		if ($pair === null) {
			$fallback = trim($fallback);
			if ($fallback === '') {
				return $rule;
			}

			return $fallback;
		}

		if (str_starts_with(strtolower($locale), 'en') === true) {
			return $pair[1];
		}

		return $pair[0];
	}//end sentence()

	/**
	 * Whether a rule has its own sentence.
	 *
	 * @param string $rule The axe-core rule id.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/site-accessibility-statement/specs/portaliq-cms/spec.md#requirement-each-portal-publishes-a-statement-in-the-national-model-req-sas-002
	 */
	public function isMapped(string $rule): bool {
		return isset(self::SENTENCES[$rule]);
	}//end isMapped()
}//end class
