<?php

/**
 * SignedInSubject and PlanOutcomeAnswer: the resident behind a bearer token,
 * and the HTTP answer of a plan action's outcome.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Controller
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

namespace OCA\Portaliq\Tests\Unit\Controller;

use OCA\Portaliq\Controller\PlanOutcomeAnswer;
use OCA\Portaliq\Controller\SignedInSubject;
use OCA\Portaliq\Kernel\Contracts\BearerSessionResolverInterface;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The signed-in subject and the plan outcome answer.
 */
class SignedInSubjectTest extends TestCase {
	/**
	 * Resolve a subject from what the session service answers for the request's header.
	 *
	 * @param array<string, mixed>|null $answer The session service's answer.
	 *
	 * @return array<string, mixed>|null
	 */
	private function resolve(?array $answer): ?array {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getHeader')->with('Authorization')->willReturn('Bearer abc');
		$session = $this->createMock(originalClassName: BearerSessionResolverInterface::class);
		$session->expects($this->once())->method('resolveFromBearer')->with('Bearer abc')->willReturn($answer);
		return (new SignedInSubject())->resolve(session: $session, request: $request);
	}//end resolve()

	/**
	 * A session with a subject is the signed-in resident.
	 *
	 * @return void
	 */
	public function testASessionWithASubjectIsSignedIn(): void {
		$subject = ['subjectRef' => 'bsn-1', 'trust' => 'low'];
		$this->assertSame(expected: $subject, actual: $this->resolve(answer: $subject));
	}//end testASessionWithASubjectIsSignedIn()

	/**
	 * No session, or one without a subject, is nobody.
	 *
	 * @return void
	 */
	public function testNoSubjectIsNobody(): void {
		$this->assertNull(actual: $this->resolve(answer: null));
		$this->assertNull(actual: $this->resolve(answer: ['subjectRef' => '']));
		$this->assertNull(actual: $this->resolve(answer: ['trust' => 'low']));
	}//end testNoSubjectIsNobody()

	/**
	 * The unauthenticated answer is a 401 that says so.
	 *
	 * @return void
	 */
	public function testTheUnauthenticatedAnswer(): void {
		$response = (new SignedInSubject())->unauthenticated();
		$this->assertSame(expected: 401, actual: $response->getStatus());
		$this->assertSame(expected: ['authenticated' => false], actual: $response->getData());
	}//end testTheUnauthenticatedAnswer()

	/**
	 * Plan outcomes and their HTTP status.
	 *
	 * @return array<string, array{0: string, 1: int}>
	 */
	public static function outcomes(): array {
		return [
			'not found' => ['not_found', 404],
			'forbidden' => ['forbidden', 403],
			'invalid'   => ['invalid', 400],
			'unknown'   => ['storage_failed', 500],
		];
	}//end outcomes()

	/**
	 * A failed outcome is answered with its own status and named in the body.
	 *
	 * @param string $result The outcome.
	 * @param int $status The HTTP status.
	 *
	 * @return void
	 */
	#[DataProvider('outcomes')]
	public function testAFailedOutcomeIsNamed(string $result, int $status): void {
		$response = (new PlanOutcomeAnswer())->for(result: $result);
		$this->assertSame(expected: $status, actual: $response->getStatus());
		$this->assertSame(expected: ['error' => $result], actual: $response->getData());
	}//end testAFailedOutcomeIsNamed()

	/**
	 * A successful outcome is a plain ok.
	 *
	 * @return void
	 */
	public function testASuccessIsOk(): void {
		$response = (new PlanOutcomeAnswer())->for(result: 'ok');
		$this->assertSame(expected: 200, actual: $response->getStatus());
		$this->assertSame(expected: ['ok' => true], actual: $response->getData());
	}//end testASuccessIsOk()
}//end class
