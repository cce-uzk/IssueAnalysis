<?php

/**
 * This file is part of the IssueAnalysis plugin for ILIAS.
 *
 * Copyright (c) University of Cologne, CompetenceCenter E-Learning
 *
 * The plugin is licensed with the GPL-3.0,
 * see https://www.gnu.org/licenses/gpl-3.0.en.html
 * You should have received a copy of said license along with the
 * source code, too.
 */

declare(strict_types=1);


/**
 * Test bootstrap
 *
 * The tested classes only need a few ILIAS base classes. They are replaced by
 * the stubs, so the tests run without an ILIAS installation or database.
 */

require_once __DIR__ . '/stubs.stub';

$plugin_dir = dirname(__DIR__);
require_once $plugin_dir . '/vendor/autoload.php';
