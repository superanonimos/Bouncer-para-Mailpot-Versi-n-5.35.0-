<?php
declare(strict_types=1);

use Isolated\Symfony\Component\Finder\Finder;

return [
	'prefix' => 'Publikate\\MailPoetBouncer\\Scoped',

	'finders' => [
		Finder::create()
			->files()
			->ignoreVCS(true)
			->exclude([
				'doc',
				'test',
				'test_old',
				'tests',
				'Tests',
				'vendor-bin',
			])
			->notName('LICENSE')
			->notName('*.md')
			->notName('*.dist')
			->notName('Makefile')
			->notName('composer.json')
			->notName('composer.lock')
			->in('vendor'),
	],

	'patchers' => [],

	'exclude-namespaces' => [
		'Publikate\\MailPoetBouncer',
		'MailPoet',
		'WP_CLI',
	],

	'exclude-classes' => [
		'wpdb',
		'WP_Error',
		'WP_CLI',
		'DateTime',
		'DateTimeImmutable',
		'DateTimeZone',
		'Exception',
		'RuntimeException',
		'InvalidArgumentException',
		'Throwable',
	],

	'exclude-functions' => [
		'/^wp_.*/',
		'/^esc_.*/',
		'/^sanitize_.*/',
		'/^add_.*/',
		'/^remove_.*/',
		'/^plugin_.*/',
		'/^register_.*/',
		'current_user_can',
		'check_ajax_referer',
		'current_time',
		'dbDelta',
		'absint',
	],

	'exclude-constants' => [
		'/^WP_.*/',
		'/^PKMB_.*/',
		'ABSPATH',
		'MINUTE_IN_SECONDS',
		'HOUR_IN_SECONDS',
		'DAY_IN_SECONDS',
		'IMAP_OPENTIMEOUT',
		'IMAP_READTIMEOUT',
		'FT_PREFETCHTEXT',
		'FT_PEEK',
	],

	'expose-global-constants' => false,
	'expose-global-classes'   => false,
	'expose-global-functions' => false,
];