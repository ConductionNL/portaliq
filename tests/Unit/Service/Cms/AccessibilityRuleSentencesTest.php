<?php

/**
 * AccessibilityRuleSentences: the plain sentence an accessibility statement
 * shows for a failing rule, in Dutch or English.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Service\Cms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Cms;

use OCA\Portaliq\Service\Cms\AccessibilityRuleSentences;
use PHPUnit\Framework\TestCase;

/**
 * The rule sentences.
 */
class AccessibilityRuleSentencesTest extends TestCase {
	/**
	 * A mapped rule reads in English for any English locale, in Dutch otherwise.
	 *
	 * @return void
	 */
	public function testAMappedRuleReadsInTheLocalesLanguage(): void {
		$sentences = new AccessibilityRuleSentences();
		$this->assertSame(expected: 'Een pagina heeft geen titel.', actual: $sentences->sentence(rule: 'document-title', locale: 'nl', fallback: 'x'));
		$this->assertSame(expected: 'A page has no title.', actual: $sentences->sentence(rule: 'document-title', locale: 'en', fallback: 'x'));
		$this->assertSame(expected: 'A page has no title.', actual: $sentences->sentence(rule: 'document-title', locale: 'EN_gb', fallback: 'x'));
		$this->assertSame(expected: 'Een pagina heeft geen titel.', actual: $sentences->sentence(rule: 'document-title', locale: 'de', fallback: 'x'));
		$this->assertSame(expected: 'Een pagina heeft geen titel.', actual: $sentences->sentence(rule: 'document-title', locale: '', fallback: 'x'));
	}//end testAMappedRuleReadsInTheLocalesLanguage()

	/**
	 * An unmapped rule falls back to the trimmed scanner text, then to its own id.
	 *
	 * @return void
	 */
	public function testAnUnmappedRuleFallsBack(): void {
		$sentences = new AccessibilityRuleSentences();
		$this->assertSame(
			expected: 'Ensure every video has captions',
			actual: $sentences->sentence(rule: 'video-caption', locale: 'en', fallback: '  Ensure every video has captions ')
		);
		$this->assertSame(expected: 'video-caption', actual: $sentences->sentence(rule: 'video-caption', locale: 'nl', fallback: "  \n"));
	}//end testAnUnmappedRuleFallsBack()

	/**
	 * Whether a rule has its own sentence is case sensitive, like the scanner's rule ids.
	 *
	 * @return void
	 */
	public function testIsMappedNamesTheKnownRules(): void {
		$sentences = new AccessibilityRuleSentences();
		foreach (['color-contrast', 'image-alt', 'label', 'meta-viewport', 'target-size'] as $rule) {
			$this->assertTrue(condition: $sentences->isMapped(rule: $rule), message: $rule);
		}

		foreach (['Color-Contrast', 'video-caption', '', ' label'] as $rule) {
			$this->assertFalse(condition: $sentences->isMapped(rule: $rule), message: $rule);
		}
	}//end testIsMappedNamesTheKnownRules()

	/**
	 * Every mapped rule has two different sentences that each end in a full stop.
	 *
	 * @return void
	 */
	public function testEveryMappedRuleHasTwoFullSentences(): void {
		$sentences = new AccessibilityRuleSentences();
		$rules     = ['color-contrast', 'link-in-text-block', 'image-alt', 'input-image-alt', 'label', 'select-name', 'link-name', 'button-name',
			'html-has-lang', 'html-lang-valid', 'document-title', 'page-has-heading-one', 'heading-order', 'empty-heading', 'landmark-one-main',
			'region', 'bypass', 'list', 'listitem', 'frame-title', 'duplicate-id-aria', 'aria-required-attr', 'aria-valid-attr-value',
			'aria-allowed-attr', 'nested-interactive', 'scrollable-region-focusable', 'target-size', 'meta-viewport',
		];
		foreach ($rules as $rule) {
			$dutch   = $sentences->sentence(rule: $rule, locale: 'nl', fallback: '');
			$english = $sentences->sentence(rule: $rule, locale: 'en', fallback: '');
			$this->assertNotSame(expected: $dutch, actual: $english, message: $rule);
			$this->assertStringEndsWith(suffix: '.', string: $dutch, message: $rule);
			$this->assertStringEndsWith(suffix: '.', string: $english, message: $rule);
		}
	}//end testEveryMappedRuleHasTwoFullSentences()
}//end class
