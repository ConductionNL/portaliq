<?php

/**
 * NoticeRecipientNormaliser: every refusal of a change rule's recipients and
 * messages, and the shape of a kept one.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Contribution
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

namespace OCA\Portaliq\Tests\Unit\Contribution;

use OCA\Portaliq\Contribution\NoticeRecipientNormaliser;
use PHPUnit\Framework\TestCase;

/**
 * Recipients and messages, as recorded.
 */
class NoticeRecipientNormaliserGoldenTest extends TestCase {
	/**
	 * The refusal for a rule on a collection projecting `status` and `when`.
	 *
	 * @param array<string, mixed> $rule  The rule.
	 * @param string               $appId The app.
	 *
	 * @return string|null
	 */
	private static function refusal(array $rule, string $appId = 'dossiq'): ?string {
		return (new NoticeRecipientNormaliser())->refusal(rule: $rule, collection: ['fields' => ['status', 'when']], appId: $appId, label: 'R');
	}//end refusal()

	/**
	 * Every recipients and messages refusal, in the order they are checked.
	 *
	 * @return void
	 */
	public function testEveryRefusalIsAsRecorded(): void {
		$recipients = 'R: recipients need a field and a claim of the app itself, not of another app';
		$this->assertSame($recipients, self::refusal(['recipients' => 'x']));
		$this->assertSame($recipients, self::refusal(['recipients' => ['field' => '1x', 'claim' => 'kvk']]));
		$this->assertSame($recipients, self::refusal(['recipients' => ['field' => 'owner', 'claim' => 7]]));
		$this->assertSame($recipients, self::refusal(['recipients' => ['field' => 'owner', 'claim' => 'other.kvk']]));
		$this->assertSame($recipients, self::refusal(['recipients' => ['field' => 'owner', 'claim' => 'dossiq.a.b']]));
		$this->assertSame($recipients, self::refusal(['recipients' => ['field' => 'owner', 'claim' => '.kvk']], appId: ''));
		$this->assertSame($recipients, self::refusal(['recipients' => ['field' => 'owner', 'claim' => 'dossiq.'], 'messages' => 'x']));
		$this->assertNull(self::refusal(['recipients' => ['field' => 'owner', 'claim' => 'dossiq.kvk']]));
		$this->assertNull(self::refusal([]));

		$this->assertSame('R: messages must map a value to a subject and a body', self::refusal(['messages' => []]));
		$this->assertSame('R: messages must map a value to a subject and a body', self::refusal(['messages' => 'x']));
		$this->assertSame('R: a message needs a subject and a body', self::refusal(['messages' => ['open' => 'x']]));
		$this->assertSame('R: a message needs a subject and a body', self::refusal(['messages' => ['open' => ['subject' => 'S']]]));
		$this->assertSame('R: a message needs a subject and a body', self::refusal(['messages' => ['open' => ['subject' => [], 'body' => 'B']]]));
		$this->assertSame('R: a message text must be a string that says something', self::refusal(['messages' => ['open' => ['subject' => ['S', ' '], 'body' => 'B']]]));
		$this->assertSame('R: the placeholder "secret" is not a field projected to the resident', self::refusal(['messages' => ['open' => ['subject' => 'S {status}', 'body' => 'B {secret|datetime}']]]));
		$this->assertNull(self::refusal(['messages' => ['open' => ['subject' => ['S {when|datetime}', 'T'], 'body' => 'B']]]));
	}//end testEveryRefusalIsAsRecorded()

	/**
	 * A kept rule's recipients and messages, each only when declared.
	 *
	 * @return void
	 */
	public function testTheShapeIsAsRecorded(): void {
		$normaliser = new NoticeRecipientNormaliser();
		$this->assertSame(
			['recipients' => ['field' => 'owner', 'claim' => 'kvk'], 'messages' => ['1' => ['subject' => ['S'], 'body' => 'B']]],
			$normaliser->shape(rule: ['recipients' => ['field' => 'owner', 'claim' => 'dossiq.kvk'], 'messages' => [1 => ['subject' => ['S'], 'body' => 'B', 'x' => 1]]], appId: 'dossiq')
		);
		$this->assertSame(['recipients' => ['field' => 'owner', 'claim' => 'kvk']], $normaliser->shape(rule: ['recipients' => ['field' => 'owner', 'claim' => 'kvk']], appId: 'dossiq'));
		$this->assertSame([], $normaliser->shape(rule: [], appId: 'dossiq'));
	}//end testTheShapeIsAsRecorded()
}//end class
