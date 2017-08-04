<?php

require 'vendor/autoload.php';


Layered\Wp\CustomPostType::$i18n = 'my-theme-or-plugin';


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
	->addColumns(['author'])
;


// Add Recipes - uses Custom Post Type & MetaBox
CustomPostType::add('recipe')
	->addTaxonomy('difficulty')
	->addColumns(['author'])
	->addMetaBox('Recipe Info', [[
		'id'			=>	'servings',
		'title'			=>	__('Servings', 'my-theme-or-plugin'),
		'placeholder'	=>	__('Ex: 1 cake, 2 servings, 5 cupcakes', 'my-theme-or-plugin')
	], [
		'id'			=>	'ingredients',
		'title'			=>	__('Ingredients', 'my-theme-or-plugin'),
		'placeholder'	=>	__('Ex: 200g quinoa, 2 tbsp olive oil', 'my-theme-or-plugin'),
		'multiple'		=>	true
	], [
		'id'			=>	'methods',
		'title'			=>	__('Methods', 'my-theme-or-plugin'),
		'placeholder'	=>	__('Ex: mix & stir the ingredients', 'my-theme-or-plugin'),
		'multiple'		=>	true
	], [
		'id'			=>	'time',
		'type'			=>	'number',
		'title'			=>	__('Prep time', 'my-theme-or-plugin'),
		'placeholder'	=>	__('Ex: 15', 'my-theme-or-plugin'),
		'suffix'		=>	'minutes'
	]])
;
