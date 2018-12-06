WordPress Helpers
=========

**WP Helpers** is a collection of classes and librariers for easier WordPress development.


## Features and use cases
* **CustomPostType** - Register a new post type. It fills or generates values and post types can be added with one line of code, ex: `CustomPostType::add('idea');`
* **MetaFields** - Easily add meta fields to Posts, Terms, Comments and Users, ex: `mf()->addPostMeta('number-field', ['name' => 'Number Field', type' => 'number'])`
* **Q** - Process WP actions in background without blocking the current request. Usage: `queue_action('resources_hungry_action', 'data', 4, 'action')`
* **PostMetaBox** - Create good looking and functional meta boxes for post types

## How to use

#### Installation

Add `layered/wp-helpers` as a require dependency in your `composer.json` file:
```bash
$ composer require layered/wp-helpers
```

#### Register post type

Example of adding a Post Type, create a `CustomPostType` instance with name as first argument:
```php
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
```

#### Meta Fields

Meta Fields are custom fields that can be easily added to Posts, Terms, Comments and Users. They are registered with default WordPress flow, showing up as columns in list views, editable fields on edit pages, editable fields for Quick/Bulk edit screens and REST Api:
```php
MetaFields::instance()->addPostMeta('second-heading', [
	'name'				=>	'Second Heading',
	'type'				=>	'text',
	'placeholder'		=>	'Heading for article',
	'show_in_rest'		=>	true,
	'showInMetaBox'		=>	true,
	'showInColumns'		=>	true,
	'showInQuickEdit'	=>	true,
	'showInBulkEdit'	=>	true
]);
```

#### Q

Q adds support for asynchronous actions in plugins and themes. Simple use, only include the file and switch from `do_action()` to `queue_action()`. Processing is handled in background, making web requests load qicker. Example:
```php
// keeps request hanging until action is complete
do_action('resource_hungry_action', 'data', 4, 'action');

// queues the action to be handled in background
queue_action('resource_hungry_action', 'data', 4, 'action');
```

## More

Please report any issues here on GitHub.

[Any contributions are welcome](CONTRIBUTING.md)
