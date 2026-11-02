<?php

/**
 * PortalRateLimit (portal-subject-rate-limit): a portal session is counted
 * per subject, a call without one per IP address.
 *
 * @category Tests
 * @package  OCA\Portaliq\Tests\Unit\Service
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

namespace OCA\Portaliq\Tests\Unit\Service;

use Exception;
use OCA\Portaliq\Service\PortalRateLimit;
use OCP\IRequest;
use OCP\IUser;
use OCP\Security\RateLimiting\ILimiter;
use OCP\Security\RateLimiting\IRateLimitExceededException;
use PHPUnit\Framework\TestCase;

/**
 * Which bucket a call counts in, and the answer when it is full.
 *
 * @spec openspec/changes/portal-subject-rate-limit/specs/portal-contribution-contract/spec.md#requirement-a-signed-in-portal-session-must-be-rate-limited-per-subject
 */
class PortalRateLimitTest extends TestCase {
	/**
	 * A limiter that records each call and refuses after `$capacity` calls
	 * per bucket, the way Nextcloud's limiter keys a bucket.
	 *
	 * @param int $capacity Calls a bucket takes.
	 *
	 * @return ILimiter&object{calls: array<int, array<int, mixed>>}
	 */
	private function limiter(int $capacity = PHP_INT_MAX): ILimiter {
		return new class($capacity) implements ILimiter {
			/** @var array<int, array<int, mixed>> */
			public array $calls = [];

			/** @var array<string, int> */
			private array $buckets = [];

			public function __construct(private readonly int $capacity) {
			}

			public function registerAnonRequest(string $identifier, int $anonLimit, int $anonPeriod, string $ip): void {
				$this->calls[] = [$identifier, $anonLimit, $anonPeriod, $ip];
				$key = $identifier . $ip;
				$this->buckets[$key] = ($this->buckets[$key] ?? 0) + 1;
				if ($this->buckets[$key] > min($this->capacity, $anonLimit)) {
					throw new class('limit') extends Exception implements IRateLimitExceededException {
					};
				}
			}

			public function registerUserRequest(string $identifier, int $userLimit, int $userPeriod, IUser $user): void {
			}
		};
	}//end limiter()

	/**
	 * The request, from one IP address.
	 *
	 * @return IRequest
	 */
	private function request(): IRequest {
		$request = $this->createMock(IRequest::class);
		$request->method('getRemoteAddress')->willReturn('192.0.2.7');
		return $request;
	}//end request()

	/**
	 * A session counts per subject, with room for a page, never by IP.
	 *
	 * @return void
	 */
	public function testASessionCountsPerSubject(): void {
		$limiter = $this->limiter();
		$limit   = new PortalRateLimit(limiter: $limiter, request: $this->request());

		$this->assertNull($limit->refusal(endpoint: 'collection', subject: ['subjectRef' => 'guardian-1']));

		[$identifier, $max, $period, $key] = $limiter->calls[0];
		$this->assertSame('portaliq-collection-subject', $identifier);
		$this->assertSame(PortalRateLimit::SUBJECT_LIMIT, $max);
		$this->assertGreaterThanOrEqual(200, $max, 'room for ten pages of twenty blocks');
		$this->assertSame(60, $period);
		$this->assertSame('subject:' . hash('sha256', 'guardian-1'), $key);
		$this->assertStringNotContainsString('192.0.2.7', $key);
	}//end testASessionCountsPerSubject()

	/**
	 * Without a session the call counts per IP at the tight limit.
	 *
	 * @return void
	 */
	public function testACallWithoutASessionCountsPerIp(): void {
		$limiter = $this->limiter();
		$limit   = new PortalRateLimit(limiter: $limiter, request: $this->request());

		$this->assertNull($limit->refusal(endpoint: 'collection', subject: null));

		$this->assertSame(['portaliq-collection-anonymous', 60, 60, '192.0.2.7'], $limiter->calls[0]);
	}//end testACallWithoutASessionCountsPerIp()

	/**
	 * Four page loads of twenty blocks from one IP pass for a session (they
	 * failed at 60 per IP), while one subject over its limit gets 429 and
	 * another subject on the same IP does not.
	 *
	 * @return void
	 */
	public function testFourPagesPassAndAFullBucketAnswers429(): void {
		$limit = new PortalRateLimit(limiter: $this->limiter(), request: $this->request());
		for ($call = 0; $call < 80; $call++) {
			$this->assertNull($limit->refusal(endpoint: 'collection', subject: ['subjectRef' => 'guardian-1']));
		}

		$small = new PortalRateLimit(limiter: $this->limiter(capacity: 2), request: $this->request());
		$small->refusal(endpoint: 'collection', subject: ['subjectRef' => 'guardian-1']);
		$small->refusal(endpoint: 'collection', subject: ['subjectRef' => 'guardian-1']);
		$refused = $small->refusal(endpoint: 'collection', subject: ['subjectRef' => 'guardian-1']);

		$this->assertNotNull($refused);
		$this->assertSame(429, $refused->getStatus());
		$this->assertSame(['error' => 'rate_limited'], $refused->getData());
		$this->assertNull($small->refusal(endpoint: 'collection', subject: ['subjectRef' => 'guardian-2']));
	}//end testFourPagesPassAndAFullBucketAnswers429()
}//end class
