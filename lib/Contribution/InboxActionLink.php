<?php

/**
 * Portaliq Inbox Action Link
 *
 * The action a message links to, kept only when it stays on this portal.
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
 * @spec openspec/changes/a-message-names-its-record-and-links-its-action/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Portaliq\Contribution;

/**
 * Keeps an action a label and a path inside this portal, or drops it.
 * Built by InboxMessageFields.
 *
 * @spec openspec/changes/a-message-names-its-record-and-links-its-action/tasks.md#task-1
 */
final class InboxActionLink {
	/**
	 * An action as the app projected it, kept only when it is a label and a
	 * path inside this portal.
	 *
	 * An absolute address, a protocol-relative one and a script address all
	 * return null, so the site never draws a button that leaves the portal.
	 *
	 * @param mixed $action The projected value.
	 *
	 * @return array{label: string, href: string}|null
	 *
	 * @spec openspec/changes/a-message-names-its-record-and-links-its-action/tasks.md#task-1
	 */
	public function sameSite(mixed $action): ?array {
		if (is_array($action) === false || $this->isSameSiteLink(action: $action) === false) {
			return null;
		}

		return ['label' => trim($action['label']), 'href' => $action['href']];
	}//end sameSite()

	/**
	 * Whether a projected action is a label and an address on this portal.
	 *
	 * @param array<string, mixed> $action The projected action.
	 *
	 * @return bool
	 */
	private function isSameSiteLink(array $action): bool {
		$label = $action['label'] ?? null;
		$href  = $action['href'] ?? null;

		return is_string($label) === true && trim($label) !== '' && is_string($href) === true && $this->isSameSiteHref(href: $href) === true;
	}//end isSameSiteLink()

	/**
	 * Whether an address stays on this portal: a path (never protocol-relative,
	 * never with a backslash or a control character) or an in-page hash.
	 *
	 * @param string $href The address.
	 *
	 * @return bool
	 */
	private function isSameSiteHref(string $href): bool {
		$isPath = (preg_match('#^/(?!/)#', $href) === 1 && str_contains($href, '\\') === false && preg_match('/[[:cntrl:]]/', $href) === 0);
		return $isPath === true || preg_match('/^#[A-Za-z0-9_\/=.-]+$/', $href) === 1;
	}//end isSameSiteHref()
}//end class
