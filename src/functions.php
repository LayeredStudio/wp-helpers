<?php

use Layered\Wp\MetaFields;

if (!function_exists('mf')) {

	function mf() {
		return MetaFields::instance();
	}

}
