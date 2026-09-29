<?php

/**
 * The organisation's government message box setting, checked.
 *
 * `messageBox: {sourceId, label}` in the organisation's presentation settings:
 * the integriq digital post source to send over and the label residents read.
 * Both must be non-empty text. Half a configuration is no channel, so an
 * organisation that set only one of them sends nothing and shows its residents
 * no choice (inbox-berichtenbox-channel, REQ-MBC-001).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Notifications
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inbox-berichtenbox-channel/specs/portal-message-box-channel/spec.md#requirement-the-organisation-turns-the-channel-on-req-mbc-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Notifications;

/**
 * Reads the message box setting, or nothing.
 *
 * @spec openspec/changes/inbox-berichtenbox-channel/specs/portal-message-box-channel/spec.md#requirement-the-organisation-turns-the-channel-on-req-mbc-001
 */
class MessageBoxOffer {
	/**
	 * The offer, or null when the setting is missing or half set.
	 *
	 * @param mixed $raw The organisation's `messageBox` setting.
	 *
	 * @return array{sourceId: string, label: string}|null
	 *
	 * @spec openspec/changes/inbox-berichtenbox-channel/specs/portal-message-box-channel/spec.md#requirement-the-organisation-turns-the-channel-on-req-mbc-001
	 */
	public function from(mixed $raw): ?array {
		if (is_array($raw) === false) {
			return null;
		}

		$offer = [];
		foreach (['sourceId', 'label'] as $key) {
			$value = ($raw[$key] ?? null);
			if (is_string($value) === false || trim($value) === '') {
				return null;
			}

			$offer[$key] = trim($value);
		}

		return ['sourceId' => $offer['sourceId'], 'label' => $offer['label']];
	}//end from()
}//end class
