WordPress Helpers
=========

**WP Helpers** is a collection of classes and librariers for easier WordPress development.


## Features and use cases
* **CustomPostType** - Register a new post type. It fills or generates values and post types can be added with one line of code, ex: `CustomPostType::add('idea');`
* **PostMetaBox** - Create good looking and functional meta boxes for post types

## How to use

#### Installation

Add `layered/wp-helpers` as a require dependency in your `composer.json` file:
``` bash
$ composer require layered/wp-helpers
```

#### Register post type

For each post type, create a `CustomPostType` instance with name as first argument:
```
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

## More

Please report any issues here on GitHub.

[Any contributions are welcome](CONTRIBUTING.md)
