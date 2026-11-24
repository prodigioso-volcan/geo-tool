<?php
/**
 * PHPUnit 9 has one bootstrap per configuration: dispatch on the requested suite.
 */

$mdia_argv  = isset( $_SERVER['argv'] ) ? (array) $_SERVER['argv'] : array();
$mdia_index = array_search( '--testsuite', $mdia_argv, true );
$mdia_suite = false !== $mdia_index && isset( $mdia_argv[ $mdia_index + 1 ] ) ? $mdia_argv[ $mdia_index + 1 ] : 'unit';

require __DIR__ . ( 'integration' === $mdia_suite ? '/bootstrap-integration.php' : '/bootstrap-unit.php' );
