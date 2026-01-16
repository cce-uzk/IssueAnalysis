<?php declare(strict_types=1);

/**
 * Bootstrap file for IssueAnalysis plugin
 * Loads Composer autoloader for PSR-4 classes
 *
 * @author  Nadimo Staszak <nadimo.staszak@uni-koeln.de>
 */

// Load Composer autoloader only once
if (!class_exists('ComposerAutoloaderInitXial')) {
    require_once __DIR__ . '/../vendor/autoload.php';
}
