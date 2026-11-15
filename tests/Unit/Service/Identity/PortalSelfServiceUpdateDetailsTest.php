<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use OCA\Portaliq\Service\Identity\PortalSelfServiceService;
use OCA\Portaliq\Service\Identity\PortalAccountLookup;
use OCA\Portaliq\Service\PortalObjectWriter;
use PHPUnit\Framework\TestCase;

/**
 * Pins every outcome of PortalSelfServiceService::updateDetails(): which
 * calls are refused (null, nothing written, no secret minted) and exactly
 * what an allowed call writes and answers.
 *
 * @spec openspec/changes/portal-identity-and-the-organisations-cases/specs/portal-identity-and-the-organisations-cases/spec.md
 */
class PortalSelfServiceUpdateDetailsTest extends TestCase {
	use PortalIdentityStoreTrait;

	/**
	 * Fields written by updateObject, one entry per call.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	private array $writes = [];

	protected function setUp(): void {
		$this->rows   = [];
		$this->writes = [];

	}//end setUp()

	/**
	 * Refused calls answer null and write nothing.
	 *
	 * @return void
	 */
	public function testRefusedCallsWriteNothing(): void {
		$this->seedAccount();
		$service = $this->service();

		$this->assertNull($service->updateDetails(subjectRef: 'somebody-else', displayName: 'X'));
		$this->assertNull($service->updateDetails(subjectRef: 'subject-1'));
		$this->assertNull($service->updateDetails(subjectRef: 'subject-1', displayName: 'X', messageLanguage: 'not a tag'));
		$this->assertNull($service->updateDetails(subjectRef: 'subject-1', displayName: 'X', email: 'not-an-address'));

		$this->assertSame([], $this->writes);
		$this->assertSame('Ans de Vries', $this->account()['displayName']);

	}//end testRefusedCallsWriteNothing()

	/**
	 * A name, a channel and a language land at once, with no secret.
	 *
	 * @return void
	 */
	public function testNameChannelAndLanguageLandTogether(): void {
		$this->seedAccount();

		$answer = $this->service()->updateDetails(
			subjectRef: 'subject-1',
			displayName: 'Anna',
			emailNotifications: false,
			messageLanguage: 'nl-NL'
		);

		$this->assertSame(['updated' => true, 'confirmationToken' => ''], $answer);
		$this->assertSame(
			[['notificationChannels' => ['email' => false], 'messageLanguage' => 'nl-NL', 'displayName' => 'Anna']],
			$this->writes
		);

	}//end testNameChannelAndLanguageLandTogether()

	/**
	 * A cleared language is written as '', and nothing else is.
	 *
	 * @return void
	 */
	public function testAClearedLanguageIsWrittenAlone(): void {
		$this->seedAccount();

		$answer = $this->service()->updateDetails(subjectRef: 'subject-1', messageLanguage: '');

		$this->assertSame(['updated' => true, 'confirmationToken' => ''], $answer);
		$this->assertSame([['messageLanguage' => '']], $this->writes);

	}//end testAClearedLanguageIsWrittenAlone()

	/**
	 * A new address is parked beside the name, under a minted secret.
	 *
	 * @return void
	 */
	public function testANewAddressIsParkedBesideTheName(): void {
		$this->seedAccount();

		$answer = $this->service()->updateDetails(subjectRef: 'subject-1', displayName: 'Anna', email: 'nieuw@example.org');

		$this->assertSame(['updated' => true, 'confirmationToken' => 'secret-1'], $answer);
		$this->assertCount(1, $this->writes);
		$this->assertSame('Anna', $this->writes[0]['displayName']);
		$this->assertSame('nieuw@example.org', $this->writes[0]['pendingEmail']);
		$this->assertSame(['displayName', 'contactAddresses', 'pendingEmail', 'pendingEmailTokenHash', 'pendingEmailExpiresAt', 'pendingEmailMode'], array_keys($this->writes[0]));
		$this->assertSame('oud@example.org', $this->account()['email']);

	}//end testANewAddressIsParkedBesideTheName()

	/**
	 * A write the store refuses answers null.
	 *
	 * @return void
	 */
	public function testARefusedWriteAnswersNull(): void {
		$this->seedAccount();
		$writer = $this->getMockBuilder(PortalObjectWriter::class)
			->disableOriginalConstructor()
			->onlyMethods(['updateObject'])
			->getMock();
		$writer->expects($this->once())->method('updateObject')->willReturn(null);

		$this->assertNull($this->service($writer)->updateDetails(subjectRef: 'subject-1', displayName: 'Anna'));

	}//end testARefusedWriteAnswersNull()

	/**
	 * An account row without an id is never written.
	 *
	 * @return void
	 */
	public function testAnAccountWithoutAnIdIsNotWritten(): void {
		$this->seedAccount();
		unset($this->rows['uuid-1']['uuid']);

		$this->assertNull($this->service()->updateDetails(subjectRef: 'subject-1', displayName: 'Anna'));
		$this->assertSame([], $this->writes);

	}//end testAnAccountWithoutAnIdIsNotWritten()

	/**
	 * The account row as it now stands.
	 *
	 * @return array<string, mixed>
	 */
	private function account(): array {
		return $this->storedRows('portalAccount')[0];
	}//end account()

	/**
	 * Put one active account in the fake store.
	 *
	 * @return void
	 */
	private function seedAccount(): void {
		$this->seedRow('portalAccount', [
			'subjectRef' => 'subject-1',
			'organisation' => 'gemeente-x',
			'audience' => 'client',
			'displayName' => 'Ans de Vries',
			'email' => 'oud@example.org',
			'status' => 'active',
		]);
	}//end seedAccount()

	/**
	 * The service over the fake store, recording every update it writes.
	 *
	 * @param PortalObjectWriter|null $writer A writer to use instead of the fake one.
	 *
	 * @return PortalSelfServiceService
	 */
	private function service(?PortalObjectWriter $writer = null): PortalSelfServiceService {
		$accounts = $this->getMockBuilder(PortalAccountLookup::class)
			->disableOriginalConstructor()
			->onlyMethods(['bySubjectRef'])
			->getMock();
		$accounts->method('bySubjectRef')->willReturnCallback(
			function (string $subjectRef): ?array {
				foreach ($this->storedRows('portalAccount') as $row) {
					if (($row['subjectRef'] ?? '') === $subjectRef && ($row['status'] ?? '') !== 'removed') {
						return $row;
					}
				}

				return null;
			}
		);

		return new PortalSelfServiceService($accounts, $this->fakeReader(), $writer ?? $this->recordingWriter(), $this->fakeRandom());
	}//end service()

	/**
	 * The fake writer, recording the fields of every update.
	 *
	 * @return PortalObjectWriter
	 */
	private function recordingWriter(): PortalObjectWriter {
		$fake   = $this->fakeWriter();
		$writer = $this->getMockBuilder(PortalObjectWriter::class)
			->disableOriginalConstructor()
			->onlyMethods(['updateObject'])
			->getMock();
		$writer->method('updateObject')->willReturnCallback(
			function (string $register, string $schema, string $scopeField, string $subjectRef, string $organisation, string $id, array $data) use ($fake): ?array {
				$this->writes[] = $data;
				return $fake->updateObject($register, $schema, $scopeField, $subjectRef, $organisation, $id, $data);
			}
		);

		return $writer;
	}//end recordingWriter()

}//end class
