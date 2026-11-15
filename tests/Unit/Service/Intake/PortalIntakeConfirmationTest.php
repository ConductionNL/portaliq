<?php

/**
 * PortalIntakeConfirmation: the confirmation mail of a submitted form, sent
 * only when the form asks for it and the answers hold an address.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Service\Intake
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

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Kernel\Contracts\FormConfirmationMailerInterface;
use OCA\Portaliq\Service\Intake\PortalIntakeConfirmation;
use PHPUnit\Framework\TestCase;

/**
 * The intake confirmation.
 */
class PortalIntakeConfirmationTest extends TestCase {
	/**
	 * A rendered form that asks for a confirmation mail.
	 */
	private const RENDER = [
		'formName' => 'Melding openbare ruimte',
		'settings' => ['confirmationMail' => true],
		'fields'   => [
			['name' => 'email', 'label' => 'E-mail', 'type' => 'email'],
			['name' => 'what', 'label' => 'Wat', 'type' => 'text'],
			['name' => 'bsn', 'label' => 'BSN', 'format' => 'bsn'],
		],
	];

	/**
	 * The answers of a resident.
	 */
	private const ANSWERS = ['email' => 'a@example.org', 'what' => 'Losse stoeptegel', 'bsn' => '123456782'];

	/**
	 * The mail goes to the address in the answers, with a summary that withholds the BSN.
	 *
	 * @return void
	 */
	public function testTheMailIsSentWithASafeSummary(): void {
		$mailer = $this->createMock(originalClassName: FormConfirmationMailerInterface::class);
		$mailer->expects($this->once())->method('addressIn')->with(self::RENDER['fields'], self::ANSWERS)->willReturn('a@example.org');
		$mailer->expects($this->once())->method('send')->with(
			'a@example.org',
			['slug' => 'mijn'],
			'M-2026-1',
			'Melding openbare ruimte',
			[['label' => 'E-mail', 'value' => 'a@example.org'], ['label' => 'Wat', 'value' => 'Losse stoeptegel']],
			['submitted' => '2026-11-14']
		)->willReturn(true);
		$sent = (new PortalIntakeConfirmation(confirmationMail: $mailer))->send(
			site: ['slug' => 'mijn'],
			render: self::RENDER,
			answers: self::ANSWERS,
			reference: 'M-2026-1',
			moments: ['submitted' => '2026-11-14']
		);
		$this->assertSame(expected: 'a@example.org', actual: $sent);
	}//end testTheMailIsSentWithASafeSummary()

	/**
	 * A form that does not ask for a confirmation mail sends none.
	 *
	 * @return void
	 */
	public function testNoMailWithoutTheSetting(): void {
		$mailer = $this->createMock(originalClassName: FormConfirmationMailerInterface::class);
		$mailer->expects($this->never())->method('addressIn');
		$mailer->expects($this->never())->method('send');
		$confirmation = new PortalIntakeConfirmation(confirmationMail: $mailer);
		foreach ([[], ['confirmationMail' => 'true'], ['confirmationMail' => false]] as $settings) {
			$render = ['settings' => $settings] + self::RENDER;
			$this->assertSame(expected: '', actual: $confirmation->send(site: [], render: $render, answers: self::ANSWERS, reference: 'M-1'));
		}
	}//end testNoMailWithoutTheSetting()

	/**
	 * Without a mailer, or without an address in the answers, nothing is sent.
	 *
	 * @return void
	 */
	public function testNoMailWithoutAMailerOrAnAddress(): void {
		$withoutMailer = new PortalIntakeConfirmation();
		$this->assertSame(expected: '', actual: $withoutMailer->send(site: [], render: self::RENDER, answers: self::ANSWERS, reference: 'M-1'));
		$mailer = $this->createMock(originalClassName: FormConfirmationMailerInterface::class);
		$mailer->method('addressIn')->willReturn('');
		$mailer->expects($this->never())->method('send');
		$this->assertSame(
			expected: '',
			actual: (new PortalIntakeConfirmation(confirmationMail: $mailer))->send(site: [], render: self::RENDER, answers: [], reference: 'M-1')
		);
	}//end testNoMailWithoutAMailerOrAnAddress()

	/**
	 * A mail that could not be sent reports no address.
	 *
	 * @return void
	 */
	public function testAFailedSendReportsNoAddress(): void {
		$mailer = $this->createMock(originalClassName: FormConfirmationMailerInterface::class);
		$mailer->method('addressIn')->willReturn('a@example.org');
		$mailer->expects($this->once())->method('send')->willReturn(false);
		$this->assertSame(
			expected: '',
			actual: (new PortalIntakeConfirmation(confirmationMail: $mailer))->send(site: [], render: self::RENDER, answers: self::ANSWERS, reference: 'M-1')
		);
	}//end testAFailedSendReportsNoAddress()
}//end class
