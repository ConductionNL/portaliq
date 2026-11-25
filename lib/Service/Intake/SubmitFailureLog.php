<?php

/**
 * Portaliq Submit Failure Log
 *
 * A submit whose destination did not answer gets an error code the resident
 * can quote, one evidence line in the server log, and a short entry the daily
 * digest reads. Nothing of the answers is kept: the form in the resident's
 * browser still holds them (decision 179, nothing is queued).
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Intake
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
 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-failed-submit-is-reported-and-can-be-sent-again-req-fgv-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Intake;

use OCA\Portaliq\Kernel\AppIdentity;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records failed submits: an error code, a log line and a digest entry.
 *
 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-failed-submit-is-reported-and-can-be-sent-again-req-fgv-003
 */
class SubmitFailureLog {
	/**
	 * The app config key that holds the recent failures, as a JSON list.
	 */
	public const CONFIG_KEY = 'submit_failures';

	/**
	 * How long an entry is kept: two days, so the 07:00 digest always sees the past 24 hours.
	 */
	public const KEEP_SECONDS = 172800;

	/**
	 * At most this many entries are kept; the oldest go first.
	 */
	public const MAX_ENTRIES = 500;

	/**
	 * Constructor.
	 *
	 * @param IAppConfig      $appConfig Holds the recent failures.
	 * @param LoggerInterface $logger    Takes the evidence line.
	 * @param ITimeFactory    $time      The clock.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * Record one failed submit and answer its error code.
	 *
	 * @param array<string, mixed> $binding The form binding.
	 * @param int                  $status  What the destination answered (0 when nothing).
	 * @param string               $message Why, in the destination's words.
	 *
	 * @return string The error code, `OF-` and four characters.
	 *
	 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-failed-submit-is-reported-and-can-be-sent-again-req-fgv-003
	 */
	public function record(array $binding, int $status, string $message): string {
		$code   = self::newCode();
		$portal = (string)($binding['portal'] ?? '');
		$route  = (string)($binding['route'] ?? '');
		$this->logger->warning(
			sprintf('[portaliq] form submit failed %s: %s/%s answered %d: %s', $code, $portal, $route, $status, $message),
			['app' => AppIdentity::APP_ID, 'code' => $code, 'portal' => $portal, 'route' => $route, 'status' => $status]
		);

		$now     = $this->time->getTime();
		$entries = self::kept(entries: $this->entries(), now: $now);
		$entries[] = [
			'code' => $code,
			'portal' => $portal,
			'route' => $route,
			'title' => (string)($binding['title'] ?? $route),
			'status' => $status,
			'at' => $now,
		];
		$entries = array_slice($entries, -self::MAX_ENTRIES);
		try {
			$this->appConfig->setValueString(AppIdentity::APP_ID, self::CONFIG_KEY, (string)json_encode($entries), lazy: true);
		} catch (Throwable $failure) {
			// The code and the log line are what the resident and the staff need;
			// a digest that misses one entry is not worth a second failure.
			$this->logger->info('[portaliq] could not keep the failed submit for the digest: ' . $failure->getMessage());
		}

		return $code;
	}//end record()

	/**
	 * The failures recorded at or after a moment, oldest first.
	 *
	 * @param int $from The moment, as a unix timestamp.
	 *
	 * @return array<int, array{code: string, portal: string, route: string, title: string, status: int, at: int}> The failures.
	 *
	 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-failed-submit-is-reported-and-can-be-sent-again-req-fgv-003
	 */
	public function since(int $from): array {
		return array_values(
			array_filter(
				$this->entries(),
				static fn (array $entry): bool => $entry['at'] >= $from
			)
		);
	}//end since()

	/**
	 * A new error code: `OF-` and four upper-case hexadecimal characters.
	 *
	 * @return string The code.
	 *
	 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-failed-submit-is-reported-and-can-be-sent-again-req-fgv-003
	 */
	public static function newCode(): string {
		return 'OF-' . strtoupper(bin2hex(random_bytes(2)));
	}//end newCode()

	/**
	 * The entries younger than KEEP_SECONDS.
	 *
	 * @param array<int, array<string, mixed>> $entries The entries.
	 * @param int                              $now     Now, as a unix timestamp.
	 *
	 * @return array<int, array<string, mixed>> The entries kept.
	 *
	 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-failed-submit-is-reported-and-can-be-sent-again-req-fgv-003
	 */
	public static function kept(array $entries, int $now): array {
		return array_values(
			array_filter(
				$entries,
				static fn (array $entry): bool => (int)($entry['at'] ?? 0) > $now - self::KEEP_SECONDS
			)
		);
	}//end kept()

	/**
	 * The stored entries, each with its fields typed; a broken value reads as none.
	 *
	 * @return array<int, array{code: string, portal: string, route: string, title: string, status: int, at: int}> The entries.
	 *
	 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-failed-submit-is-reported-and-can-be-sent-again-req-fgv-003
	 */
	private function entries(): array {
		$decoded = json_decode($this->appConfig->getValueString(AppIdentity::APP_ID, self::CONFIG_KEY, '[]', lazy: true), true);
		if (is_array($decoded) === false) {
			return [];
		}

		$entries = [];
		foreach ($decoded as $entry) {
			if (is_array($entry) === false || is_string($entry['code'] ?? null) === false) {
				continue;
			}

			$entries[] = [
				'code' => $entry['code'],
				'portal' => (string)($entry['portal'] ?? ''),
				'route' => (string)($entry['route'] ?? ''),
				'title' => (string)($entry['title'] ?? ''),
				'status' => (int)($entry['status'] ?? 0),
				'at' => (int)($entry['at'] ?? 0),
			];
		}

		return $entries;
	}//end entries()
}//end class
