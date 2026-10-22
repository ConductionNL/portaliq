<?php

/**
 * Whether a portal's organisation offers the government message box, read and
 * switched from the portal's settings.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Notifications
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-settings-side-cards/specs/portal-settings/spec.md#requirement-an-administrator-switches-the-berichtenbox-offer-from-the-portals-settings
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Notifications;

use OCA\Portaliq\Service\PortalOrganisationConfigService;

/**
 * The offer is the organisation's `messageBox` presentation setting
 * (inbox-berichtenbox-channel). Switching off moves it aside under
 * `messageBoxOff`, so switching on restores the same integriq source and
 * label; an organisation that never configured a source cannot be switched on
 * here. No recipient is read or written.
 */
class MessageBoxSetting {

	/**
	 * The presentation key the channel reads.
	 */
	private const KEY = 'messageBox';

	/**
	 * Where a switched-off offer is kept.
	 */
	private const KEY_OFF = 'messageBoxOff';

	/**
	 * Constructor.
	 *
	 * @param PortalOrganisationConfigService $orgConfig The organisation's presentation settings.
	 */
	public function __construct(
		private readonly PortalOrganisationConfigService $orgConfig,
	) {
	}//end __construct()

	/**
	 * The state for an organisation, or null when the slug names none.
	 *
	 * @param string $orgSlug The organisation slug.
	 *
	 * @return array{offered: bool, configured: bool, label: string}|null
	 *
	 * @spec openspec/changes/portal-settings-side-cards/specs/portal-settings/spec.md#requirement-an-administrator-switches-the-berichtenbox-offer-from-the-portals-settings
	 */
	public function read(string $orgSlug): ?array {
		$presentation = $this->orgConfig->presentationFor(orgSlug: $orgSlug);
		if ($presentation === null) {
			return null;
		}

		return $this->state(overrides: (array)$presentation['overrides']);
	}//end read()

	/**
	 * Switch the offer on or off.
	 *
	 * @param string $orgSlug The organisation slug.
	 * @param bool   $offered Whether residents are offered the message box.
	 *
	 * @return array{offered: bool, configured: bool, label: string}|string The new state, or `not_found`,
	 *                                                                       `no_source` or `save_failed`.
	 *
	 * @spec openspec/changes/portal-settings-side-cards/specs/portal-settings/spec.md#requirement-an-administrator-switches-the-berichtenbox-offer-from-the-portals-settings
	 */
	public function write(string $orgSlug, bool $offered): array|string {
		$presentation = $this->orgConfig->presentationFor(orgSlug: $orgSlug);
		if ($presentation === null) {
			return 'not_found';
		}

		$overrides = (array)$presentation['overrides'];
		$active    = (new MessageBoxOffer())->from(raw: ($overrides[self::KEY] ?? null));
		$aside     = (new MessageBoxOffer())->from(raw: ($overrides[self::KEY_OFF] ?? null));

		if ($offered === true && $active === null) {
			if ($aside === null) {
				return 'no_source';
			}

			$overrides[self::KEY] = $aside;
			unset($overrides[self::KEY_OFF]);
		}

		if ($offered === false && $active !== null) {
			$overrides[self::KEY_OFF] = $active;
			unset($overrides[self::KEY]);
		}

		if ($overrides !== (array)$presentation['overrides']
			&& $this->orgConfig->writePresentation(organisationUuid: (string)$presentation['uuid'], overrides: $overrides) === false
		) {
			return 'save_failed';
		}

		return $this->state(overrides: $overrides);
	}//end write()

	/**
	 * The state the card reads from the presentation settings.
	 *
	 * @param array<string, mixed> $overrides The organisation's presentation settings.
	 *
	 * @return array{offered: bool, configured: bool, label: string}
	 */
	private function state(array $overrides): array {
		$active = (new MessageBoxOffer())->from(raw: ($overrides[self::KEY] ?? null));
		$aside  = (new MessageBoxOffer())->from(raw: ($overrides[self::KEY_OFF] ?? null));
		$offer  = $active ?? $aside;

		return [
			'offered' => ($active !== null),
			'configured' => ($offer !== null),
			'label' => (string)($offer['label'] ?? ''),
		];
	}//end state()
}//end class
