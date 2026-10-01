<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Service\Identity;

use DateTimeImmutable;
use OCA\Portaliq\Service\Identity\PortalAccountActivationService;
use OCA\Portaliq\Service\PortalAccountService;
use OCP\Security\ISecureRandom;
use Opis\JsonSchema\Validator;
use PHPUnit\Framework\TestCase;

/**
 * identity-ways-in-screens T01/T03 (REQ-IWI-002): under the activation policy
 * a self-registered account becomes active, its address verified, when the
 * mailed link is followed, once and before it expires. The account is made by
 * the real PortalAccountService over the same fake store, and every write is
 * checked against the real `portalAccount` schema.
 *
 * @spec openspec/changes/identity-ways-in-screens/specs/portal-ways-in/spec.md#requirement-you-can-create-an-account-where-the-portal-allows-it-req-iwi-002
 */
class PortalAccountActivationServiceTest extends TestCase {
	use PortalIdentityStoreTrait;

	protected function setUp(): void {
		$this->rows = [];

	}//end setUp()

	public function testAFollowedLinkMakesTheAccountActiveWithAVerifiedAddress(): void {
		$subjectRef = $this->register();
		$service = $this->service();

		$token = $service->issue(subjectRef: $subjectRef);
		$this->assertIsString($token);
		$this->assertSame(hash('sha256', $token), $this->account()['activationTokenHash']);
		$this->assertStringNotContainsString($token, (string)json_encode($this->account()));

		$this->assertSame(['organisation' => 'gemeente-x'], $service->activate(token: $token));
		$account = $this->account();
		$this->assertSame('active', $account['status']);
		$this->assertTrue($account['verifiedEmail']);
		$this->assertTrue($this->fitsAccountSchema($account), 'the activated account fits the register schema');

	}//end testAFollowedLinkMakesTheAccountActiveWithAVerifiedAddress()

	public function testTheLinkWorksOnce(): void {
		$service = $this->service();
		$token = (string)$service->issue(subjectRef: $this->register());

		$this->assertNotNull($service->activate(token: $token));
		$this->assertNull($service->activate(token: $token));

	}//end testTheLinkWorksOnce()

	public function testAnExpiredLinkLeavesTheAccountPending(): void {
		$service = $this->service();
		$token = (string)$service->issue(subjectRef: $this->register());

		$this->assertNull($service->activate(token: $token, now: new DateTimeImmutable('+3 days')));
		$this->assertSame('pending', $this->account()['status']);

	}//end testAnExpiredLinkLeavesTheAccountPending()

	public function testAStaffProvisionedAccountGetsNoActivationLink(): void {
		$accounts = $this->accounts();
		$made = $accounts->provision(audience: 'client', organisation: 'gemeente-x', email: 'desk@example.org', provisionedBy: 'clerk-1');

		$this->assertNull($this->service()->issue(subjectRef: (string)$made['subjectRef']));
		$this->assertArrayNotHasKey('activationTokenHash', $this->account());

	}//end testAStaffProvisionedAccountGetsNoActivationLink()

	public function testTheIssuedFieldsFitTheAccountSchema(): void {
		$this->service()->issue(subjectRef: $this->register());

		$this->assertTrue($this->fitsAccountSchema($this->account()), 'the pending account with its link fits the register schema');

	}//end testTheIssuedFieldsFitTheAccountSchema()

	public function testAnExpiryThatIsNoDateTimeDoesNotFit(): void {
		$this->service()->issue(subjectRef: $this->register());
		$account = $this->account();
		$account['activationExpiresAt'] = '';

		$this->assertFalse($this->fitsAccountSchema($account), 'negative control: an empty expiry is no date-time');

	}//end testAnExpiryThatIsNoDateTimeDoesNotFit()

	/**
	 * A self-registration, made the way register() makes it.
	 *
	 * @return string The account's subjectRef.
	 */
	private function register(): string {
		$made = $this->accounts()->provision(
			audience: 'client',
			organisation: 'gemeente-x',
			email: 'ans@example.org',
			verifiedEmail: false,
			provisionedBy: PortalAccountService::SELF_REGISTRATION,
			displayName: 'Ans'
		);

		return (string)$made['subjectRef'];
	}//end register()

	/**
	 * The one account in the store.
	 *
	 * @return array<string, mixed>
	 */
	private function account(): array {
		$rows = $this->storedRows('portalAccount');
		$this->assertCount(1, $rows);

		return (array)reset($rows);
	}//end account()

	/**
	 * The real account service over the fake store.
	 *
	 * @return PortalAccountService
	 */
	private function accounts(): PortalAccountService {
		return new PortalAccountService($this->fakeReader(), $this->fakeWriter(), $this->fakeRandom());
	}//end accounts()

	/**
	 * The service under test over the fake store.
	 *
	 * @return PortalAccountActivationService
	 */
	private function service(): PortalAccountActivationService {
		// Its own secrets, so a link never reads like the account's subjectRef.
		$random = $this->createMock(ISecureRandom::class);
		$random->method('generate')->willReturn('activation-secret-48');

		return new PortalAccountActivationService($this->fakeReader(), $this->fakeWriter(), $random);
	}//end service()

	/**
	 * Whether a stored account row fits the real `portalAccount` schema.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return bool
	 */
	private function fitsAccountSchema(array $row): bool {
		foreach (array_keys($row) as $key) {
			if (str_starts_with((string)$key, '_') === true || $key === '@self' || $key === 'id' || $key === 'uuid') {
				unset($row[$key]);
			}
		}

		$register = json_decode((string)file_get_contents(__DIR__.'/../../../../lib/Settings/portaliq_register.json'), true);
		$schema = $register['components']['schemas']['portalAccount'];
		$jsonSchema = json_decode(
			(string)json_encode(['type' => 'object', 'required' => ($schema['required'] ?? []), 'properties' => $schema['properties'], 'additionalProperties' => false]),
			false
		);

		return (new Validator())->validate(json_decode((string)json_encode($row), false), $jsonSchema)->isValid();
	}//end fitsAccountSchema()
}//end class
