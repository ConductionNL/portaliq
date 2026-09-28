<?php

declare(strict_types=1);

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\NotificationRuleNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * A contribution's `notifications` accepts change rules next to plain keys
 * (REQ-NAP-001).
 *
 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-case-app-declares-which-change-a-resident-hears-about-req-nap-001
 */
class NotificationRuleNormaliserTest extends TestCase {

	/**
	 * The collections of the contribution under test.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function collections(): array {
		return [
			['id' => 'mijnZaken', 'register' => 'zaken', 'schema' => 'zaak', 'scopeField' => 'initiator', 'label' => 'My cases', 'fields' => ['identifier', 'status', 'title']],
			['id' => 'viaZaken', 'register' => 'zaken', 'schema' => 'zaak', 'via' => ['collection' => 'x'], 'fields' => ['status']],
			['id' => 'claimZaken', 'register' => 'zaken', 'schema' => 'zaak', 'scopeClaim' => 'email', 'fields' => ['status']],
		];
	}//end collections()

	/**
	 * A rule on a default-scoped collection and a projected field is kept.
	 *
	 * @return void
	 */
	public function testKeepsAWellFormedRule(): void {
		$result = (new NotificationRuleNormaliser())->normalise(
			notifications: [['ruleKey' => 'case.updated', 'collection' => 'mijnZaken', 'on' => ['field' => 'status', 'operator' => 'changed'], 'titleField' => 'identifier']],
			collections: $this->collections()
		);

		$this->assertSame(
			[['ruleKey' => 'case.updated', 'collection' => 'mijnZaken', 'on' => ['field' => 'status', 'operator' => 'changed'], 'titleField' => 'identifier']],
			$result['kept']
		);
		$this->assertSame([], $result['dropped']);
	}//end testKeepsAWellFormedRule()

	/**
	 * A rule on a collection scoped through `via` or `scopeClaim` is dropped:
	 * the listener could not tell whose record it is.
	 *
	 * @return void
	 */
	public function testDropsARuleOnAViaCollection(): void {
		$result = (new NotificationRuleNormaliser())->normalise(
			notifications: [
				['ruleKey' => 'case.updated', 'collection' => 'viaZaken', 'on' => ['field' => 'status', 'operator' => 'changed']],
				['ruleKey' => 'case.updated', 'collection' => 'claimZaken', 'on' => ['field' => 'status', 'operator' => 'changed']],
				['ruleKey' => 'case.updated', 'collection' => 'elsewhere', 'on' => ['field' => 'status', 'operator' => 'changed']],
			],
			collections: $this->collections()
		);

		$this->assertSame([], $result['kept']);
		$this->assertCount(3, $result['dropped']);
		$this->assertStringContainsString('viaZaken', $result['dropped'][0]);
	}//end testDropsARuleOnAViaCollection()

	/**
	 * A rule on a field the collection does not project is dropped, and so is
	 * an operator other than `changed`.
	 *
	 * @return void
	 */
	public function testDropsAnUnprojectedField(): void {
		$result = (new NotificationRuleNormaliser())->normalise(
			notifications: [
				['ruleKey' => 'case.updated', 'collection' => 'mijnZaken', 'on' => ['field' => 'internalNote', 'operator' => 'changed']],
				['ruleKey' => 'case.updated', 'collection' => 'mijnZaken', 'on' => ['field' => 'status', 'operator' => 'equals']],
			],
			collections: $this->collections()
		);

		$this->assertSame([], $result['kept']);
		$this->assertCount(2, $result['dropped']);
		$this->assertStringContainsString('internalNote', $result['dropped'][0]);
	}//end testDropsAnUnprojectedField()

	/**
	 * Plain rule keys still pass, untouched, next to a rule object; a title
	 * field that is not projected is left off rather than dropping the rule.
	 *
	 * @return void
	 */
	public function testPlainStringsStillPass(): void {
		$result = (new NotificationRuleNormaliser())->normalise(
			notifications: ['message.created', '', 7, ['ruleKey' => 'case.updated', 'collection' => 'mijnZaken', 'on' => ['field' => 'status', 'operator' => 'changed'], 'titleField' => 'secret']],
			collections: $this->collections()
		);

		$this->assertSame(
			['message.created', ['ruleKey' => 'case.updated', 'collection' => 'mijnZaken', 'on' => ['field' => 'status', 'operator' => 'changed']]],
			$result['kept']
		);
	}//end testPlainStringsStillPass()
}//end class
