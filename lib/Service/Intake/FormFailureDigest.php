<?php

/**
 * Portaliq Form Failure Digest
 *
 * Once a day, from 07:00 server time, each portal's administrators get one
 * mail naming the forms whose submits failed in the past 24 hours, with the
 * error codes the residents saw. A quiet day sends nothing.
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

use DateTimeImmutable;
use DateTimeZone;
use OCA\Portaliq\Kernel\AppIdentity;
use OCP\IAppConfig;

/**
 * The daily digest of failed submits, one mail per portal administrator.
 *
 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-failed-submit-is-reported-and-can-be-sent-again-req-fgv-003
 */
class FormFailureDigest {
	/**
	 * The hour of the day, server time, from which the digest goes out.
	 */
	public const HOUR = 7;

	/**
	 * The app config key holding the day (Y-m-d) the last digest went out.
	 */
	public const SENT_KEY = 'submit_failure_digest_day';

	/**
	 * Constructor.
	 *
	 * @param SubmitFailureLog       $failures   The failed submits.
	 * @param FormFailureRecipients  $recipients Who administers each portal.
	 * @param FormFailureDigestMail  $mail       Writes and sends one digest.
	 * @param IAppConfig             $appConfig  The day the last digest went out.
	 */
	public function __construct(
		private readonly SubmitFailureLog $failures,
		private readonly FormFailureRecipients $recipients,
		private readonly FormFailureDigestMail $mail,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * Send today's digest when it is due: from 07:00, once a day, and only
	 * to the portals with failed submits in the past 24 hours.
	 *
	 * @param int $now Now, as a unix timestamp.
	 *
	 * @return int The number of mails the mail server took.
	 *
	 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-failed-submit-is-reported-and-can-be-sent-again-req-fgv-003
	 */
	public function run(int $now): int {
		$local = (new DateTimeImmutable('@' . $now))->setTimezone(new DateTimeZone(date_default_timezone_get()));
		$today = $local->format('Y-m-d');
		if ((int)$local->format('G') < self::HOUR
			|| $this->appConfig->getValueString(AppIdentity::APP_ID, self::SENT_KEY, '') === $today
		) {
			return 0;
		}

		$this->appConfig->setValueString(AppIdentity::APP_ID, self::SENT_KEY, $today);
		$sent = 0;
		foreach (self::perPortal(failures: $this->failures->since(from: $now - 86400)) as $slug => $forms) {
			$portal = $this->recipients->portal(slug: $slug);
			foreach ($this->recipients->addresses(portal: $portal) as $address) {
				$sent += (int)$this->mail->send(address: $address, portal: $portal, forms: $forms);
			}
		}

		return $sent;
	}//end run()

	/**
	 * The failures grouped per portal and per form, in the order they came.
	 *
	 * @param array<int, array{code: string, portal: string, route: string, title: string, status: int, at: int}> $failures The failures.
	 *
	 * @return array<string, array<string, array{title: string, codes: array<int, string>}>> Per portal slug, per route.
	 *
	 * @spec openspec/changes/form-governance-availability-retention-and-routing/specs/portal-form-governance/spec.md#requirement-a-failed-submit-is-reported-and-can-be-sent-again-req-fgv-003
	 */
	public static function perPortal(array $failures): array {
		$out = [];
		foreach ($failures as $failure) {
			$slug  = $failure['portal'];
			$route = $failure['route'];
			if (isset($out[$slug][$route]) === false) {
				$title = $failure['title'];
				if ($title === '') {
					$title = $route;
				}

				$out[$slug][$route] = ['title' => $title, 'codes' => []];
			}

			$out[$slug][$route]['codes'][] = $failure['code'];
		}

		return $out;
	}//end perPortal()
}//end class
