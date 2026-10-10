<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Tenancy;

use OCA\Portaliq\Command\PortalProvision;
use OCA\Portaliq\Service\ExampleSite\ExampleSiteStore;
use OCA\Portaliq\Service\Tenancy\PortalProvisioningService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * operate-portals-per-organisation REQ-OPO-004: a draft portal in one step, and
 * a slug or host another portal holds is refused before anything is written.
 *
 * @spec openspec/changes/operate-portals-per-organisation/tasks.md#t06
 */
class PortalProvisioningServiceTest extends TestCase {

	/**
	 * What was written, in order.
	 *
	 * @var array<int, array{schema: string, data: array<string, mixed>}>
	 */
	private array $written = [];

	private MockObject $store;

	/**
	 * A service over a store holding some portals.
	 *
	 * @param array<int, array<string, mixed>> $portals The portals already there.
	 * @param bool $available Whether OpenRegister is there.
	 *
	 * @return PortalProvisioningService
	 */
	private function service(array $portals=[], bool $available=true): PortalProvisioningService {
		$this->written = [];
		$this->store   = $this->createMock(ExampleSiteStore::class);
		$this->store->method('available')->willReturn($available);
		$this->store->method('find')->willReturn($portals);
		$this->store->method('create')->willReturnCallback(
			function (string $schema, array $data): string {
				$this->written[] = ['schema' => $schema, 'data' => $data];
				return 'id-'.count($this->written);
			}
		);

		return new PortalProvisioningService($this->store);
	}

	private function zeist(): array {
		return ['org-b', 'zeist', 'Gemeente Zeist', 'mijn.zeist.example'];
	}

	public function testSlugTakenIsRefused(): void {
		$service = $this->service([['slug' => 'zeist', 'organisation' => 'org-a']]);

		$result = $service->provision(...$this->zeist());

		$this->assertSame(PortalProvisioningService::SLUG_TAKEN, $result['status']);
		$this->assertSame([], $this->written);

	}//end testSlugTakenIsRefused()

	public function testHostTakenIsRefused(): void {
		$service = $this->service([['slug' => 'other', 'organisation' => 'org-c', 'domains' => [['hostname' => 'MIJN.zeist.example', 'verified' => false]]]]);

		$result = $service->provision(...$this->zeist());

		$this->assertSame(PortalProvisioningService::HOST_TAKEN, $result['status'], 'whichever organisation holds it, however it is spelled');
		$this->assertSame([], $this->written);

	}//end testHostTakenIsRefused()

	public function testProvisionedPortalIsDraft(): void {
		$service = $this->service([['slug' => 'a', 'organisation' => 'org-a', 'domains' => [['hostname' => 'mijn.a.example']]]]);

		$result = $service->provision(...$this->zeist());

		$this->assertSame(PortalProvisioningService::CREATED, $result['status']);
		$portal = $this->written[0]['data'];
		$this->assertSame('portal', $this->written[0]['schema']);
		$this->assertSame('draft', $portal['status']);
		$this->assertSame('org-b', $portal['organisation']);
		$this->assertSame(['public'], $portal['authentication']['modes']);
		$this->assertSame('mijn.zeist.example', $portal['domains'][0]['hostname']);
		$this->assertFalse($portal['domains'][0]['verified']);
		$this->assertSame(['portal', 'menu', 'page'], array_column($this->written, 'schema'));
		$this->assertSame('zeist', $this->written[1]['data']['portal']);
		$this->assertSame('/', $this->written[2]['data']['route']);
		$this->assertSame('_portaliq-verify.mijn.zeist.example', $result['dns']['name']);
		$this->assertSame($portal['domains'][0]['verificationToken'], $result['dns']['value']);

	}//end testProvisionedPortalIsDraft()

	public function testInvalidInputAndAMissingStoreWriteNothing(): void {
		foreach ([['', 'zeist', 'T', 'mijn.zeist.example'], ['o', 'Bad Slug', 'T', 'mijn.zeist.example'], ['o', 'zeist', '', 'mijn.zeist.example'], ['o', 'zeist', 'T', 'https://zeist.example'], ['o', 'zeist', 'T', 'zeist.example:8080'], ['o', 'zeist', 'T', 'localhost']] as $args) {
			$result = $this->service()->provision(...$args);
			$this->assertSame(PortalProvisioningService::INVALID, $result['status'], json_encode($args));
			$this->assertSame([], $this->written);
		}

		$this->assertSame(PortalProvisioningService::UNAVAILABLE, $this->service(available: false)->provision(...$this->zeist())['status']);
		$this->assertSame([], $this->written);

	}//end testInvalidInputAndAMissingStoreWriteNothing()

	public function testACreateThatFailsStopsBeforeTheMenuAndPage(): void {
		$service = $this->service();
		$store   = $this->createMock(ExampleSiteStore::class);
		$store->method('available')->willReturn(true);
		$store->method('find')->willReturn([]);
		$store->expects($this->once())->method('create')->willReturn(null);

		$this->assertSame(PortalProvisioningService::FAILED, (new PortalProvisioningService($store))->provision(...$this->zeist())['status']);

	}//end testACreateThatFailsStopsBeforeTheMenuAndPage()

	public function testTheCommandPrintsTheDnsRecordOrTheRefusal(): void {
		$command = new PortalProvision($this->service());
		$output  = new BufferedOutput();
		$code    = $command->run(new ArrayInput(['organisation' => 'org-b', 'slug' => 'zeist', 'title' => 'Gemeente Zeist', 'host' => 'mijn.zeist.example']), $output);

		$this->assertSame(0, $code);
		$this->assertStringContainsString('_portaliq-verify.mijn.zeist.example', $output->fetch());

		$taken   = new PortalProvision($this->service([['slug' => 'x', 'domains' => [['hostname' => 'mijn.zeist.example']]]]));
		$output  = new BufferedOutput();
		$refused = $taken->run(new ArrayInput(['organisation' => 'org-c', 'slug' => 'zeist2', 'title' => 'Zeist', 'host' => 'mijn.zeist.example']), $output);

		$this->assertSame(1, $refused);
		$this->assertStringContainsString('This web address is already used by another portal.', $output->fetch());
		$this->assertSame([], $this->written);

	}//end testTheCommandPrintsTheDnsRecordOrTheRefusal()
}//end class
