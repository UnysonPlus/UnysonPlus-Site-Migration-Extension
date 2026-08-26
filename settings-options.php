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
					'keep_archives'           => [
						'label' => __( 'Archives to keep', 'fw' ),
						'desc'  => __(
							'How many finished archives to keep on disk before the oldest is deleted. Each archive is a full copy of the site, so this is the setting that decides how much disk the extension can consume.',
							'fw'
						),
						'type'  => 'short-select',
						'value' => '3',
						'choices' => [
							'1' => __( 'Only the latest', 'fw' ),
							'2' => '2',
							'3' => '3',
							'5' => '5',
							'10' => '10',
						],
					],
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
