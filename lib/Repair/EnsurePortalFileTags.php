<?php

/**
 * Ensure Portal File Tags Repair Step
 *
 * Creates the system tags portaliq puts on files, so they exist before a
 * resident's first upload. Nextcloud lets a tag be created only by a signed-in
 * user or from the command line, and a portal resident has no Nextcloud
 * session: OpenRegister's tagging then fails, the upload is stored untagged,
 * and the case page never lists it under "Sent by you" (found live, 25 Nov
 * 2026, cases-documents-on-the-case). A repair step runs from occ or for an
 * administrator, who may create tags.
 *
 * @category Repair
 * @package  OCA\Portaliq\Repair
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
 * @spec openspec/specs/citizen-case-documents/spec.md#requirement-the-residents-own-uploads-stay-visible-and-nothing-else-from-the-folder-req-cdc-004
 */

declare(strict_types=1);

namespace OCA\Portaliq\Repair;

use OCA\Portaliq\Kernel\Contracts\PortalFileWriterInterface;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use OCP\SystemTag\ISystemTagManager;
use Throwable;

/**
 * Creates the file tags a portal upload needs, when they are missing.
 *
 * @spec openspec/specs/citizen-case-documents/spec.md#requirement-the-residents-own-uploads-stay-visible-and-nothing-else-from-the-folder-req-cdc-004
 */
class EnsurePortalFileTags implements IRepairStep {

	/**
	 * The tags portaliq puts on files.
	 */
	public const TAGS = [PortalFileWriterInterface::TAG_FROM_APPLICANT];

	/**
	 * Constructor.
	 *
	 * @param ISystemTagManager $tags Nextcloud's system tags.
	 */
	public function __construct(
		private readonly ISystemTagManager $tags,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/citizen-case-documents/spec.md#requirement-the-residents-own-uploads-stay-visible-and-nothing-else-from-the-folder-req-cdc-004
	 */
	public function getName(): string {
		return 'Create the file tags portal uploads carry';
	}//end getName()

	/**
	 * Create each missing tag, user visible and assignable as OpenRegister
	 * would create it. A tag that already exists, under any visibility, is
	 * left alone.
	 *
	 * @param IOutput $output The repair output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/citizen-case-documents/spec.md#requirement-the-residents-own-uploads-stay-visible-and-nothing-else-from-the-folder-req-cdc-004
	 */
	public function run(IOutput $output): void {
		foreach (self::TAGS as $name) {
			if ($this->exists(name: $name) === true) {
				continue;
			}

			try {
				$this->tags->createTag(tagName: $name, userVisible: true, userAssignable: true);
				$output->info('Created the file tag ' . $name);
			} catch (Throwable $e) {
				$output->warning('Could not create the file tag ' . $name . ': ' . $e->getMessage());
			}
		}
	}//end run()

	/**
	 * Whether a tag with exactly this name exists.
	 *
	 * @param string $name The tag name.
	 *
	 * @return bool
	 */
	private function exists(string $name): bool {
		foreach ($this->tags->getAllTags(visibilityFilter: null, nameSearchPattern: $name) as $tag) {
			if ($tag->getName() === $name) {
				return true;
			}
		}

		return false;
	}//end exists()
}//end class
