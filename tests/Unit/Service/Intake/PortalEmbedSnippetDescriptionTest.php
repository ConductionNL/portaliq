<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\PortalEmbedGuard;
use OCA\Portaliq\Service\Intake\PortalEmbedHeight;
use OCA\Portaliq\Service\Intake\PortalEmbedSnippetDescription;
use PHPUnit\Framework\TestCase;

/**
 * embedded-intake-form T02: the snippet, beside the origins, in plain language.
 *
 * 🔴 AN ORIGIN LIST IS A SECURITY DECISION IN A FORMAT NOBODY READS AS ONE.
 * `["https://www.gemeente.nl"]` beside a text box is a configuration field.
 * "Only www.gemeente.nl may show this form on its own pages" is a sentence an
 * administrator can check against what they meant.
 *
 * @spec openspec/changes/embedded-intake-form/specs/embedded-intake-form/spec.md
 */
class PortalEmbedSnippetDescriptionTest extends TestCase {

	private PortalEmbedSnippetDescription $description;

	/**
	 * Wire the description over the real guard and height.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->description = new PortalEmbedSnippetDescription(
			guard: new PortalEmbedGuard(),
			height: new PortalEmbedHeight()
		);
	}//end setUp()

	/**
	 * One allowed origin reads as a sentence, not a list.
	 *
	 * @return void
	 */
	public function testOneOriginReadsAsASentence(): void {
		$described = $this->description->describe(
			binding: ['allowedOrigins' => ['https://www.gemeente.nl'], 'route' => 'melding'],
			frameUrl: 'https://portaal.nl/embed'
		);

		$this->assertTrue($described['embeddable']);
		$this->assertSame('Only www.gemeente.nl may show this form on its own pages.', $described['originSentence']);
	}//end testOneOriginReadsAsASentence()

	/**
	 * 🔴 EVERY ORIGIN IS NAMED, NOT COUNTED. An administrator checking whether
	 * they accidentally allowed a test domain cannot check a count, and a list
	 * that collapses after two hides exactly the entry somebody added in a
	 * hurry and meant to remove.
	 *
	 * @return void
	 */
	public function testEveryOriginIsNamedRatherThanCounted(): void {
		$described = $this->description->describe(
			binding: [
				'allowedOrigins' => ['https://www.gemeente.nl', 'https://test.gemeente.nl', 'https://partner.nl'],
				'route' => 'melding',
			],
			frameUrl: 'https://portaal.nl/embed'
		);

		$this->assertStringContainsString('www.gemeente.nl', $described['originSentence']);
		$this->assertStringContainsString('test.gemeente.nl', $described['originSentence']);
		$this->assertStringContainsString('partner.nl', $described['originSentence']);
		$this->assertStringNotContainsString('3 ', $described['originSentence']);
	}//end testEveryOriginIsNamedRatherThanCounted()

	/**
	 * 🔴 AN EMPTY LIST IS EXPLAINED ON THE SCREEN WHERE THE SNIPPET IS COPIED.
	 * Serving nobody is the right default, but an administrator who pastes a
	 * snippet and sees a blank page should not have to read a CSP error in a
	 * browser console they have never opened.
	 *
	 * @return void
	 */
	public function testAnEmptyOriginListIsExplainedInWords(): void {
		$described = $this->description->describe(
			binding: ['allowedOrigins' => [], 'route' => 'melding'],
			frameUrl: 'https://portaal.nl/embed'
		);

		$this->assertFalse($described['embeddable']);
		$this->assertStringContainsString('cannot be put on another website', $described['originSentence']);
		$this->assertStringContainsString('would show nothing at all', $described['originSentence']);
	}//end testAnEmptyOriginListIsExplainedInWords()

	/**
	 * 🔴 AND NO SNIPPET IS OFFERED FOR A FORM THAT CANNOT BE FRAMED. There is
	 * nothing to copy that would work, and offering it invites an afternoon of
	 * debugging the host page.
	 *
	 * @return void
	 */
	public function testNoSnippetIsOfferedForAFormThatCannotBeFramed(): void {
		$described = $this->description->describe(binding: ['allowedOrigins' => []], frameUrl: 'https://portaal.nl/embed');

		$this->assertSame('', $described['snippet']);
		$this->assertSame('', $described['listener']);
	}//end testNoSnippetIsOfferedForAFormThatCannotBeFramed()

	/**
	 * The height sentence says what the optional half does, so a web team knows
	 * that pasting only the iframe is a supported choice rather than a mistake.
	 *
	 * @return void
	 */
	public function testTheHeightSentenceSaysWhatTheOptionalHalfDoes(): void {
		$described = $this->description->describe(
			binding: ['allowedOrigins' => ['https://www.gemeente.nl'], 'route' => 'melding'],
			frameUrl: 'https://portaal.nl/embed'
		);

		$this->assertStringContainsString((string)PortalEmbedHeight::MINIMUM_HEIGHT, $described['heightSentence']);
		$this->assertStringContainsString('optional', $described['heightSentence']);
		$this->assertStringContainsString('still works', $described['heightSentence']);
	}//end testTheHeightSentenceSaysWhatTheOptionalHalfDoes()

	/**
	 * With no frame url there is nothing to copy either, and it says so rather
	 * than offering an iframe pointing at nowhere.
	 *
	 * @return void
	 */
	public function testNoFrameUrlIsAlsoNotEmbeddable(): void {
		$described = $this->description->describe(
			binding: ['allowedOrigins' => ['https://www.gemeente.nl']],
			frameUrl: ''
		);

		$this->assertFalse($described['embeddable']);
		$this->assertSame('', $described['snippet']);
	}//end testNoFrameUrlIsAlsoNotEmbeddable()
}//end class
