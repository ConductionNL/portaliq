<?php

/**
 * Contract of FormUploadGrants
 *
 * The public surface of FormUploadGrants that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Portaliq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Portaliq\Kernel\Contracts;

/**
 * Contract of FormUploadGrants.
 *
 * @category Kernel
 * @package  OCA\Portaliq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/portaliq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface FormUploadGrantsInterface {

	/**
	 * Mint the one-time upload token of a just-created submission.
	 *
	 * @param array{register: string, schema: string, id: string} $target  The object the submission became.
	 * @param array<string, array<string, mixed>>                 $uploads The file fields and their configs, by field id.
	 *
	 * @return string The token, '' when there is nothing to upload.
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function mint(array $target, array $uploads): string;

	/**
	 * What a token may still upload into one field, without using it.
	 *
	 * @param string $token The token.
	 * @param string $field The file field.
	 *
	 * @return array{target: array{register: string, schema: string, id: string}, config: array<string, mixed>}|null
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function grantFor(string $token, string $field): ?array;

	/**
	 * Use one upload of the token's field. False when none is left.
	 *
	 * @param string $token The token.
	 * @param string $field The file field.
	 *
	 * @return bool
	 *
	 * @spec exclude Structural contract, no functional requirement.
	 */
	public function use(string $token, string $field): bool;
}//end interface
