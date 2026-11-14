<?php

/**
 * Tests for PortalRegisteredDetailsService's lookups without OpenRegister.
 *
 * PortalRegisteredDetailsServiceTest doubles OpenRegister's real provider
 * classes and skips where they are not loadable. The service only asks the
 * container for a provider and calls its lookup method by name, so these
 * tests hand it plain objects with that method: the reading, filtering and
 * refusals run in every environment.
 *
 * @category Test
 * @package  OCA\Portaliq\Tests\Unit\Service\Identity
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

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use OCA\Portaliq\Service\Identity\PortalAccountLookup;
use OCA\Portaliq\Service\Identity\PortalRegisteredDetailsService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Portaliq\Service\Identity\PortalRegisteredDetailsService
 * @uses   \OCA\Portaliq\Service\Identity\RegisteredDetailsShape
 *
 * @spec openspec/changes/identity-registered-details/specs/registered-details/spec.md
 */
class PortalRegisteredDetailsLookupTest extends TestCase {

	private const BRP = 'OCA\\OpenRegister\\Service\\Integration\\Providers\\BrpPersonProvider';

	private const KVK = 'OCA\\OpenRegister\\Service\\Integration\\Providers\\KvkProvider';

	/**
	 * A BSN that passes the eleven test.
	 */
	private const BSN = '999993653';

	/**
	 * Everything the logger was told.
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $logged = [];

	/**
	 * The identifiers each provider was asked for.
	 *
	 * @var array<int, string>
	 */
	private array $asked = [];

	public function testADigidAccountSeesItsShapedPersonRecordWithoutTheBsn(): void {
		$person = ['burgerservicenummer' => self::BSN, 'naam' => ['volledigeNaam' => 'Jan de Vries'], 'geboorte' => ['datum' => '1980-04-12']];
		$service = $this->service(account: ['identityType' => 'digid', 'identityRef' => ' ' . self::BSN . ' '], providers: [self::BRP => $this->provider(method: 'lookupByBsn', answer: ['results' => [$person]])]);

		$result = $service->forSubject('subject-1');

		$this->assertSame([self::BSN], $this->asked);
		$this->assertTrue($result['available']);
		$this->assertSame('person', $result['kind']);
		$this->assertSame('Jan de Vries', $result['person']['name']);
		$this->assertSame('1980-04-12', $result['person']['birthDate']);
		$this->assertStringNotContainsString(self::BSN, (string)json_encode($result));
		$this->assertSame([], $this->logged);
	}//end testADigidAccountSeesItsShapedPersonRecordWithoutTheBsn()

	public function testAPersonLookupWithoutARecordIsNotFound(): void {
		foreach ([['results' => []], ['results' => ['not a record']], []] as $answer) {
			$service = $this->service(account: ['identityType' => 'eidas', 'identityRef' => self::BSN], providers: [self::BRP => $this->provider(method: 'lookupByBsn', answer: $answer)]);

			$this->assertSame(['available' => false, 'reason' => 'not_found'], $service->forSubject('subject-1'), (string)json_encode($answer));
		}
	}//end testAPersonLookupWithoutARecordIsNotFound()

	public function testACompanyKeepsOnlyTheRowsOfItsOwnKvkNumber(): void {
		$rows = [
			['kvkNummer' => '87654321', 'naam' => 'Andere BV', 'type' => 'hoofdvestiging', 'vestigingsnummer' => '000099999999'],
			'not a row',
			['naam' => 'Bakkerij', 'type' => 'hoofdvestiging', 'vestigingsnummer' => '000012345678', 'rechtsvorm' => 'Eenmanszaak'],
			['kvkNummer' => '12345678', 'naam' => 'Filiaal', 'type' => 'nevenvestiging', 'vestigingsnummer' => '000087654321'],
		];
		$service = $this->service(account: ['identityType' => 'eherkenning', 'identityRef' => '12345678'], providers: [self::KVK => $this->provider(method: 'lookupByKvkNumber', answer: ['results' => $rows])]);

		$result = $service->forSubject('subject-1');

		$this->assertTrue($result['available']);
		$this->assertSame('company', $result['kind']);
		$this->assertSame('Bakkerij', $result['company']['tradeName']);
		$this->assertSame('Eenmanszaak', $result['company']['legalForm']);
		$this->assertSame(['000012345678', '000087654321'], array_column($result['company']['branches'], 'number'));
		$this->assertSame(['000012345678', '000087654321'], $service->companyBranchNumbers('12345678'));
	}//end testACompanyKeepsOnlyTheRowsOfItsOwnKvkNumber()

	public function testACompanyWithoutRowsOfItsOwnHasNoBranches(): void {
		$service = $this->service(account: ['identityType' => 'eherkenning', 'identityRef' => '12345678'], providers: [self::KVK => $this->provider(method: 'lookupByKvkNumber', answer: ['results' => [['kvkNummer' => '87654321']]])]);

		$this->assertSame(['available' => false, 'reason' => 'not_found'], $service->forSubject('subject-1'));
		$this->assertNull($service->companyBranches('12345678'));
		$this->assertNull($service->companyBranchNumbers('12345678'));
		$this->assertNull($service->companyBranches('1234567'));
		$this->assertSame(['12345678', '12345678', '12345678'], $this->asked, 'a malformed KvK number is never looked up');
	}//end testACompanyWithoutRowsOfItsOwnHasNoBranches()

