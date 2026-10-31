<?php

/**
 * Portaliq Portal Rate Limit (portal-subject-rate-limit)
 *
 * A portal session is not a Nextcloud user, so Nextcloud's own limiter counts
 * every portal call as anonymous, by IP address. A signed-in portal page reads
 * fifteen to twenty collections at once; at 60 calls a minute per IP the
 * fourth page load in a minute got 429 on every block, and every block
 * rendered empty (portal-proof run 3, De Wilgenboom).
 *
 * This limits a signed-in portal session per subject, with room for a page's
 * blocks, and keeps the tight per-IP limit for a call without a session. The
 * `#[AnonRateLimit]` on the endpoint stays as the outer bound per IP.
 *
 * @category Service
 * @package  OCA\Portaliq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-subject-rate-limit/specs/portal-contribution-contract/spec.md#requirement-a-signed-in-portal-session-must-be-rate-limited-per-subject
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\Security\RateLimiting\ILimiter;
use OCP\Security\RateLimiting\IRateLimitExceededException;

/**
 * Per-subject limit for a portal session, per-IP limit without one.
 *
 * @spec openspec/changes/portal-subject-rate-limit/specs/portal-contribution-contract/spec.md#requirement-a-signed-in-portal-session-must-be-rate-limited-per-subject
 */
class PortalRateLimit {
	/**
	 * Calls a minute for one signed-in subject: twenty blocks on fifteen
	 * page loads.
	 */
	public const SUBJECT_LIMIT = 300;

	/**
	 * Calls a minute from one IP address without a session.
	 */
	public const ANONYMOUS_LIMIT = 60;

	/**
	 * The period of both limits, in seconds.
	 */
	public const PERIOD = 60;

	/**
	 * Constructor.
	 *
	 * @param ILimiter $limiter Nextcloud's limiter.
	 * @param IRequest $request The request, for the caller's IP address.
	 */
	public function __construct(
		private readonly ILimiter $limiter,
		private readonly IRequest $request,
	) {
	}//end __construct()

	/**
	 * Count one call to an endpoint and answer 429 when it is over its limit:
	 * per subject for a session, per IP address without one.
	 *
	 * @param string                    $endpoint A short name of the endpoint.
	 * @param array<string, mixed>|null $subject  The resolved portal subject, or null.
	 *
	 * @return JSONResponse|null 429, or null when the call may go on.
	 *
	 * @spec openspec/changes/portal-subject-rate-limit/specs/portal-contribution-contract/spec.md#requirement-a-signed-in-portal-session-must-be-rate-limited-per-subject
	 */
	public function refusal(string $endpoint, ?array $subject): ?JSONResponse {
		$subjectRef = (string)($subject['subjectRef'] ?? '');
		try {
			if ($subject === null || $subjectRef === '') {
				$this->limiter->registerAnonRequest(
					'portaliq-' . $endpoint . '-anonymous',
					self::ANONYMOUS_LIMIT,
					self::PERIOD,
					$this->request->getRemoteAddress()
				);
				return null;
			}

			// The limiter keys a bucket on its identifier plus this value; a
			// hash of the subject makes it one bucket per subject, from any IP.
			$this->limiter->registerAnonRequest(
				'portaliq-' . $endpoint . '-subject',
				self::SUBJECT_LIMIT,
				self::PERIOD,
				'subject:' . hash('sha256', $subjectRef)
			);
		} catch (IRateLimitExceededException) {
			return new JSONResponse(['error' => 'rate_limited'], Http::STATUS_TOO_MANY_REQUESTS);
		}

		return null;
	}//end refusal()
}//end class
