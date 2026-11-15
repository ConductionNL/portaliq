<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Intake;

use OCA\Portaliq\Service\Identity\PortalAccountLookup;
use OCA\Portaliq\Service\Intake\DutchFormats;
use OCA\Portaliq\Service\Intake\PortalFamilyMembers;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The edges of the family lookup the main test leaves out: a missing BRP
 * provider and an unavailable BRP are logged with their cause, members that
 * are no record or carry no name are left out, a resident without a
 * comparable address has nobody "at home", a birth year falls back on the
 * year alone, an eIDAS session counts like DigiD, and a reference that is no
 * string is reported as forged under a printable label.
 *
 * @covers \OCA\Portaliq\Service\Intake\PortalFamilyMembers
 * @uses   \OCA\Portaliq\Service\Intake\DutchFormats
 *
 * @spec openspec/changes/data-lookups-and-checks-in-forms/tasks.md#t04
 */
class PortalFamilyMembersEdgeTest extends TestCase {

	/**
	 * Warnings logged, as [message, context].
	 *
	 * @var array<int, array{0: string, 1: array<string, mixed>}>
	 */
	private array $warnings = [];

	/**
	 * The lookup over one account and one BRP answer (or a provider that cannot be resolved).
	 *
	 * @param array<string, mixed>|null $account The portal account.
	 * @param array<string, mixed>|null $answer  The BRP answer; null makes the provider unresolvable.
	 *
	 * @return PortalFamilyMembers
	 */
	private function members(?array $account, ?array $answer): PortalFamilyMembers {
		$accounts = $this->getMockBuilder(PortalAccountLookup::class)->disableOriginalConstructor()->onlyMethods(['bySubjectRef'])->getMock();
		$accounts->method('bySubjectRef')->willReturn($account);

		$container = $this->createMock(ContainerInterface::class);
		if ($answer === null) {
			$container->method('get')->willThrowException(new RuntimeException('no BRP app'));
		} else {
			$container->method('get')->willReturn(
				new class($answer) {
					public function __construct(private array $answer) {
					}

					public function lookupByBsn(string $bsn): array {
						return $this->answer;
					}
				}
			);
		}

		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('warning')->willReturnCallback(
			function (string $message, array $context=[]): void {
				$this->warnings[] = [$message, $context];
			}
		);

		return new PortalFamilyMembers($accounts, $container, new DutchFormats(), $logger);
	}//end members()

	/**
	 * @return array<string, string>
	 */
	private static function digid(): array {
		return ['identityType' => 'digid', 'identityRef' => '111222333'];
	}//end digid()

	public function testAMissingProviderIsLoggedAsProviderMissing(): void {
		$this->assertNull($this->members(account: self::digid(), answer: null)->forSubject('sub-1'));
		$this->assertSame([['Portaliq: family members cannot be read', ['cause' => 'provider_missing']]], $this->warnings);
	}//end testAMissingProviderIsLoggedAsProviderMissing()

	public function testAnUnavailableBrpIsLoggedWithItsCause(): void {
		$this->assertNull($this->members(account: self::digid(), answer: ['unavailable' => true, 'cause' => 'timeout'])->allForSubject('sub-1'));
		$this->assertNull($this->members(account: self::digid(), answer: ['results' => ['not a person']])->allForSubject('sub-1'));
		$this->assertSame(
			[
				['Portaliq: family members cannot be read', ['cause' => 'timeout']],
				['Portaliq: family members cannot be read', ['cause' => 'unavailable']],
			],
			$this->warnings
		);
	}//end testAnUnavailableBrpIsLoggedWithItsCause()

	public function testMembersThatAreNoRecordOrHaveNoNameAreLeftOut(): void {
		$person = [
			'partners' => ['junk', ['naam' => ['volledigeNaam' => '   ']]],
			'kinderen' => [['naam' => ['volledigeNaam' => 'Sanne'], 'geboorte' => ['datum' => ['jaar' => 2012]]]],
		];

		$list = $this->members(account: ['identityType' => 'eidas', 'identityRef' => '111222333'], answer: ['results' => [$person]])->allForSubject('sub-1');

		$this->assertSame([['Sanne', 'child', '2012']], array_map(static fn (array $m): array => [$m['name'], $m['relation'], $m['birthYear']], $list));
		$this->assertMatchesRegularExpression('/^child-/', $list[0]['ref']);
	}//end testMembersThatAreNoRecordOrHaveNoNameAreLeftOut()

	public function testAResidentWithoutAComparableAddressHasNobodyAtHome(): void {
		$sameStreet = ['verblijfplaats' => ['verblijfadres' => ['postcode' => '1234AB']]];
		$person = $sameStreet + ['partners' => [['naam' => ['volledigeNaam' => 'Henk']] + $sameStreet]];

		$members = $this->members(account: self::digid(), answer: ['results' => [$person]]);

		$this->assertSame([], $members->forSubject('sub-1'));
		$this->assertSame(['Henk'], array_column($members->allForSubject('sub-1'), 'name'));
	}//end testAResidentWithoutAComparableAddressHasNobodyAtHome()

	public function testAHouseLetterAndAdditionMustMatchToo(): void {
		$home = ['verblijfplaats' => ['verblijfadres' => ['postcode' => '1234 ab', 'huisnummer' => '12', 'huisletter' => 'a', 'huisnummertoevoeging' => '2']]];
		$next = ['verblijfplaats' => ['verblijfadres' => ['postcode' => '1234AB', 'huisnummer' => '12', 'huisletter' => 'B', 'huisnummertoevoeging' => '2']]];
		$person = $home + [
			'kinderen' => [
				['naam' => ['volledigeNaam' => 'Thuis']] + ['verblijfplaats' => ['verblijfadres' => ['postcode' => '1234AB', 'huisnummer' => '12', 'huisletter' => 'A', 'huisnummertoevoeging' => '2']]],
				['naam' => ['volledigeNaam' => 'Buren']] + $next,
			],
		];

		$this->assertSame(['Thuis'], array_column($this->members(account: self::digid(), answer: ['results' => [$person]])->forSubject('sub-1'), 'name'));
	}//end testAHouseLetterAndAdditionMustMatchToo()

	public function testAReferenceThatIsNoStringIsForgedUnderAPrintableLabel(): void {
		$members = $this->members(account: self::digid(), answer: ['results' => [['partners' => [['naam' => ['volledigeNaam' => 'Henk']]]]]]);

		$this->assertSame(['42', ''], $members->forged('sub-1', [42, ['partner-x']]));
	}//end testAReferenceThatIsNoStringIsForgedUnderAPrintableLabel()
}
