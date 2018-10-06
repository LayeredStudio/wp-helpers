<?php
namespace Layered\Wp;

class Inflector {

	public static function humanize(string $string, array $delimiter = ['_']): string {
		$result = explode(' ', str_replace($delimiter, ' ', $string));

		foreach ($result as &$word) {
			$word = mb_strtoupper(mb_substr($word, 0, 1)) . mb_substr($word, 1);
		}

		return implode(' ', $result);
	}

	public static function pluralize(string $word): string {

		// processing here

		return $word . 's';
	}

}
