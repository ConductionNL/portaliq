<?php

/**
 * Portaliq Traffic Visitor Hasher.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 *
 * @category  Service
 * @package   OCA\Portaliq
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link      https://portaliq.conduction.nl
 *
 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-measurement-must-be-cookieless-unless-the-portal-persists-a-client-id
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic;

use OCA\Portaliq\AppInfo\Application;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IAppConfig;

/**
 * The one identity a cookieless visitor has: a hash that lives for a day.
 *
 * The digest is sha256(dailySalt | portal | parts...). The salt is random, created the
 * first time a day needs it, and DELETED when the next day's salt is
 * created. That deletion is the privacy property: without the salt the hash
 * cannot be recomputed from an address and a user agent, so two days' events
 * cannot be joined into one person, and neither can anyone holding the
 * table.
 *
 * Portal is in the hash so the same browser on two portals is two visitors.
 *
 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-measurement-must-be-cookieless-unless-the-portal-persists-a-client-id
 */
class VisitorHasher {

	/**
	 * The prefix of the per-day salt keys in app config.
	 */
	public const SALT_PREFIX = 'traffic_salt_';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig   $config The app config store.
	 * @param ITimeFactory $time   The clock.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $config,
		private readonly ITimeFactory $time,
	) {
	}

	/**
	 * The visitor hash for a portal and the identifying parts.
	 *
	 * @param string   $portal The portal slug.
	 * @param string[] $parts  The address and user agent, or a contact reference.
	 *
	 * @return string A 64-character hex digest.
	 *
	 * @spec openspec/changes/portal-traffic-analytics/specs/portal-traffic-analytics/spec.md#requirement-measurement-must-be-cookieless-unless-the-portal-persists-a-client-id
	 */
	public function hash(string $portal, array $parts): string {
		return hash('sha256', implode('|', array_merge([$this->salt(), $portal], $parts)));
	}

	/**
	 * Today's salt, created on first use, with every older salt removed.
	 *
	 * @return string The salt.
	 */
	private function salt(): string {
		$today = self::SALT_PREFIX . gmdate('Ymd', $this->time->getTime());
		$existing = $this->config->getValueString(Application::APP_ID, $today, '');
		if ($existing !== '') {
			return $existing;
		}

		// Rotation IS deletion. The old salt is not archived anywhere; that
		// is what makes yesterday's hashes unrecomputable today.
		foreach ($this->config->getKeys(Application::APP_ID) as $key) {
			if (str_starts_with($key, self::SALT_PREFIX) === true && $key !== $today) {
				$this->config->deleteKey(Application::APP_ID, $key);
			}
		}

		$salt = bin2hex(random_bytes(32));
		$this->config->setValueString(Application::APP_ID, $today, $salt, false, true);

		return $salt;
	}
}
