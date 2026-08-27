<?php if ( ! defined( 'FW' ) ) {
	die( 'Forbidden' );
}

$options = [
	'general_box' => [
		'title'   => __( 'Migration', 'fw' ),
		'type'    => 'box',
		'options' => [
			'group_general' => [
				'type'    => 'group',
				'options' => [
					'install_compat_muplugin' => [
						'label' => __( 'Compatibility mode', 'fw' ),
						'desc'  => __(
							'Installs a small must-use plugin that trims the active plugin list and swaps in a stub theme for the duration of a migration request only. Other plugins then cannot interfere with a long-running slice or blow its memory budget. Normal page loads are untouched.',
							'fw'
						),
						'type'  => 'switch',
						'value' => true,
					],
				],
			],
		],
	],
];
