<?php

/**
 * Unit tests for EmailLinkSender: the queued work behind a request.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/sign-in-with-an-email-link/tasks.md#5
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity\EmailLink;

use OCA\Portaliq\Service\Identity\ClaimLock;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkAddress;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkEligibility;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkLimits;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkSender;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkSetting;
use OCA\Portaliq\Service\Identity\EmailLink\EmailLinkTokens;
use OCA\Portaliq\Service\Identity\PortalIdentityMailer;
use OCA\Portaliq\Tests\Unit\Service\Identity\PortalIdentityStoreTrait;
use OCP\IAppConfig;
use OCP\Lock\ILockingProvider;
use OCP\Security\RateLimiting\ILimiter;
use OCP\Security\RateLimiting\IRateLimitExceededException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A known address gets one link and one mail; anything else gets nothing.
 */
class EmailLinkSenderTest extends TestCase {
	use PortalIdentityStoreTrait;

	/**
	 * Mails the fake mailer was asked to send.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $mails = [];

	/**
	 * Messages logged, with their context.
	 *
	 * @var array<int, string>
	 */
	private array $logged = [];

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->seedRow('portal', ['slug' => 'academie', 'title' => 'Mijn academie', 'organisation' => 'academie', 'authentication' => ['modes' => ['eherkenning', 'email-link']]]);
		$this->seedRow('portalAccount', ['subjectRef' => 'email:tom', 'audience' => 'client', 'organisation' => 'academie', 'identityType' => 'email', 'status' => 'active', 'signInAddress' => 'tom@example.nl']);
	}//end setUp()

	/**
	 * @return void
	 */
	public function testWhileTheSwitchIsOffNothingIsSent(): void {
		$this->assertFalse($this->sender(enabled: false)->handle(portalSlug: 'academie', address: 'tom@example.nl', cookieHash: 'c'));
		$this->assertSame([], $this->mails);
		$this->assertSame([], $this->storedRows('portalEmailLink'));
	}//end testWhileTheSwitchIsOffNothingIsSent()

	/**
	 * @return void
	 */
	public function testAPortalThatDoesNotDeclareTheModeSendsNothing(): void {
		$this->seedRow('portal', ['slug' => 'gemeente', 'organisation' => 'academie', 'authentication' => ['modes' => ['digid']]]);

		$this->assertFalse($this->sender()->handle(portalSlug: 'gemeente', address: 'tom@example.nl', cookieHash: 'c'));
		$this->assertSame([], $this->mails);
	}//end testAPortalThatDoesNotDeclareTheModeSendsNothing()

	/**
	 * @return void
	 */
	public function testAnUnknownAddressGetsNoMailAndNoLink(): void {
		$this->assertFalse($this->sender()->handle(portalSlug: 'academie', address: 'nobody@example.nl', cookieHash: 'c'));
		$this->assertSame([], $this->mails);
		$this->assertSame([], $this->storedRows('portalEmailLink'));
	}//end testAnUnknownAddressGetsNoMailAndNoLink()

	/**
	 * @return void
	 */
	public function testOverThePortalCapNothingIsSent(): void {
		$this->assertFalse($this->sender(portalCapReached: true)->handle(portalSlug: 'academie', address: 'tom@example.nl', cookieHash: 'c'));
		$this->assertSame([], $this->mails);
	}//end testOverThePortalCapNothingIsSent()

	/**
	 * Tom asks for a link: one link stored, one mail to his sign-in address
	 * that names the address, and the log carries no address or token.
	 *
	 * @return void
	 */
	public function testAKnownAddressGetsOneLinkByMail(): void {
		$this->assertTrue($this->sender()->handle(portalSlug: 'academie', address: ' TOM@example.nl', cookieHash: 'c'));

		$this->assertCount(1, $this->storedRows('portalEmailLink'));
		$this->assertCount(1, $this->mails);
		$this->assertSame(PortalIdentityMailer::TEMPLATE_EMAIL_LINK, $this->mails[0]['template']);
		$this->assertSame('tom@example.nl', $this->mails[0]['email']);
		$this->assertSame(['address' => 'tom@example.nl'], $this->mails[0]['details']);
		$secret = (string)$this->mails[0]['secret'];
		foreach ($this->logged as $line) {
			$this->assertStringNotContainsString('tom@example.nl', $line);
			$this->assertStringNotContainsString($secret, $line);
		}
	}//end testAKnownAddressGetsOneLinkByMail()

	/**
	 * @param bool $enabled          The switch.
	 * @param bool $portalCapReached Whether the portal's hourly cap is reached.
	 *
	 * @return EmailLinkSender
	 */
	private function sender(bool $enabled = true, bool $portalCapReached = false): EmailLinkSender {
		$config = $this->createMock(IAppConfig::class);
		$config->method('getValueString')->willReturn($enabled ? '1' : '0');

		$limiter = $this->createMock(ILimiter::class);
		if ($portalCapReached === true) {
			$limiter->method('registerAnonRequest')->willThrowException($this->createMock(IRateLimitExceededException::class));
		}

		$mailer = $this->createMock(PortalIdentityMailer::class);
		$mailer->method('send')->willReturnCallback(
			function (string $template, string $email, string $secret, string $organisation, ?array $portal = null, array $details = []): bool {
				$this->mails[] = compact('template', 'email', 'secret', 'organisation', 'details');
				return true;
			}
		);

		$logger = $this->createMock(LoggerInterface::class);
		foreach (['info', 'warning'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []): void {
					$this->logged[] = $message . ' ' . json_encode($context);
				}
			);
		}

		$reader  = $this->fakeReader();
		$address = new EmailLinkAddress();
		$tokens  = new EmailLinkTokens($reader, $this->fakeWriter(), $this->fakeRandom48(), new ClaimLock($this->createMock(ILockingProvider::class), 0));

		return new EmailLinkSender($reader, new EmailLinkSetting($config), new EmailLinkEligibility($reader, $address), $tokens, new EmailLinkLimits($limiter), $address, $mailer, $logger);
	}//end sender()

	/**
	 * @return \OCP\Security\ISecureRandom
	 */
	private function fakeRandom48(): \OCP\Security\ISecureRandom {
		$random = $this->createMock(\OCP\Security\ISecureRandom::class);
		$random->method('generate')->willReturn(str_repeat('k7', 24));
		return $random;
	}//end fakeRandom48()
}//end class
