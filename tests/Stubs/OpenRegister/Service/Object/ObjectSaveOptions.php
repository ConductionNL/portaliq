<?php

/**
 * OpenRegister test stub: a verbatim copy of openregister's ObjectSaveOptions on
 * openregister #9123, loaded only when OpenRegister is absent (tests/bootstrap.php) and
 * read by psalm and phpstan. Only the @spec tags differ.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Stub
 * @package  OCA\OpenRegister\Service\Object
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude test stub of an OpenRegister class
 */

namespace OCA\OpenRegister\Service\Object;

/**
 * How one object save runs: where it goes, which object it updates, what the answer expands,
 * the files that come with it, the save policy and on whose behalf it is written.
 *
 * No strict_types: callers pass request values through it as they did to saveObject().
 *
 * @spec exclude test stub of an OpenRegister class
 */
final class ObjectSaveOptions {

	/**
	 * Constructor.
	 *
	 * @param ObjectFindTarget $target        The register and schema the object is saved in.
	 * @param string|null      $uuid          The UUID of the object to update, null to create or read it from the object.
	 * @param array|null       $extend        Properties to extend the rendered result with.
	 * @param array|null       $uploadedFiles Uploaded files from multipart/form-data.
	 * @param SavePolicy       $policy        Events, validation, insert-only and duplicate-override choices.
	 * @param SaveActor        $actor         The acting user and the owner the row is stamped with.
	 *
	 * @phpstan-param array<array-key, mixed>|null $extend
	 * @phpstan-param array<array-key, mixed>|null $uploadedFiles
	 * @psalm-param   array<array-key, mixed>|null $extend
	 * @psalm-param   array<array-key, mixed>|null $uploadedFiles
	 *
	 * @spec exclude test stub of an OpenRegister class
	 */
	public function __construct(
		public readonly ObjectFindTarget $target=new ObjectFindTarget(),
		public readonly ?string $uuid=null,
		public readonly ?array $extend=[],
		public readonly ?array $uploadedFiles=null,
		public readonly SavePolicy $policy=new SavePolicy(),
		public readonly SaveActor $actor=new SaveActor(),
	) {
	}//end __construct()
}//end class
