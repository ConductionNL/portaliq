<?php

/**
 * Tests for the mail template renderer (mail-templates-admin-screen).
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service\Mail
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/mail-templates-admin-screen/tasks.md#t02
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Mail;

use OCA\Portaliq\Service\Mail\MailTemplateRenderer;
use OCA\Portaliq\Service\PortalObjectReader;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Portaliq\Service\Mail\MailTemplateRenderer
 */
class MailTemplateRendererTest extends TestCase {
	/**
	 * A renderer over the given stored rows.
	 *
	 * @param array<int, array<string, mixed>> $rows The stored rows.
	 *
	 * @return MailTemplateRenderer
	 */
	private function renderer(array $rows): MailTemplateRenderer {
		$reader = $this->createMock(PortalObjectReader::class);
		$reader->method('readCollection')->willReturn($rows);

		return new MailTemplateRenderer($reader);
	}//end renderer()

	/**
	 * Without a stored text the default is sent as it is.
	 *
	 * @return void
	 */
	public function testDefaultWhenNothingIsStored(): void {
		$out = $this->renderer([])->render(portal: 'p', key: 'contact-confirmation', values: ['topic' => 'Afval'], subject: 'S', body: 'B');

		self::assertSame(['subject' => 'S', 'body' => 'B', 'overridden' => false], $out);
	}//end testDefaultWhenNothingIsStored()

	/**
	 * A stored active text replaces the default and its variables are filled.
	 *
	 * @return void
	 */
	public function testStoredTextOverridesAndFillsVariables(): void {
		$rows = [['templateKey' => 'contact-confirmation', 'active' => true, 'subject' => "Over {topic}\nnu", 'body' => 'Dank, {topic}, van {portal}.']];
		$out  = $this->renderer($rows)->render(portal: 'p', key: 'contact-confirmation', values: ['topic' => 'Afval', 'portal' => 'Gemeente'], subject: 'S', body: 'B');

		self::assertTrue($out['overridden']);
		self::assertSame('Over Afval nu', $out['subject']);
		self::assertSame('Dank, Afval, van Gemeente.', $out['body']);
	}//end testStoredTextOverridesAndFillsVariables()

	/**
	 * An inactive row, or one that uses a variable the kind does not declare, is ignored.
	 *
	 * @return void
	 */
	public function testInactiveAndUnknownVariableFallBackToDefault(): void {
		$inactive = [['templateKey' => 'invitation', 'active' => false, 'subject' => 'x', 'body' => 'y']];
		$unknown  = [['templateKey' => 'invitation', 'active' => true, 'subject' => 'x', 'body' => 'Hallo {email}']];

		self::assertFalse($this->renderer($inactive)->render(portal: 'p', key: 'invitation', values: [], subject: 'S', body: 'B')['overridden']);
		self::assertFalse($this->renderer($unknown)->render(portal: 'p', key: 'invitation', values: [], subject: 'S', body: 'B')['overridden']);
	}//end testInactiveAndUnknownVariableFallBackToDefault()

	/**
	 * Unknown variables are listed once; a value is never read as a variable.
	 *
	 * @return void
	 */
	public function testUnknownVariablesAndPreview(): void {
		$renderer = $this->renderer([]);

		self::assertSame(['email', 'name'], $renderer->unknownVariables('invitation', 'Hi {email}', '{portal} {name} {email}'));
		self::assertSame('Hi Gemeente Voorbeeld {email}', $renderer->preview(key: 'invitation', text: 'Hi {portal} {email}'));
		self::assertTrue($renderer->knows(key: 'form-confirmation'));
		self::assertFalse($renderer->knows(key: 'nope'));
	}//end testUnknownVariablesAndPreview()

	/**
	 * A value that itself looks like a variable is not expanded again.
	 *
	 * @return void
	 */
	public function testValuesAreNotExpandedTwice(): void {
		$rows = [['templateKey' => 'contact-confirmation', 'active' => true, 'subject' => 'S {topic}', 'body' => 'B {topic}']];
		$out  = $this->renderer($rows)->render(portal: 'p', key: 'contact-confirmation', values: ['topic' => '{portal}', 'portal' => 'X'], subject: 'a', body: 'b');

		self::assertSame('B {portal}', $out['body']);
	}//end testValuesAreNotExpandedTwice()
}//end class
