<?php

declare(strict_types=1);

use Cms\ContentType\MenuLocationRegistry;

// Register menu locations available to the menu builder.
// Theme Builder may register additional locations.

MenuLocationRegistry::register('primary', 'Primary Navigation');
MenuLocationRegistry::register('footer',  'Footer Navigation');
// The footer's second column. A separate location so the owner manages these
// links in Appearance → Menus like any other navigation, rather than them being
// three <li> tags a developer has to edit.
MenuLocationRegistry::register('footer_secondary', 'Footer Navigation (second column)');
