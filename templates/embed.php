<?php
// SPDX-License-Identifier: EUPL-1.2

use OCP\Util;

$appId = OCA\Portaliq\AppInfo\Application::APP_ID;

// embedded-intake-form: the frame's own boot state. It carries the form to
// render and the minimum height, and NOTHING about a visitor: the frame never
// reads a session, so there is nothing personal here to inject.
\OC::$server->get(\OCP\IInitialStateService::class)
    ->provideInitialState($appId, 'embed', $_['embed'] ?? []);

Util::addScript($appId, $appId . '-portal');
?>
<div id="portaliq-embed" data-min-height="<?php p($_['minHeight'] ?? 480); ?>"></div>
