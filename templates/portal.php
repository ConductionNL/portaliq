<?php
// SPDX-License-Identifier: EUPL-1.2

use OCP\Util;

$appId = OCA\Portaliq\AppInfo\Application::APP_ID;

// The public portal is a standalone React + NL Design System SPA, built
// separately from the app's internal Vue admin bundle (see webpack.portal.js).
// It boots into #portaliq-portal and drives its own routing + auth edge.
Util::addScript($appId, $appId . '-portal');
?>
<div id="portaliq-portal"></div>
