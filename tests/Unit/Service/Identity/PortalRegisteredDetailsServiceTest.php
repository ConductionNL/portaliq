<?php

/**
 * Tests for PortalRegisteredDetailsService (identity-registered-details T01, T02, T04).
 *
 * The providers are OpenRegister's REAL BrpPersonProvider and KvkProvider
 * classes, doubled on their real lookup methods, so a renamed or reshaped
 * method fails here instead of in production. Outside the container they
 * autoload from PORTALIQ_OPENREGISTER_LIB; without it those tests skip and
 * say why.
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

use OCA\Portaliq\Service\Identity\PortalRegisteredDetailsService;
use OCA\Portaliq\Service\PortalAccountService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

class PortalRegisteredDetailsServiceTest extends TestCase {

	private const BRP = 'OCA\\OpenRegister\\Service\\Integration\\Providers\\BrpPersonProvider';

	private const KVK = 'OCA\\OpenRegister\\Service\\Integration\\Providers\\KvkProvider';

	/**
	 * A BSN that passes the eleven test.
	 */
	private const BSN = '999993653';

	/**
	 * Everything the logger was told.
	 *
	 * @var array<int, array{message: string, context: array<string, mixed>}>
	 */
	private array $logged = [];

	public function testADigidAccountWithAValidBsnSeesItsPersonRecordAndNeverTheBsn(): void {
		$brp = $this->provider(self::BRP, 'lookupByBsn');
		$brp->expects($this->once())->method('lookupByBsn')->with(self::BSN)->willReturn([
			'results' => [$this->haalCentraalPerson()],
			'total' => 1,
			'meta' => ['correlationId' => 'c-1', 'durationMs' => 12, 'status' => 200],
		]);

		$result = $this->service(['identityType' => 'digid', 'identityRef' => self::BSN], [self::BRP => $brp])->forSubject('subject-1');

		$this->assertTrue($result['available']);
		$this->assertSame('person', $result['kind']);
		$this->assertSame('Jan de Vries', $result['person']['name']);
		$this->assertSame('1980-04-12', $result['person']['birthDate']);
		$this->assertSame(
			['street' => 'Dorpsstraat', 'number' => '12A', 'postcode' => '1234AB', 'city' => 'Utrecht'],
			$result['person']['address']
		);
		$encoded = (string)json_encode($result);
		$this->assertStringNotContainsString(self::BSN, $encoded);
		$this->assertStringNotContainsString('burgerservicenummer', $encoded);
		$this->assertStringNotContainsString('adresseerbaarObjectIdentificatie', $encoded);
		$this->assertArrayNotHasKey('meta', $result);

	}//end testADigidAccountWithAValidBsnSeesItsPersonRecordAndNeverTheBsn()

	public function testAnEidasAccountWithAValidBsnIsLookedUpAsAPersonToo(): void {
		$brp = $this->provider(self::BRP, 'lookupByBsn');
		$brp->expects($this->once())->method('lookupByBsn')->willReturn(['results' => [$this->haalCentraalPerson()], 'total' => 1]);

		$result = $this->service(['identityType' => 'eidas', 'identityRef' => self::BSN], [self::BRP => $brp])->forSubject('subject-1');

		$this->assertSame('person', $result['kind']);

	}//end testAnEidasAccountWithAValidBsnIsLookedUpAsAPersonToo()

	public function testAPseudonymIsNotLookedUpAndSaysWhy(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->expects($this->never())->method('get');

		$service = new PortalRegisteredDetailsService($this->accounts(['identityType' => 'digid', 'identityRef' => 'a1b2c3-pairwise']), $container, $this->logger());

		$this->assertSame(['available' => false, 'reason' => 'no_registration_identifier'], $service->forSubject('subject-1'));

	}//end testAPseudonymIsNotLookedUpAndSaysWhy()

	public function testNineDigitsThatFailTheElevenTestAreNotABsn(): void {
		$service = $this->service(['identityType' => 'digid', 'identityRef' => '123456789'], []);

		$this->assertSame('no_registration_identifier', $service->forSubject('subject-1')['reason']);

	}//end testNineDigitsThatFailTheElevenTestAreNotABsn()

	public function testGenericAndDevAccountsHaveNoRegistrationIdentifier(): void {
		foreach (['generic', 'dev', ''] as $type) {
			$result = $this->service(['identityType' => $type, 'identityRef' => self::BSN], [])->forSubject('subject-1');
			$this->assertSame('no_registration_identifier', $result['reason'], $type);
		}

	}//end testGenericAndDevAccountsHaveNoRegistrationIdentifier()

	public function testAnEherkenningAccountWithANonKvkReferenceIsNotLookedUp(): void {
		$result = $this->service(['identityType' => 'eherkenning', 'identityRef' => '1234567'], [])->forSubject('subject-1');

		$this->assertSame('no_registration_identifier', $result['reason']);

	}//end testAnEherkenningAccountWithANonKvkReferenceIsNotLookedUp()

	public function testNoAccountAnswersNoAccount(): void {
		$service = new PortalRegisteredDetailsService($this->accounts(null), $this->createMock(ContainerInterface::class), $this->logger());

		$this->assertSame(['available' => false, 'reason' => 'no_account'], $service->forSubject('subject-1'));
		$this->assertSame(['available' => false, 'reason' => 'no_account'], $service->forSubject(''));

	}//end testNoAccountAnswersNoAccount()

	public function testAnEherkenningAccountSeesItsCompanyWithEveryBranch(): void {
		$kvk = $this->provider(self::KVK, 'lookupByKvkNumber');
		$kvk->expects($this->once())->method('lookupByKvkNumber')->with('12345678')->willReturn([
			'results' => $this->kvkRows(),
			'total' => 3,
		]);

		$result = $this->service(['identityType' => 'eherkenning', 'identityRef' => '12345678'], [self::KVK => $kvk])->forSubject('subject-1');

		$this->assertTrue($result['available']);
		$this->assertSame('company', $result['kind']);
		$this->assertSame('Bakkerij de Korenschoof', $result['company']['tradeName']);
		$this->assertSame('12345678', $result['company']['kvkNumber']);
		$this->assertSame('Besloten Vennootschap', $result['company']['legalForm']);
		$this->assertSame(
			[
				['number' => '000012345678', 'name' => 'Bakkerij de Korenschoof', 'address' => 'Marktplein 1, 3511AB Utrecht', 'main' => true],
				['number' => '000087654321', 'name' => 'Korenschoof Zuid', 'address' => 'Laan 40, 3521CD Utrecht', 'main' => false],
			],
			$result['company']['branches']
		);

	}//end testAnEherkenningAccountSeesItsCompanyWithEveryBranch()

	public function testTheBranchNumbersOfACompanyAreReadForTheBranchChoice(): void {
		$kvk = $this->provider(self::KVK, 'lookupByKvkNumber');
		$kvk->method('lookupByKvkNumber')->willReturn(['results' => $this->kvkRows(), 'total' => 3]);

		$service = $this->service([], [self::KVK => $kvk]);

		$this->assertSame(['000012345678', '000087654321'], $service->companyBranchNumbers('12345678'));
		$this->assertNull($service->companyBranchNumbers('not-a-kvk'));

	}//end testTheBranchNumbersOfACompanyAreReadForTheBranchChoice()

	public function testAnUnavailableSourceSaysSoAndTheLogNeverHoldsTheBsn(): void {
		$brp = $this->provider(self::BRP, 'lookupByBsn');
		$brp->method('lookupByBsn')->willReturn(['unavailable' => true, 'cause' => 'upstream_service_down', 'results' => [], 'total' => 0]);

		$result = $this->service(['identityType' => 'digid', 'identityRef' => self::BSN], [self::BRP => $brp])->forSubject('subject-1');

		$this->assertSame(['available' => false, 'reason' => 'source_unavailable'], $result);
		$this->assertNotEmpty($this->logged);
		$this->assertStringNotContainsString(self::BSN, (string)json_encode($this->logged));
		$this->assertStringContainsString('upstream_service_down', (string)json_encode($this->logged));

	}//end testAnUnavailableSourceSaysSoAndTheLogNeverHoldsTheBsn()

	public function testAMissingProviderIsAnUnavailableSource(): void {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new RuntimeException('not installed'));

		$service = new PortalRegisteredDetailsService(
			$this->accounts(['identityType' => 'eherkenning', 'identityRef' => '12345678']),
			$container,
			$this->logger()
		);

		$this->assertSame(['available' => false, 'reason' => 'source_unavailable'], $service->forSubject('subject-1'));
		$this->assertStringNotContainsString('12345678', (string)json_encode($this->logged));

	}//end testAMissingProviderIsAnUnavailableSource()

	public function testNoRecordFoundSaysNotFound(): void {
		$brp = $this->provider(self::BRP, 'lookupByBsn');
		$brp->method('lookupByBsn')->willReturn(['results' => [], 'total' => 0]);

		$result = $this->service(['identityType' => 'digid', 'identityRef' => self::BSN], [self::BRP => $brp])->forSubject('subject-1');

		$this->assertSame(['available' => false, 'reason' => 'not_found'], $result);

	}//end testNoRecordFoundSaysNotFound()

	public function testTheCountOfResidentsIsNotClaimedWhileOpenRegisterCannotAnswerIt(): void {
		$brp = $this->provider(self::BRP, 'lookupByBsn');
		$brp->method('lookupByBsn')->willReturn(['results' => [$this->haalCentraalPerson()], 'total' => 1]);

		$result = $this->service(['identityType' => 'digid', 'identityRef' => self::BSN], [self::BRP => $brp])->forSubject('subject-1');

		$this->assertNull($result['person']['residentsAtAddress']);

	}//end testTheCountOfResidentsIsNotClaimedWhileOpenRegisterCannotAnswerIt()

	/**
	 * The service over one account and a container holding the given providers.
	 *
	 * @param array<string, mixed>  $account   The caller's account.
	 * @param array<string, object> $providers Class name => provider.
	 *
	 * @return PortalRegisteredDetailsService
	 */
	private function service(array $account, array $providers): PortalRegisteredDetailsService {
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturnCallback(
			static function (string $id) use ($providers): object {
				if (isset($providers[$id]) === false) {
					throw new RuntimeException('not installed: ' . $id);
				}

				return $providers[$id];
			}
		);

		return new PortalRegisteredDetailsService($this->accounts($account), $container, $this->logger());
	}//end service()

	/**
	 * An account store that finds the given account.
	 *
	 * @param array<string, mixed>|null $account The account, or null for none.
	 *
	 * @return PortalAccountService
	 */
	private function accounts(?array $account): PortalAccountService {
		$accounts = $this->getMockBuilder(PortalAccountService::class)
			->disableOriginalConstructor()
			->onlyMethods(['findBySubjectRef'])
			->getMock();
		$accounts->method('findBySubjectRef')->willReturnCallback(
			static fn (string $ref): ?array => ($ref === '' ? null : $account)
		);
		return $accounts;
	}//end accounts()

	/**
	 * A logger that records every line.
	 *
	 * @return LoggerInterface
	 */
	private function logger(): LoggerInterface {
		$this->logged = [];
		$logger = $this->createMock(LoggerInterface::class);
		foreach (['warning', 'info', 'error', 'debug', 'notice'] as $level) {
			$logger->method($level)->willReturnCallback(
				function (string $message, array $context = []): void {
					$this->logged[] = ['message' => $message, 'context' => $context];
				}
			);
		}

		return $logger;
	}//end logger()

	/**
	 * A double of OpenRegister's real provider class on its real lookup method.
	 *
	 * @param string $class  The provider class.
	 * @param string $method The lookup method.
	 *
	 * @return mixed
	 */
	private function provider(string $class, string $method): mixed {
		if (class_exists($class) === false) {
			$this->markTestSkipped($class . ' is not loadable: set PORTALIQ_OPENREGISTER_LIB to an openregister checkout\'s lib/.');
		}

		return $this->getMockBuilder($class)
			->disableOriginalConstructor()
			->onlyMethods([$method])
			->getMock();
	}//end provider()

	/**
	 * A HaalCentraal BRP 2 person as RaadpleegMetBurgerservicenummer returns it.
	 *
	 * @return array<string, mixed>
	 */
	private function haalCentraalPerson(): array {
		return [
			'burgerservicenummer' => self::BSN,
			'naam' => [
				'voornamen' => 'Jan',
				'voorvoegsel' => 'de',
				'geslachtsnaam' => 'Vries',
				'voorletters' => 'J.',
			],
			'geboorte' => [
				'datum' => ['type' => 'Datum', 'datum' => '1980-04-12', 'langFormaat' => '12 april 1980'],
				'plaats' => ['code' => '0344', 'omschrijving' => 'Utrecht'],
			],
			'verblijfplaats' => [
				'type' => 'Adres',
				'adresseerbaarObjectIdentificatie' => '0344010000012345',
				'verblijfadres' => [
					'officieleStraatnaam' => 'Dorpsstraat',
					'korteStraatnaam' => 'Dorpsstr',
					'huisnummer' => 12,
					'huisletter' => 'A',
					'postcode' => '1234AB',
					'woonplaats' => 'Utrecht',
				],
			],
		];
	}//end haalCentraalPerson()

	/**
	 * KvK Zoeken rows for one KvK number: the legal entity and two branches.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function kvkRows(): array {
		return [
			[
				'kvkNummer' => '12345678',
				'naam' => 'Korenschoof Holding B.V.',
				'type' => 'rechtspersoon',
				'rechtsvorm' => 'Besloten Vennootschap',
			],
			[
				'kvkNummer' => '12345678',
				'vestigingsnummer' => '000012345678',
				'naam' => 'Bakkerij de Korenschoof',
				'type' => 'hoofdvestiging',
				'adres' => ['binnenlandsAdres' => ['straatnaam' => 'Marktplein', 'huisnummer' => 1, 'postcode' => '3511AB', 'plaats' => 'Utrecht']],
			],
			[
				'kvkNummer' => '12345678',
				'vestigingsnummer' => '000087654321',
				'naam' => 'Korenschoof Zuid',
				'type' => 'nevenvestiging',
				'adres' => ['binnenlandsAdres' => ['straatnaam' => 'Laan', 'huisnummer' => 40, 'postcode' => '3521CD', 'plaats' => 'Utrecht']],
			],
		];
	}//end kvkRows()
}//end class
