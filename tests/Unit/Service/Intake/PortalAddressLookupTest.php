<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\DutchFormats;
use OCA\Portaliq\Service\Intake\PortalAddressLookup;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * data-lookups-and-checks-in-forms T01: street and town from the BAG register
 * by postcode and number, nothing for a bad postcode, a miss or a register
 * that cannot be read.
 *
 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t01
 */
class PortalAddressLookupTest extends TestCase {

	private function lookup(array $rows, ?object &$service = null): PortalAddressLookup {
		$service = new class($rows) {
			public array $filters = [];

			public string $register = '';

			public int $reads = 0;

			public function __construct(private array $rows) {
			}

			public function setRegister(string $register): void {
				$this->register = $register;
			}

			public function setSchema(string $schema): void {
			}

			public function findAll(array $config = [], bool $_rbac = true, bool $_multitenancy = true): array {
				$this->reads++;
				$this->filters = $config['filters'];

				return $this->rows;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($service);

		return new PortalAddressLookup($container, new DutchFormats(), $this->createMock(LoggerInterface::class));
	}//end lookup()

	public function testAFoundAddressGivesStreetAndTown(): void {
		$lookup = $this->lookup([['openbareRuimte' => 'Lindelaan', 'woonplaats' => 'Zuiddrecht']], $service);

		$this->assertSame(['street' => 'Lindelaan', 'town' => 'Zuiddrecht'], $lookup->find('1234 ab', '12', 'a', '2'));
		$this->assertSame('bag', $service->register);
		$this->assertSame(['postcode' => '1234AB', 'huisnummer' => 12, 'huisletter' => 'A', 'huisnummertoevoeging' => '2'], $service->filters);
	}//end testAFoundAddressGivesStreetAndTown()

	public function testABadPostcodeOrNumberNeverReachesTheRegister(): void {
		$lookup = $this->lookup([['openbareRuimte' => 'X', 'woonplaats' => 'Y']], $service);

		$this->assertNull($lookup->find('12345', '12'));
		$this->assertNull($lookup->find('1234AB', 'twaalf'));
		$this->assertNull($lookup->find('1234AB', '0'));
		$this->assertSame(0, $service->reads);
	}//end testABadPostcodeOrNumberNeverReachesTheRegister()

	public function testAMissAnIncompleteRowAndABrokenRegisterAnswerNull(): void {
		$this->assertNull($this->lookup([])->find('1234AB', '12'));
		$this->assertNull($this->lookup([['openbareRuimte' => 'Lindelaan']])->find('1234AB', '12'));

		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('no register'));
		$broken = new PortalAddressLookup($container, new DutchFormats(), $this->createMock(LoggerInterface::class));
		$this->assertNull($broken->find('1234AB', '12'));
	}//end testAMissAnIncompleteRowAndABrokenRegisterAnswerNull()

	/**
	 * Every row shape, filter and failure reads exactly as recorded.
	 *
	 * @return void
	 */
	public function testEveryLookupIsAsRecorded(): void {
		$serialisable = new class implements \JsonSerializable {
			public function jsonSerialize(): array {
				return ['openbareRuimte' => ' Kerkstraat ', 'woonplaats' => ' Oud-Zuid '];
			}
		};
		$cases = [
			[[$serialisable], ['1234AB', '99999', 'bc', 'ABCDE'], ['street' => 'Kerkstraat', 'town' => 'Oud-Zuid'], ['postcode' => '1234AB', 'huisnummer' => 99999, 'huisletter' => 'B', 'huisnummertoevoeging' => 'ABCD']],
			[[['straat' => 'Dorpsweg', 'woonplaats' => 'Dorp'], ['openbareRuimte' => 'Second', 'woonplaats' => 'Row']], ['1234 ab', '1'], ['street' => 'Dorpsweg', 'town' => 'Dorp'], ['postcode' => '1234AB', 'huisnummer' => 1]],
			// An empty openbareRuimte is a value, so it does not fall back to straat.
			[[['openbareRuimte' => '', 'straat' => 'Fallback', 'woonplaats' => 'T']], ['1234AB', '1'], null, null],
			[[['openbareRuimte' => 'S', 'woonplaats' => '  ']], ['1234AB', '1'], null, null],
			[['not a row'], ['1234AB', '1'], null, null],
			[[new \stdClass()], ['1234AB', '1'], null, null],
			[[['openbareRuimte' => 'S', 'woonplaats' => 'T']], ['1234AB', '100000'], null, null],
			[[['openbareRuimte' => 'S', 'woonplaats' => 'T']], ['1234AB', ' 1'], null, null],
			[[['openbareRuimte' => 'S', 'woonplaats' => 'T']], ['1234AB', '1', '', ''], ['street' => 'S', 'town' => 'T'], ['postcode' => '1234AB', 'huisnummer' => 1]],
		];
		foreach ($cases as $index => [$rows, $args, $expected, $filters]) {
			$service = null;
			$found   = $this->lookup($rows, $service)->find(...$args);
			$this->assertSame($expected, $found, 'case ' . $index);
			if ($filters !== null) {
				$this->assertSame($filters, $service->filters, 'case ' . $index);
			}
		}

		$logged    = [];
		$logger    = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			static function (string $message, array $context = []) use (&$logged): void {
				$logged[] = [$message, $context];
			}
		);
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willThrowException(new \RuntimeException('no register'));
		$this->assertNull((new PortalAddressLookup($container, new DutchFormats(), $logger))->find('1234AB', '12'));
		$this->assertSame([['Portaliq: the address register could not be read', ['reason' => 'no register']]], $logged);
	}//end testEveryLookupIsAsRecorded()
}
