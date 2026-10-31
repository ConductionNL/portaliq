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
}
