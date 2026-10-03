<?php

/**
 * Portaliq media file
 *
 * Streams the file of a published media item of the serving portal.
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
 * @spec openspec/specs/site-page-seo-history-and-media/spec.md
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

use OCA\Portaliq\Service\PortalFileReader;
use OCP\AppFramework\Http\StreamResponse;

/**
 * The file behind a media:<id> reference.
 *
 * The item must be a published item of the serving portal
 * ({@see MediaLibraryReader::item()} filters both in the query), so a draft item,
 * another portal's item and an unknown id answer the same: nothing. The newest
 * attached file is served, which is what makes replacing an item's file keep
 * its id and update every page that uses it.
 *
 * A portal behind sign-in serves no item: an image tag sends no bearer token,
 * so this route cannot tell a signed-in visitor from anyone else, and it does
 * not guess.
 */
class MediaFile {

	/**
	 * Constructor.
	 *
	 * @param MediaLibraryReader $library Reads the portal's published items.
	 * @param PortalFileReader   $files   Lists and streams an object's files.
	 */
	public function __construct(
		private readonly MediaLibraryReader $library,
		private readonly PortalFileReader $files,
	) {
	}//end __construct()

	/**
	 * The item's file, or null when there is nothing to serve.
	 *
	 * @param array<string, mixed> $portal The resolved serving portal.
	 * @param string               $id     The item id.
	 *
	 * @return StreamResponse|null
	 *
	 * @spec openspec/specs/site-page-seo-history-and-media/spec.md
	 */
	public function stream(array $portal, string $id): ?StreamResponse {
		if ($this->isPublic(portal: $portal) === false) {
			return null;
		}

		$item = $this->library->item(portal: (string)($portal['slug'] ?? ''), id: $id);
		if ($item === null) {
			return null;
		}

		$newest = null;
		foreach ($this->files->listFiles(register: 'portaliq', schema: 'media', id: $item['id']) as $file) {
			if (isset($file['id']) === true && ($newest === null || (int)$file['id'] > (int)$newest)) {
				$newest = $file['id'];
			}
		}

		if ($newest === null) {
			return null;
		}

		return $this->files->streamFile(register: 'portaliq', schema: 'media', id: $item['id'], fileId: (string)$newest);
	}//end stream()

	/**
	 * Whether the portal's content is readable without signing in.
	 *
	 * The same reading as the content API: no modes, or `public` among them.
	 *
	 * @param array<string, mixed> $portal The portal.
	 *
	 * @return bool
	 */
	private function isPublic(array $portal): bool {
		$modes = array_values(array_filter((array)(((array)($portal['authentication'] ?? []))['modes'] ?? []), 'is_string'));

		return ($modes === [] || in_array('public', $modes, true) === true);
	}//end isPublic()
}//end class