	public function testAnUnavailableSourceIsLoggedByProviderAndCauseOnly(): void {
		$service = $this->service(account: ['identityType' => 'digid', 'identityRef' => self::BSN], providers: [self::BRP => $this->provider(method: 'lookupByBsn', answer: ['unavailable' => true, 'cause' => 'upstream_service_down'])]);

		$this->assertSame(['available' => false, 'reason' => 'source_unavailable'], $service->forSubject('subject-1'));
		$this->assertSame([['Portaliq: registered details cannot be read', ['provider' => 'BrpPersonProvider', 'cause' => 'upstream_service_down']]], $this->logged);
	}//end testAnUnavailableSourceIsLoggedByProviderAndCauseOnly()

	public function testAnUnavailableAnswerWithoutACauseSaysUnavailable(): void {
		$service = $this->service(account: [], providers: [self::KVK => $this->provider(method: 'lookupByKvkNumber', answer: ['unavailable' => true])]);

		$this->assertNull($service->companyBranches('12345678'));
		$this->assertSame([['Portaliq: registered details cannot be read', ['provider' => 'KvkProvider', 'cause' => 'unavailable']]], $this->logged);
	}//end testAnUnavailableAnswerWithoutACauseSaysUnavailable()

	public function testAProviderWithoutTheLookupIsAMissingProvider(): void {
		$service = $this->service(account: ['identityType' => 'eherkenning', 'identityRef' => '12345678'], providers: [self::KVK => $this->provider(method: 'lookupByBsn', answer: ['results' => []])]);

		$this->assertSame(['available' => false, 'reason' => 'source_unavailable'], $service->forSubject('subject-1'));
		$this->assertSame([], $this->asked);
		$this->assertSame([['Portaliq: registered details cannot be read', ['provider' => 'KvkProvider', 'cause' => 'provider_missing']]], $this->logged);
	}//end testAProviderWithoutTheLookupIsAMissingProvider()

	public function testARemovedOrMissingAccountIsNoAccount(): void {
		$removed = $this->service(account: ['status' => 'removed', 'identityType' => 'digid', 'identityRef' => self::BSN], providers: []);
		$missing = $this->service(account: null, providers: []);

		$this->assertSame(['available' => false, 'reason' => 'no_account'], $removed->forSubject('subject-1'));
		$this->assertSame(['available' => false, 'reason' => 'no_account'], $missing->forSubject('subject-1'));
		$this->assertSame(['available' => false, 'reason' => 'no_account'], $removed->forSubject(''));
	}//end testARemovedOrMissingAccountIsNoAccount()

	public function testOnlyAValidBsnOrKvkNumberOfTheRightKindIsLookedUp(): void {
		$providers = [
			self::BRP => $this->provider(method: 'lookupByBsn', answer: ['results' => []]),
			self::KVK => $this->provider(method: 'lookupByKvkNumber', answer: ['results' => []]),
		];

		foreach ([
			['identityType' => 'digid', 'identityRef' => '000000000'],
			['identityType' => 'digid', 'identityRef' => '12345678'],
			['identityType' => 'eherkenning', 'identityRef' => self::BSN],
			['identityType' => 'generic', 'identityRef' => self::BSN],
		] as $account) {
			$result = $this->service(account: $account, providers: $providers)->forSubject('subject-1');
			$this->assertSame(['available' => false, 'reason' => 'no_registration_identifier'], $result, (string)json_encode($account));
		}

		$this->assertSame([], $this->asked);
	}//end testOnlyAValidBsnOrKvkNumberOfTheRightKindIsLookedUp()

	/**
	 * The service over one account and a container holding the given providers.
	 *
	 * @param array<string, mixed>|null $account   The caller's account, or null for none.
	 * @param array<string, object>     $providers Class name => provider.
	 *
	 * @return PortalRegisteredDetailsService
	 */
	private function service(?array $account, array $providers): PortalRegisteredDetailsService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(static function (string $id) use ($providers): object {
			if (isset($providers[$id]) === false) {
				throw new RuntimeException('not installed: ' . $id);
			}

			return $providers[$id];
		});

		$accounts = $this->getMockBuilder(PortalAccountLookup::class)->disableOriginalConstructor()->onlyMethods(['bySubjectRef'])->getMock();
		$accounts->method('bySubjectRef')->willReturn($account);

		$this->logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(function (string $message, array $context = []): void {
			$this->logged[] = [$message, $context];
		});

		return new PortalRegisteredDetailsService($accounts, $container, $logger);
	}//end service()

	/**
	 * A provider with one lookup method that records what it was asked.
	 *
	 * @param string               $method The lookup method's name.
	 * @param array<string, mixed> $answer What it answers.
	 *
	 * @return object
	 */
	private function provider(string $method, array $answer): object {
		$asked = &$this->asked;
		if ($method === 'lookupByBsn') {
			return new class($answer, $asked) {
				/**
				 * @param array<string, mixed> $answer What it answers.
				 * @param array<int, string>   $asked  What it was asked.
				 */
				public function __construct(private array $answer, private array &$asked) {
				}

				/**
				 * @param string $bsn The BSN.
				 *
				 * @return array<string, mixed>
				 */
				public function lookupByBsn(string $bsn): array {
					$this->asked[] = $bsn;
					return $this->answer;
				}
			};
		}

		return new class($answer, $asked) {
			/**
			 * @param array<string, mixed> $answer What it answers.
			 * @param array<int, string>   $asked  What it was asked.
			 */
			public function __construct(private array $answer, private array &$asked) {
			}

			/**
			 * @param string $kvkNumber The KvK number.
			 *
			 * @return array<string, mixed>
			 */
			public function lookupByKvkNumber(string $kvkNumber): array {
				$this->asked[] = $kvkNumber;
				return $this->answer;
			}
		};
	}//end provider()
}//end class
