<?php

/**
 * Portaliq Traffic Geo Settings.
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
 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-maxmind-credentials-must-never-be-echoed-back
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Traffic\Geo;

use OCA\Portaliq\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Which geography provider the instance uses, and the MaxMind credentials
 * when it is MaxMind.
 *
 * ONE class reads these keys so the admin panel, the refresh service and
 * the resolver agree on the default. The licence key is written as a
 * SENSITIVE app config value and this class never hands it to anything
 * that renders: `toArray()` says whether a key is stored, and only the
 * provider that needs it asks for the value.
 *
 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-maxmind-credentials-must-never-be-echoed-back
 */
class GeoSettings {

	/**
	 * The provider key: none, dbip or maxmind.
	 */
	public const KEY_PROVIDER = 'traffic.geo.provider';

	/**
	 * The MaxMind account id key.
	 */
	public const KEY_ACCOUNT_ID = 'traffic.geo.maxmind.accountId';

	/**
	 * The MaxMind licence key key. Stored sensitive.
	 */
	public const KEY_LICENSE_KEY = 'traffic.geo.maxmind.licenseKey';

	/**
	 * The MaxMind edition key: GeoLite2-City or GeoIP2-City.
	 */
	public const KEY_EDITION = 'traffic.geo.maxmind.edition';

	/**
	 * The providers this app knows.
	 *
	 * @var string[]
	 */
	public const PROVIDERS = ['none', 'dbip', 'maxmind'];

	/**
	 * The MaxMind editions this app fetches.
	 *
	 * @var string[]
	 */
	public const EDITIONS = ['GeoLite2-City', 'GeoIP2-City'];

	/**
	 * The provider an instance gets before anyone chose (Ruben, decision 7).
	 */
	public const DEFAULT_PROVIDER = 'dbip';

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $config The app config store.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppConfig $config,
	) {
	}

	/**
	 * The configured provider, defaulting to DB-IP.
	 *
	 * @return string One of PROVIDERS.
	 *
	 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-geography-must-come-from-an-offline-database-the-operator-chose
	 */
	public function provider(): string {
		$value = $this->config->getValueString(Application::APP_ID, self::KEY_PROVIDER, self::DEFAULT_PROVIDER);
		if (in_array($value, self::PROVIDERS, true) === false) {
			return self::DEFAULT_PROVIDER;
		}

		return $value;
	}

	/**
	 * The MaxMind account id, or ''.
	 *
	 * @return string The account id.
	 *
	 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-maxmind-credentials-must-never-be-echoed-back
	 */
	public function maxMindAccountId(): string {
		return trim($this->config->getValueString(Application::APP_ID, self::KEY_ACCOUNT_ID, ''));
	}

	/**
	 * The MaxMind licence key, or ''. For the provider only.
	 *
	 * @return string The key.
	 *
	 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-maxmind-credentials-must-never-be-echoed-back
	 */
	public function maxMindLicenseKey(): string {
		return trim($this->config->getValueString(Application::APP_ID, self::KEY_LICENSE_KEY, ''));
	}

	/**
	 * The MaxMind edition, defaulting to the free one.
	 *
	 * @return string One of EDITIONS.
	 *
	 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-geography-must-come-from-an-offline-database-the-operator-chose
	 */
	public function maxMindEdition(): string {
		$value = $this->config->getValueString(Application::APP_ID, self::KEY_EDITION, self::EDITIONS[0]);
		if (in_array($value, self::EDITIONS, true) === false) {
			return self::EDITIONS[0];
		}

		return $value;
	}

	/**
	 * What the admin panel may see: everything but the key itself.
	 *
	 * @return array{provider: string, maxmindAccountId: string, maxmindEdition: string, maxmindLicenseKeySet: bool} The view.
	 *
	 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-maxmind-credentials-must-never-be-echoed-back
	 */
	public function toArray(): array {
		return [
			'provider' => $this->provider(),
			'maxmindAccountId' => $this->maxMindAccountId(),
			'maxmindEdition' => $this->maxMindEdition(),
			'maxmindLicenseKeySet' => ($this->maxMindLicenseKey() !== ''),
		];
	}

	/**
	 * Apply an admin panel write. Unknown providers and editions are
	 * ignored; an absent key leaves the stored one alone, an empty string
	 * removes it.
	 *
	 * @param array<string, mixed> $data provider, maxmindAccountId, maxmindLicenseKey, maxmindEdition.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-traffic-visitors-and-geo/specs/portal-traffic-visitors-and-geo/spec.md#requirement-maxmind-credentials-must-never-be-echoed-back
	 */
	public function update(array $data): void {
		$provider = $data['provider'] ?? null;
		if (is_string($provider) === true && in_array($provider, self::PROVIDERS, true) === true) {
			$this->config->setValueString(Application::APP_ID, self::KEY_PROVIDER, $provider);
		}

		$edition = $data['maxmindEdition'] ?? null;
		if (is_string($edition) === true && in_array($edition, self::EDITIONS, true) === true) {
			$this->config->setValueString(Application::APP_ID, self::KEY_EDITION, $edition);
		}

		$accountId = $data['maxmindAccountId'] ?? null;
		if (is_string($accountId) === true) {
			$this->config->setValueString(Application::APP_ID, self::KEY_ACCOUNT_ID, trim($accountId));
		}

		$key = $data['maxmindLicenseKey'] ?? null;
		if (is_string($key) === false) {
			return;
		}

		if (trim($key) === '') {
			$this->config->deleteKey(Application::APP_ID, self::KEY_LICENSE_KEY);

			return;
		}

		$this->config->setValueString(Application::APP_ID, self::KEY_LICENSE_KEY, trim($key), false, true);
	}
}
