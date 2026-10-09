<?php

/**
 * Portaliq Portal Email Verification Test
 *
 * @category Test
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
 *
 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t03
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\FormEmailCodeMailer;
use OCA\Portaliq\Service\Intake\PortalEmailVerification;
use OCP\ICache;
use OCP\ICacheFactory;
use OCP\IL10N;
use OCP\Security\ICrypto;
use OCP\Security\ISecureRandom;
use PHPUnit\Framework\TestCase;

/**
 * The code lives 15 minutes and 5 tries, a new one waits 60 seconds, an
 * address and a client are throttled, and a proof fits one address on one form.
 *
 * @spec openspec/changes/resident-identity-in-forms/tasks.md#t03
 */
class PortalEmailVerificationTest extends TestCase {

	/**
	 * The cache's contents.
	 *
	 * @var array<string, mixed>
	 */
	private array $held = [];

	/**
	 * The mails sent: address and code.
	 *
	 * @var array<int, array<string, string>>
	 */
	private array $mails = [];

	private bool $mailGoes = true;

	private bool $cacheUp = true;

	private string $nextCode = '123456';

	/**
	 * The service over an in-memory cache and a recording mailer.
	 *
	 * @return PortalEmailVerification
	 */
	private function service(): PortalEmailVerification {
		$cache = $this->createMock(ICache::class);
		$cache->method('get')->willReturnCallback(fn (string $key): mixed => ($this->held[$key] ?? null));
		$cache->method('set')->willReturnCallback(function (string $key, mixed $value): bool {
			$this->held[$key] = $value;
			return true;
		});
		$cache->method('remove')->willReturnCallback(function (string $key): bool {
			unset($this->held[$key]);
			return true;
		});
		$factory = $this->createMock(ICacheFactory::class);
		$factory->method('isAvailable')->willReturnCallback(fn (): bool => $this->cacheUp);
		$factory->method('createDistributed')->willReturn($cache);
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturnCallback(fn (): string => $this->nextCode);
		$crypto = $this->createMock(ICrypto::class);
		$crypto->method('calculateHMAC')->willReturnCallback(static fn (string $text): string => hash('sha256', 'secret'.$text));
		$mailer = $this->createMock(FormEmailCodeMailer::class);
		$mailer->method('send')->willReturnCallback(function (string $email, string $code): bool {
			$this->mails[] = ['email' => $email, 'code' => $code];
			return $this->mailGoes;
		});
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text): string => $text);

		return new PortalEmailVerification($factory, $random, $crypto, $mailer, $l10n);
	}//end service()

	/**
	 * A code is mailed to the address, kept only as a hash, and a right code answers a proof.
	 *
	 * @return void
	 */
	public function testARightCodeAnswersAProofAndTheCodeIsKeptOnlyAsAHash(): void {
		$service = $this->service();
		$site    = ['slug' => 'zuid'];
		$this->assertSame('sent', $service->request(site: $site, route: 'a', email: ' Sanne@Example.nl ', client: '10.0.0.1', now: 1000));
		$this->assertSame([['email' => 'sanne@example.nl', 'code' => '123456']], $this->mails);
		$this->assertStringNotContainsString('123456', json_encode($this->held));

		$result = $service->check(portal: 'zuid', route: 'a', email: 'sanne@example.nl', code: ' 123456 ', now: 1100);
		$this->assertTrue($result['ok']);
		$this->assertTrue($service->isVerified(portal: 'zuid', route: 'a', email: 'Sanne@example.nl', proof: $result['proof'], now: 1200));
		$this->assertFalse($service->check(portal: 'zuid', route: 'a', email: 'sanne@example.nl', code: '123456', now: 1101)['ok'], 'a code works once');
	}//end testARightCodeAnswersAProofAndTheCodeIsKeptOnlyAsAHash()

	/**
	 * A proof fits one address on one form of one portal, and lapses.
	 *
	 * @return void
	 */
	public function testAProofFitsOneAddressOnOneFormAndLapses(): void {
		$service = $this->service();
		$service->request(site: ['slug' => 'zuid'], route: 'a', email: 'sanne@example.nl', client: 'c', now: 1000);
		$proof = $service->check(portal: 'zuid', route: 'a', email: 'sanne@example.nl', code: '123456', now: 1001)['proof'];
		$this->assertFalse($service->isVerified(portal: 'zuid', route: 'a', email: 'ander@example.nl', proof: $proof, now: 1002));
		$this->assertFalse($service->isVerified(portal: 'zuid', route: 'b', email: 'sanne@example.nl', proof: $proof, now: 1002));
		$this->assertFalse($service->isVerified(portal: 'noord', route: 'a', email: 'sanne@example.nl', proof: $proof, now: 1002));
		$this->assertFalse($service->isVerified(portal: 'zuid', route: 'a', email: 'sanne@example.nl', proof: $proof, now: 1001 + 7201));
		$this->assertFalse($service->isVerified(portal: 'zuid', route: 'a', email: 'sanne@example.nl', proof: 'x.y', now: 1002));
		$this->assertFalse($service->isVerified(portal: 'zuid', route: 'a', email: 'sanne@example.nl', proof: '', now: 1002));
	}//end testAProofFitsOneAddressOnOneFormAndLapses()

	/**
	 * A code works 15 minutes and five tries; the fifth wrong try voids it.
	 *
	 * @return void
	 */
	public function testACodeLivesFifteenMinutesAndFiveTries(): void {
		$service = $this->service();
		$service->request(site: ['slug' => 'zuid'], route: 'a', email: 'sanne@example.nl', client: 'c', now: 1000);
		$this->assertSame('expired', $service->check(portal: 'zuid', route: 'a', email: 'sanne@example.nl', code: '123456', now: 1000 + 901)['error']);

		$this->held = [];
		$service->request(site: ['slug' => 'zuid'], route: 'a', email: 'sanne@example.nl', client: 'c', now: 5000);
		for ($i = 0; $i < 5; $i++) {
			$this->assertSame('wrong', $service->check(portal: 'zuid', route: 'a', email: 'sanne@example.nl', code: '000000', now: 5001)['error']);
		}

		$this->assertSame('too_many_tries', $service->check(portal: 'zuid', route: 'a', email: 'sanne@example.nl', code: '123456', now: 5002)['error']);
		$this->assertSame('expired', $service->check(portal: 'zuid', route: 'a', email: 'sanne@example.nl', code: '123456', now: 5003)['error'], 'the code is gone');
	}//end testACodeLivesFifteenMinutesAndFiveTries()

	/**
	 * A new code waits 60 seconds; an address gets five an hour and a client twenty.
	 *
	 * @return void
	 */
	public function testResendAndThrottle(): void {
		$service = $this->service();
		$site    = ['slug' => 'zuid'];
		$this->assertSame('sent', $service->request(site: $site, route: 'a', email: 'sanne@example.nl', client: 'c', now: 10000));
		$this->assertSame('wait', $service->request(site: $site, route: 'a', email: 'sanne@example.nl', client: 'c', now: 10059));
		$this->nextCode = '654321';
		$this->assertSame('sent', $service->request(site: $site, route: 'a', email: 'sanne@example.nl', client: 'c', now: 10060));
		$this->assertSame('654321', $this->mails[1]['code']);
		$this->assertSame('wrong', $service->check(portal: 'zuid', route: 'a', email: 'sanne@example.nl', code: '123456', now: 10061)['error'], 'the old code is replaced');

		for ($i = 1; $i <= 3; $i++) {
			$this->assertSame('sent', $service->request(site: $site, route: 'a', email: 'sanne@example.nl', client: 'c', now: 10060 + (100 * $i)));
		}

		$this->assertSame('throttled', $service->request(site: $site, route: 'a', email: 'sanne@example.nl', client: 'c', now: 10600), 'the sixth in the hour');

		$this->held = [];
		for ($i = 0; $i < 20; $i++) {
			$this->assertSame('sent', $service->request(site: $site, route: 'a', email: 'p'.$i.'@example.nl', client: 'one', now: 20000));
		}

		$this->assertSame('throttled', $service->request(site: $site, route: 'a', email: 'late@example.nl', client: 'one', now: 20000));
		$this->assertSame('sent', $service->request(site: $site, route: 'a', email: 'late@example.nl', client: 'other', now: 20000));
	}//end testResendAndThrottle()

	/**
	 * Bad addresses, a missing cache and a mail that did not go are told apart.
	 *
	 * @return void
	 */
	public function testRefusalsAreToldApart(): void {
		$service = $this->service();
		$this->assertSame('invalid', $service->request(site: ['slug' => 'z'], route: 'a', email: 'nope', client: 'c', now: 1));
		$this->mailGoes = false;
		$this->assertSame('failed', $service->request(site: ['slug' => 'z'], route: 'a', email: 'a@example.nl', client: 'c', now: 1));
		$this->assertSame([], $this->held === [] ? [] : array_filter(array_keys($this->held), static fn (string $key): bool => str_starts_with($key, 'code:')), 'a code that was not mailed is not kept');
		$this->cacheUp = false;
		$cold = $this->service();
		$this->assertSame('unavailable', $cold->request(site: ['slug' => 'z'], route: 'a', email: 'a@example.nl', client: 'c', now: 1));
		$this->assertSame('unavailable', $cold->check(portal: 'z', route: 'a', email: 'a@example.nl', code: '1', now: 1)['error']);
	}//end testRefusalsAreToldApart()

	/**
	 * Only e-mail fields that verify are checked, and an unanswered one is left to "required".
	 *
	 * @return void
	 */
	public function testOnlyVerifyFieldsNeedAProof(): void {
		$service = $this->service();
		$fields  = [
			['name' => 'mail', 'type' => 'email', 'verify' => true],
			['name' => 'other', 'type' => 'email'],
			['name' => 'empty', 'type' => 'email', 'verify' => true],
		];
		$errors = $service->unverified(fields: $fields, answers: ['mail' => 'a@example.nl', 'other' => 'b@example.nl'], proofs: [], portal: 'z', route: 'a');
		$this->assertSame(['mail'], array_keys($errors));
		$record = $service->record(fields: $fields, answers: ['mail' => 'A@example.nl', 'other' => 'b@example.nl'], verifiedAt: '2026-10-08T10:00:00+00:00');
		$this->assertSame([['address' => 'a@example.nl', 'verifiedAt' => '2026-10-08T10:00:00+00:00']], $record);
	}//end testOnlyVerifyFieldsNeedAProof()
}//end class
