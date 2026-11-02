<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\ConfirmationMailKeys;
use PHPUnit\Framework\TestCase;

/**
 * contact-page-question-form-and-not-found T02: an action asks for a shipped
 * mail by name and names a whitelisted subject field; anything else is dropped.
 *
 * @spec openspec/changes/contact-page-question-form-and-not-found/tasks.md#t02
 */
class ConfirmationMailKeysTest extends TestCase {

	public function testAKnownTemplateAndAWhitelistedFieldAreKept(): void {
		$out = (new ConfirmationMailKeys())->normalise(
			['type' => 'create', 'confirmationMail' => 'contact-confirmation', 'topicField' => 'onderwerp'],
			['onderwerp', 'vraag']
		);

		$this->assertSame('contact-confirmation', $out['confirmationMail']);
		$this->assertSame('onderwerp', $out['topicField']);
	}//end testAKnownTemplateAndAWhitelistedFieldAreKept()

	public function testAnUnknownTemplateOrFieldIsDropped(): void {
		$out = (new ConfirmationMailKeys())->normalise(
			['type' => 'create', 'confirmationMail' => 'my-own-text', 'topicField' => 'elders'],
			['onderwerp']
		);

		$this->assertArrayNotHasKey('confirmationMail', $out);
		$this->assertArrayNotHasKey('topicField', $out);
	}//end testAnUnknownTemplateOrFieldIsDropped()

	public function testOnlyACreateActionMayAskForTheMail(): void {
		$out = (new ConfirmationMailKeys())->normalise(
			['type' => 'update', 'confirmationMail' => 'contact-confirmation', 'topicField' => 'onderwerp'],
			['onderwerp']
		);

		$this->assertArrayNotHasKey('confirmationMail', $out);
		$this->assertArrayNotHasKey('topicField', $out);
	}//end testOnlyACreateActionMayAskForTheMail()
}//end class
