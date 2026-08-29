<?php
/**
 * Portaliq delegated-admin marker for attribute-scoped endpoints.
 *
 * 🔴 THIS EXISTS TO BE NAMED, NOT TO BE RENDERED. It is deliberately NOT
 * registered in `appinfo/info.xml` — `AdminSettings` remains the app's admin
 * panel, and its docblock states the choice of `ISettings` on purpose:
 * "For most apps, ISettings is the correct choice."
 *
 * `#[AuthorizedAdminSetting(...)]` requires a
 * `class-string<OCP\Settings\IDelegatedSettings>`, which `ISettings` does not
 * satisfy — phpstan rejects it outright. Migrating `AdminSettings` would have
 * changed a documented decision about delegation to satisfy a type constraint,
 * so this subclass carries the interface instead and leaves that decision alone.
 *
 * It extends `AdminSettings` so `getForm()`, `getSection()` and `getPriority()`
 * stay the app's real implementations rather than a second, drifting copy.
 *
 * @category Settings
 * @package  OCA\Portaliq\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Portaliq\Settings;

use OCP\Settings\IDelegatedSettings;

/**
 * Delegated-settings marker used by the setup wizard's admin-only endpoints.
 *
 * @spec exclude Auth marker for ADR-042 setup endpoints; no behavioural spec.
 */
class PortaliqAdmin extends AdminSettings implements IDelegatedSettings {
	/**
	 * Human-readable name of the delegated settings section.
	 *
	 * @return string|null Null, so the section's own default name is used.
	 *
	 * @spec exclude IDelegatedSettings contract method; no behavioural spec.
	 */
	public function getName(): ?string {
		return null;
	}//end getName()

	/**
	 * App config keys an authorized (delegated) admin may manage.
	 *
	 * EMPTY ON PURPOSE, and that is the auth-critical part: an empty map means
	 * no group-restricted sub-admin is granted anything, so every endpoint
	 * scoped to this class stays full-admin-only — the same posture those
	 * endpoints had when they carried no attribute at all.
	 *
	 * @return array<string,string[]> Map of appId to allowed config keys.
	 *
	 * @spec exclude IDelegatedSettings contract method; no behavioural spec.
	 */
	public function getAuthorizedAppConfig(): array {
		return [];
	}//end getAuthorizedAppConfig()
}//end class
