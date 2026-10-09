<?php

/**
 * Portaliq portal identity images
 *
 * Checks the favicon, logo and hero image a portal refers to in its media
 * library before the portal is saved.
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
 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portals-favicon-logo-and-hero-image-come-from-the-media-library-req-pia-001
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service\Cms;

use OCA\Portaliq\AppInfo\Application;
use OCP\IL10N;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Why a portal's identity images are refused, or nothing.
 *
 * Runs inside OpenRegister's pre-write hook, so it reads through the mappers
 * and the file service, never through ObjectService: ObjectService is the
 * service performing the save, and pointing it at the media schema mid-save
 * would change what it saves. A reference that cannot be read is refused:
 * an image nobody can verify is not served on every page.
 *
 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portals-favicon-logo-and-hero-image-come-from-the-media-library-req-pia-001
 */
class PortalIdentityImages {

	/**
	 * The portal fields that may hold a media:<id> reference.
	 */
	public const FIELDS = ['favicon', 'logo', 'heroImage'];

	/**
	 * The file types a browser accepts as a tab icon.
	 */
	public const FAVICON_TYPES = ['png', 'svg', 'ico'];

	private const REGISTER_MAPPER = 'OCA\\OpenRegister\\Db\\RegisterMapper';

	private const SCHEMA_MAPPER = 'OCA\\OpenRegister\\Db\\SchemaMapper';

	private const MAGIC_MAPPER = 'OCA\\OpenRegister\\Db\\MagicMapper';

	private const FILE_SERVICE = 'OCA\\OpenRegister\\Service\\FileService';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container Resolves OpenRegister's mappers and file service.
	 * @param IL10N              $l10n      Translates the refusal.
	 * @param LoggerInterface    $logger    Logs a failed read.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Why this portal may not be saved as it is, or null.
	 *
	 * @param array<string, mixed> $portal The portal's fields.
	 *
	 * @return string|null The refusal.
	 *
	 * @spec openspec/changes/portal-identity-from-the-admin/specs/portaliq-cms/spec.md#requirement-the-portals-favicon-logo-and-hero-image-come-from-the-media-library-req-pia-001
	 */
	public function refusal(array $portal): ?string {
		$slug = (string)($portal['slug'] ?? '');
		foreach (self::FIELDS as $field) {
			$value = ($portal[$field] ?? null);
			if (MediaReferences::isReference(value: $value) === false) {
				continue;
			}

			$item = $this->item(id: substr((string)$value, strlen(MediaReferences::PREFIX)));
			if ($item === null || $slug === '' || (string)(((array)$item->getObject())['portal'] ?? '') !== $slug) {
				return $this->notInLibrary(field: $field);
			}

			if ($field === 'favicon' && in_array($this->newestType(item: $item), self::FAVICON_TYPES, true) === false) {
				return $this->l10n->t('A favicon must be a PNG, SVG or ICO file.');
			}
		}

		return null;
	}//end refusal()

	/**
	 * The refusal for a reference outside this portal's library.
	 *
	 * @param string $field The portal field.
	 *
	 * @return string
	 */
	private function notInLibrary(string $field): string {
		return match ($field) {
			'favicon' => $this->l10n->t('The favicon is not in the media library of this portal.'),
			'logo' => $this->l10n->t('The logo is not in the media library of this portal.'),
			default => $this->l10n->t('The hero image is not in the media library of this portal.'),
		};
	}//end notInLibrary()

	/**
	 * The media item with this id, of any portal, or null.
	 *
	 * @param string $id The item id.
	 *
	 * @return object|null The OpenRegister object entity.
	 */
	private function item(string $id): ?object {
		if ($id === '') {
			return null;
		}

		try {
			$register = $this->container->get(self::REGISTER_MAPPER)->find(Application::APP_ID, false, false);
			$schema   = $this->container->get(self::SCHEMA_MAPPER)->findByApplicationAndSlug(slug: 'media', application: Application::APP_ID);

			return $this->container->get(self::MAGIC_MAPPER)->find(
				identifier: $id,
				register: $register,
				schema: $schema,
				_rbac: false,
				_multitenancy: false
			);
		} catch (Throwable $e) {
			$this->logger->info('Portaliq: a portal image reference did not resolve', ['id' => $id, 'reason' => $e->getMessage()]);
			return null;
		}
	}//end item()

	/**
	 * The extension of the item's newest file, the one the site serves.
	 *
	 * @param object $item The media item entity.
	 *
	 * @return string The lower-case extension, or '' without a file.
	 */
	private function newestType(object $item): string {
		try {
			$files = $this->container->get(self::FILE_SERVICE)->getFiles(object: $item);
		} catch (Throwable $e) {
			$this->logger->warning('Portaliq: the favicon file could not be listed', ['reason' => $e->getMessage()]);
			return '';
		}

		$newest = null;
		foreach ((array)$files as $file) {
			if (is_object($file) === true && ($newest === null || (int)$file->getId() > (int)$newest->getId())) {
				$newest = $file;
			}
		}

		if ($newest === null) {
			return '';
		}

		return strtolower(pathinfo((string)$newest->getName(), PATHINFO_EXTENSION));
	}//end newestType()
}//end class
