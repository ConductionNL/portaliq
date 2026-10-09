<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use OCA\Portaliq\Service\Identity\PortalAccountLookup;
use OCA\Portaliq\Service\Identity\WaitingAccountJoin;
use PHPUnit\Framework\TestCase;

/**
 * invitation-joins-an-unbound-account: a person's own account that nobody
 * provisioned and that holds no claims may take on the audience of the
 * invitation whose secret its session handed in. An address alone never
 * moves an audience: the join at sign-in stays within one audience.
 *
 * @spec openspec/changes/invitation-joins-an-unbound-account/specs/portal-identity-space/spec.md
 */
class WaitingAccountJoinTest extends TestCase {
	use PortalIdentityStoreTrait;

	protected function setUp(): void {
		$this->rows = [];
		$this->afterRead = null;
		$this->readerIgnoresOrganisation = false;

	}//end setUp()

	/**
	 * A verified address that matches an invitation of another audience
	 * joins nothing, however unbound the account is: never by email alone.
	 *
	 * @return void
	 */
	public function testAnAddressAloneNeverMovesAnAudience(): void {
		$waiting = $this->seedRow('portalAccount', $this->waiting());
		$account = $this->seedRow('portalAccount', $this->unbound(['email' => 'ouder@example.org', 'verifiedEmail' => true]));

		$joined = $this->join()->join(account: $this->rows[$account], verifiedEmail: 'ouder@example.org', organisation: 'gemeente-x');

		$this->assertNull($joined);
		$this->assertSame('client', $this->rows[$account]['audience']);
		$this->assertArrayNotHasKey('claims', $this->rows[$account]);
		$this->assertSame('pending', $this->rows[$waiting]['status']);

	}//end testAnAddressAloneNeverMovesAnAudience()

	/**
	 * Without a session's trust the join keeps refusing another audience;
	 * with it, the unbound account takes on the waiting account's audience.
	 *
	 * @return void
	 */
	public function testOnlyTheRedeemOfASecretMovesTheAudience(): void {
		$waiting = $this->seedRow('portalAccount', $this->waiting());
		$account = $this->seedRow('portalAccount', $this->unbound());
		$join    = $this->join();

		$this->assertNull($join->joinWaiting(account: $this->rows[$account], waiting: $this->rows[$waiting]));
		$this->assertSame('client', $this->rows[$account]['audience']);

		$this->assertSame($waiting, $join->joinWaiting(account: $this->rows[$account], waiting: $this->rows[$waiting], sessionTrust: 'substantial'));
		$this->assertSame('parent', $this->rows[$account]['audience']);
		$this->assertSame(['learniq' => ['guardianRef' => 'guardian-7']], $this->rows[$account]['claims']);
		$this->assertSame('void', $this->rows[$waiting]['status']);

	}//end testOnlyTheRedeemOfASecretMovesTheAudience()

	/**
	 * Each condition of mayAdoptAudience() on its own: the one field that
	 * differs from the guardian's case is enough to refuse.
	 *
	 * @return void
	 */
	public function testEachConditionOfTakingOnAnAudienceRefusesOnItsOwn(): void {
		$join = $this->join();
		$this->assertTrue($join->mayAdoptAudience(account: $this->unbound(), waiting: $this->waiting(), trust: 'substantial'), 'the guardian');
		$this->assertTrue($join->mayAdoptAudience(account: $this->unbound(['identityType' => 'eidas']), waiting: $this->waiting(), trust: 'high'), 'eIDAS, high');
		$this->assertTrue($join->mayAdoptAudience(account: $this->unbound(['claims' => ['learniq' => []]]), waiting: $this->waiting(), trust: 'substantial'), 'an empty claim list holds nothing');

		$refusals = [
			'another person\'s identity on the invitation' => [[], ['identityType' => 'digid', 'identityRef' => 'bsn-somebody'], 'substantial'],
			'the same account' => [[], ['subjectRef' => 'subject-1'], 'substantial'],
			'another organisation' => [[], ['organisation' => 'gemeente-y'], 'substantial'],
			'no organisation' => [['organisation' => ''], ['organisation' => ''], 'substantial'],
			'low trust' => [[], [], 'low'],
			'an unknown trust' => [[], [], 'EH9'],
			'a company identity' => [['identityType' => 'eherkenning'], [], 'substantial'],
			'a generic identity provider' => [['identityType' => 'generic'], [], 'substantial'],
			'no identity reference' => [['identityRef' => ''], [], 'substantial'],
			'a provisioned account' => [['provisionedBy' => 'learniq'], [], 'substantial'],
			'a self-registered account' => [['provisionedBy' => 'self-registration'], [], 'substantial'],
			'an account with claims' => [['claims' => ['dossiq' => ['caseRef' => 'case-1']]], [], 'substantial'],
			'a company account' => [['audience' => 'supplier'], [], 'substantial'],
			'an invitation into the company audience' => [[], ['audience' => 'supplier'], 'substantial'],
			'an invitation without an audience' => [[], ['audience' => ''], 'substantial'],
			'an invitation that is no longer pending' => [[], ['status' => 'void'], 'substantial'],
		];
		foreach ($refusals as $case => [$accountFields, $waitingFields, $trust]) {
			$this->assertFalse($join->mayAdoptAudience(account: $this->unbound($accountFields), waiting: $this->waiting($waitingFields), trust: $trust), $case);
		}

	}//end testEachConditionOfTakingOnAnAudienceRefusesOnItsOwn()

	/**
	 * The guardian's own account after a first DigiD sign-in: the sign-in
	 * route's audience, an identity reference, nothing else.
	 *
	 * @param array<string, mixed> $overrides Fields that differ.
	 *
	 * @return array<string, mixed>
	 */
	private function unbound(array $overrides = []): array {
		return array_merge([
			'subjectRef' => 'subject-1',
			'organisation' => 'gemeente-x',
			'audience' => 'client',
			'identityType' => 'digid',
			'identityRef' => 'digid-pseudonym-1',
			'status' => 'active',
		], $overrides);
	}//end unbound()

	/**
	 * The school's invitation: a pending parent account learniq provisioned.
	 *
	 * @param array<string, mixed> $overrides Fields that differ.
	 *
	 * @return array<string, mixed>
	 */
	private function waiting(array $overrides = []): array {
		return array_merge([
			'subjectRef' => 'waiting-1',
			'organisation' => 'gemeente-x',
			'audience' => 'parent',
			'email' => 'ouder@example.org',
			'verifiedEmail' => true,
			'status' => 'pending',
			'provisionedBy' => 'learniq',
			'claims' => ['learniq' => ['guardianRef' => 'guardian-7']],
		], $overrides);
	}//end waiting()

	/**
	 * The join over the fake store.
	 *
	 * @return WaitingAccountJoin
	 */
	private function join(): WaitingAccountJoin {
		return new WaitingAccountJoin(lookup: new PortalAccountLookup(reader: $this->fakeReader()), writer: $this->fakeWriter());
	}//end join()
}//end class
