<?php

/**
 * Whether portaliq itself is writing to OpenRegister right now.
 *
 * Portaliq's own writes on a resident's behalf go through PortalObjectWriter
 * and PortalFileWriter. While they call OpenRegister, this context is active,
 * and PortalRecordChangeListener skips every event raised inside it: the
 * resident already saw the result on screen, and telling them "your case was
 * updated" about their own correction is noise. A handler's save or a case
 * app's job writes outside it, so those changes are reported.
 *
 * The context is a per-request service (Nextcloud's container shares it), so
 * the writer and the listener see the same instance. It counts depth rather
 * than holding a flag, so a write inside a write leaves it active until the
 * outer one is done, and it is always left in a finally block, so an exception
 * inside a write never leaves it stuck on.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Portaliq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-resident-is-not-told-about-their-own-change-req-nap-003
 */

declare(strict_types=1);

namespace OCA\Portaliq\Service;

/**
 * Marks the span of portaliq's own OpenRegister writes.
 *
 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-resident-is-not-told-about-their-own-change-req-nap-003
 */
class PortalWriteContext {

	/**
	 * How many portaliq writes are running.
	 *
	 * @var integer
	 */
	private int $depth = 0;

	/**
	 * Run a write inside the context.
	 *
	 * @param callable $write The write.
	 *
	 * @return mixed What the write returned.
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-resident-is-not-told-about-their-own-change-req-nap-003
	 */
	public function run(callable $write): mixed {
		$this->depth++;
		try {
			return $write();
		} finally {
			$this->depth--;
		}
	}//end run()

	/**
	 * Whether a portaliq write is running.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/portal-notifications-and-preferences/spec.md#requirement-a-resident-is-not-told-about-their-own-change-req-nap-003
	 */
	public function isActive(): bool {
		return $this->depth > 0;
	}//end isActive()
}//end class
