<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Intake\DutchFormats;
use OCA\Portaliq\Service\Intake\PortalFamilyMembers;
use OCA\Portaliq\Service\PortalAccountService;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * data-lookups-and-checks-in-forms T04: partner and children come from the
 * BRP for a DigiD session only, on the same address when asked, and a
 * reference the BRP does not back is forged.
 *
 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t04
 */
class PortalFamilyMembersTest extends TestCase {

	private function home(): array {
		return ['verblijfadres' => ['postcode' => '1234 AB', 'huisnummer' => '12']];
	}//end home()

	private function record(): array {
		return [
			'verblijfplaats' => $this->home(),
			'partners' => [
				['naam' => ['volledigeNaam' => 'Henk de Vries'], 'geboorte' => ['datum' => ['datum' => '1983-04-12']], 'verblijfplaats' => $this->home(), 'burgerservicenummer' => '999993653'],
			],
			'kinderen' => [
				['naam' => ['volledigeNaam' => 'Sanne de Vries'], 'geboorte' => ['datum' => ['datum' => '2012-06-01']], 'verblijfplaats' => $this->home()],
				['naam' => ['volledigeNaam' => 'Daan de Vries'], 'geboorte' => ['datum' => ['datum' => '2008-02-01']], 'verblijfplaats' => ['verblijfadres' => ['postcode' => '9999 ZZ', 'huisnummer' => '1']]],
				['naam' => ['volledigeNaam' => 'Zonder adres'], 'geboorte' => ['datum' => ['datum' => '2015-02-01']]],
			],
		];
	}//end record()

	private function members(?array $account = ['identityType' => 'digid', 'identityRef' => '111222333'], ?array $answer = null, ?object &$provider = null): PortalFamilyMembers {
		$accounts = $this->getMockBuilder(PortalAccountService::class)->disableOriginalConstructor()->onlyMethods(['findBySubjectRef'])->getMock();
		$accounts->method('findBySubjectRef')->willReturn($account);
		$provider = new class($answer ?? ['results' => [$this->record()]]) {
			public array $asked = [];

			public function __construct(private array $answer) {
			}

			public function lookupByBsn(string $bsn): array {
				$this->asked[] = $bsn;

				return $this->answer;
			}
		};
		$container = $this->createMock(ContainerInterface::class);
		$container->method('get')->willReturn($provider);

		return new PortalFamilyMembers($accounts, $container, new DutchFormats(), $this->createMock(LoggerInterface::class));
	}//end members()

	public function testOnlyMembersOnTheSameAddressComeBackWithTheMinimalFields(): void {
		$list = $this->members(provider: $provider)->forSubject('sub-1');

		$this->assertSame(['Henk de Vries', 'Sanne de Vries'], array_column($list, 'name'));
		$this->assertSame(['partner', 'child'], array_column($list, 'relation'));
		$this->assertSame(['1983', '2012'], array_column($list, 'birthYear'));
		$this->assertSame(['ref', 'name', 'relation', 'birthYear'], array_keys($list[0]));
		$this->assertStringNotContainsString('999993653', json_encode($list));
		$this->assertSame(['111222333'], $provider->asked, 'the BSN comes from the account');
	}//end testOnlyMembersOnTheSameAddressComeBackWithTheMinimalFields()

	public function testAllMembersComeBackWhenTheAddressIsNotRequired(): void {
		$this->assertCount(4, $this->members()->forSubject('sub-1', false));
	}//end testAllMembersComeBackWhenTheAddressIsNotRequired()

	public function testWhatIsNotADigidSessionWithABsnGetsNothing(): void {
		foreach ([null, ['identityType' => 'eherkenning', 'identityRef' => '12345678'], ['identityType' => 'digid', 'identityRef' => '111222334']] as $account) {
			$this->assertNull($this->members(account: $account, provider: $provider)->forSubject('sub-1'));
			$this->assertSame([], $provider->asked);
		}

		$this->assertNull($this->members()->forSubject(''));
	}//end testWhatIsNotADigidSessionWithABsnGetsNothing()

	public function testABrpThatCannotBeAskedAnswersNull(): void {
		$this->assertNull($this->members(answer: ['unavailable' => true, 'cause' => 'down'])->forSubject('sub-1'));
		$this->assertNull($this->members(answer: ['results' => []])->forSubject('sub-1'));
	}//end testABrpThatCannotBeAskedAnswersNull()

	public function testAForgedReferenceIsNamedAndARealOneIsNot(): void {
		$members = $this->members();
		$real = $members->forSubject('sub-1')[0]['ref'];

		$this->assertSame([], $members->forged('sub-1', [$real]));
		$this->assertSame(['child-aaaaaaaaaaaaaaaaaaaa'], $members->forged('sub-1', [$real, 'child-aaaaaaaaaaaaaaaaaaaa']));
		$this->assertNotSame([], $members->forged('sub-2', [$real]), 'a reference is bound to the session it was issued to');
	}//end testAForgedReferenceIsNamedAndARealOneIsNot()

	public function testWhenTheBrpIsDownEveryReferenceIsForged(): void {
		$down = $this->members(answer: ['unavailable' => true]);

		$this->assertSame(['partner-aaaaaaaaaaaaaaaaaaaa'], $down->forged('sub-1', ['partner-aaaaaaaaaaaaaaaaaaaa']));
	}//end testWhenTheBrpIsDownEveryReferenceIsForged()
}
