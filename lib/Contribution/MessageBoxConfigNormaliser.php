<?php

/**
 * Portaliq Message Box Config Normaliser
 *
 * An inbox collection may declare `messageBox: {recipientProvider}`: the
 * method on its app's own portal provider that returns the recipient identity
 * for one message, or null when that message stays in the portal. Portaliq
 * calls it server-side when the organisation offers the government message
 * box channel (inbox-berichtenbox-channel, REQ-MBC-002).
 *
 * The name comes from a manifest, so it is held to the timeline provider's
 * rule: a plain identifier, never one of the contract's own methods. Anything
 * else drops the key. Only an inbox collection can declare it: a message box
 * carries letters, not case lists.
 *
 * @category Contribution
 * @package  OCA\Portaliq\Contribution
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
 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-the-case-app-names-the-recipient-and-portaliq-does-not-keep-it-req-mbc-002
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Keeps a well-formed `messageBox` declaration on an inbox collection.
 *
 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-the-case-app-names-the-recipient-and-portaliq-does-not-keep-it-req-mbc-002
 */
class MessageBoxConfigNormaliser {
	/**
	 * Keep `messageBox: {recipientProvider}` on an inbox collection whose
	 * method name portaliq may call; drop the key otherwise.
	 *
	 * @param array<string, mixed> $collection The collection.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/specs/portal-message-box-channel/spec.md#requirement-the-case-app-names-the-recipient-and-portaliq-does-not-keep-it-req-mbc-002
	 */
	public function normalise(array $collection): array {
		if (array_key_exists('messageBox', $collection) === false) {
			return $collection;
		}

		$declared = $collection['messageBox'];
		unset($collection['messageBox']);
		if (($collection['kind'] ?? null) !== 'inbox' || is_array($declared) === false) {
			return $collection;
		}

		$method = ($declared['recipientProvider'] ?? null);
		if ((new TimelineProviderMethod())->accepts(name: $method) === false) {
			return $collection;
		}

		$collection['messageBox'] = ['recipientProvider' => $method];

		// The fields that hold the letter's text and subject, when the
		// collection names them (dossiq keeps the text in `content`). Only a
		// plain field name travels on.
		foreach (['bodyField', 'subjectField'] as $key) {
			$field = ($declared[$key] ?? null);
			if (is_string($field) === true && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $field) === 1) {
				$collection['messageBox'][$key] = $field;
			}
		}

		return $collection;
	}//end normalise()
}//end class
