<?php

require 'vendor/autoload.php';

use Layered\Wp\CustomPostType;


add_action('init', function() {

	// Add a custom post type
	CustomPostType::add('idea', [
		'labels'	=>	[
			'name'	=>	__('Ideas', 'my-theme-or-plugin')
		],
		'rewrite'	=>	[
			'slug'	=>	'my-ideas'
		],
		'supports'	=>	['title', 'editor', 'thumbnail', 'excerpt', 'author']
	])
		->addTaxonomy('tag')
	;


	// Add Recipes - uses Custom Post Type & Meta Fields
	CustomPostType::add('recipe')
		->addTaxonomy('difficulty')
		->addMetaFields([
			'servings'		=>	[
				'name'				=>	__('Servings', 'my-theme-or-plugin'),
				'placeholder'		=>	__('Ex: 1 cake, 2 servings, 5 cupcakes', 'my-theme-or-plugin')
			],
			'ingredients'	=>	[
				'name'				=>	__('Ingredients', 'my-theme-or-plugin'),
				'placeholder'		=>	__('Ex: 200g quinoa, 2 tbsp olive oil', 'my-theme-or-plugin'),
				'single'			=>	false
			],
			'methods'		=>	[
				'name'				=>	__('Methods', 'my-theme-or-plugin'),
				'placeholder'		=>	__('Ex: mix & stir the ingredients', 'my-theme-or-plugin'),
				'single'			=>	false
			],
			'time'			=>	[
				'type'				=>	'number',
				'name'				=>	__('Prep time', 'my-theme-or-plugin'),
				'placeholder'		=>	__('Ex: 15', 'my-theme-or-plugin'),
				'suffix'			=>	'minutes',
				'show_in_columns'	=>	true
			]
		])
	;

});
