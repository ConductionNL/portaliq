<?php

/**
 * Portaliq portal image choice
 *
 * Whether the logo, favicon and hero image a portal is saved with may be stored.
 *
 * @category Service
 * @package  OCA\Portaliq\Service\Cms
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/portal-identity-from-the-admin/specs
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

use OCA\Portaliq\Kernel\Contracts\PortalFileReaderInterface;
use OCP\IL10N;

/**
 * The save rules of a portal's three images (REQ-PIA-001).
 *
 * A `media:<id>` reference must name an item of THIS portal's published media
 * library: an item of another portal, or one that is not published, would
 * never render on this portal's public pages, and naming another portal's
 * item would show its file under this portal's address. A favicon must be a
 * PNG, SVG or ICO, judged by the newest file of the item, which is the one the
 * media route serves. A plain address (logo, hero image) is left as it is.
 *
 * @spec openspec/changes/portal-identity-from-the-admin/specs
 */
class PortalImageChoice {

	/**
	 * The portal properties holding an image.
	 *
	 * @var list<string>
	 */
	public const FIELDS = ['logo', 'favicon', 'heroImage'];

	/**
	 * The file extensions a favicon may have.
	 *
	 * @var list<string>
	 */
	public const FAVICON_TYPES = ['png', 'svg', 'ico'];

	/**
	 * Constructor.
	 *
	 * @param MediaLibraryReader        $library The portal's published media library.
	 * @param PortalFileReaderInterface $files   The files attached to a media item.
	 * @param IL10N                     $l10n    For the refusal messages.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly MediaLibraryReader $library,
		private readonly PortalFileReaderInterface $files,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * Why the portal may not be saved with these images, or null when it may.
	 *
	 * @param array<string, mixed> $portal The portal object being saved.
	 *
	 * @return string|null The refusal message.
	 *
	 * @spec openspec/changes/portal-identity-from-the-admin/specs
	 */
	public function refusal(array $portal): ?string {
		$slug = (string)($portal['slug'] ?? '');
		foreach (self::FIELDS as $field) {
			$value = ($portal[$field] ?? null);
			if (MediaReferences::isReference(value: $value) === false) {
				continue;
			}

			$id   = substr((string)$value, strlen(MediaReferences::PREFIX));
			$item = $this->library->item(portal: $slug, id: $id);
			if ($item === null) {
				return $this->l10n->t('The %s is not a published item of this portal\'s media library.', [$field]);
			}

			if ($field === 'favicon' && $this->isFaviconType(id: $item['id']) === false) {
				return $this->l10n->t('A favicon must be a PNG, SVG or ICO file.');
			}
		}

		return null;
	}//end refusal()

	/**
	 * Whether the newest file of a media item is a PNG, SVG or ICO.
	 *
	 * @param string $id The media item's id.
	 *
	 * @return bool True when it is.
	 */
	private function isFaviconType(string $id): bool {
		$newest = null;
		foreach ($this->files->listFiles(register: 'portaliq', schema: 'media', id: $id) as $file) {
			if (isset($file['id']) === true && ($newest === null || (int)$file['id'] > (int)$newest['id'])) {
				$newest = $file;
			}
		}

		if ($newest === null) {
			return false;
		}

		$extension = strtolower(pathinfo((string)($newest['name'] ?? ''), PATHINFO_EXTENSION));

		return in_array($extension, self::FAVICON_TYPES, true);
	}//end isFaviconType()
}//end class
