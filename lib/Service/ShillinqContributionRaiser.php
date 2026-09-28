<?php

/**
 * Portaliq Shillinq Contribution Raiser (activity-offer-contract-fix)
 *
 * The one place portaliq calls shillinq's contributions raise, in process, as
 * shillinq's contract (`extracurricular-fee-to-shillinq`, version 1) offers it
 * to an app already running inside the request. Duck-typed: no `use` of a
 * shillinq class and no info.xml dependency, so portaliq works without
 * shillinq and answers `shillinq_unavailable`.
 *
 * Shillinq checks its `payment.request` action against the session user, so
 * the staff member who asked for the raise is the one it authorises.
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
 * @spec openspec/changes/activity-offer-contract-fix/specs/portaliq-cms/spec.md#requirement-staff-must-be-able-to-raise-the-contribution-for-an-activitys-confirmed-places-and-portaliq-must-write-the-reference
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

use InvalidArgumentException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Calls shillinq's ContributionRaiseService::raise() and maps its failures to
 * one of four answers.
 *
 * @spec openspec/changes/activity-offer-contract-fix/specs/portaliq-cms/spec.md#requirement-staff-must-be-able-to-raise-the-contribution-for-an-activitys-confirmed-places-and-portaliq-must-write-the-reference
 */
class ShillinqContributionRaiser {
	/**
	 * Shillinq is not installed or does not ship the raise.
	 */
	public const ERROR_UNAVAILABLE = 'shillinq_unavailable';

	/**
	 * Shillinq refused the charge as a whole (its 400).
	 */
	public const ERROR_INVALID = 'invalid_charge';

	/**
	 * Shillinq refused the staff member (its 403).
	 */
	public const ERROR_FORBIDDEN = 'forbidden';

	/**
	 * Any other failure.
	 */
	public const ERROR_FAILED = 'raise_failed';

	/**
	 * Shillinq's raise service, by name only.
	 */
	private const RAISE_SERVICE = 'OCA\\Shillinq\\Service\\ContributionRaiseService';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves shillinq's service when it exists.
	 * @param LoggerInterface $logger Records the cause of an unexpected failure.
	 * @param string $serviceClass The raise service's class name; the default is
	 *                             shillinq's, a test names its own double.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
		private readonly string $serviceClass = self::RAISE_SERVICE,
	) {
	}//end __construct()

	/**
	 * Raise one chunk of recipients.
	 *
	 * @param array<string, mixed> $payload The raise payload of shillinq's contract.
	 *
	 * @return array<string, mixed> Shillinq's answer (`batchId`, `results`, ...), or `['error' => <code>]`.
	 *
	 * @spec openspec/changes/activity-offer-contract-fix/specs/portaliq-cms/spec.md#requirement-staff-must-be-able-to-raise-the-contribution-for-an-activitys-confirmed-places-and-portaliq-must-write-the-reference
	 */
	public function raise(array $payload): array {
		if (class_exists($this->serviceClass) === false || $this->container->has($this->serviceClass) === false) {
			return ['error' => self::ERROR_UNAVAILABLE];
		}

		try {
			$answer = $this->container->get($this->serviceClass)->raise($payload);
		} catch (InvalidArgumentException $e) {
			return ['error' => self::ERROR_INVALID, 'message' => $e->getMessage()];
		} catch (RuntimeException $e) {
			if (str_starts_with($e->getMessage(), '403') === true) {
				return ['error' => self::ERROR_FORBIDDEN];
			}

			$this->logger->error('Portaliq: shillinq contribution raise failed', ['exception' => $e]);
			return ['error' => self::ERROR_FAILED];
		} catch (Throwable $e) {
			$this->logger->error('Portaliq: shillinq contribution raise failed', ['exception' => $e]);
			return ['error' => self::ERROR_FAILED];
		}

		if (is_array($answer) === false || is_array($answer['results'] ?? null) === false) {
			return ['error' => self::ERROR_FAILED];
		}

		return $answer;
	}//end raise()
}//end class
