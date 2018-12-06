<?php

use Layered\Wp\MetaFields;
use Layered\Wp\Q;


// Helper function - retrieve MetaFields instance

if (!function_exists('mf')) {
	function mf(): MetaFields {
		return MetaFields::instance();
	}
}


// Helper function - Queue action using Layered\Q

if (!function_exists('queue_action')) {
	function queue_action(string $tag, ...$args) {
		return Q::instance()->add($tag, $args);
	}
}


// Helper function - return the HTTP response in JSON format

if (!function_exists('wp_remote_retrieve_json')) {
	function wp_remote_retrieve_json(array $response) {
		$headers = wp_remote_retrieve_headers($response);
		$body = wp_remote_retrieve_body($response);

		return $body && strpos($headers->offsetGet('content-type'), 'application/json') !== false ? json_decode($body, true) : null;
	}
}


// Get visitor location - Country

if (!function_exists('getVisitorCountryCode')) {
	function getVisitorCountryCode(): string {
		return strtoupper(apply_filters('visitor_country_code', null));
	}
}

add_filter('visitor_country_code', 'layeredUserCountryCodeHeaders');
add_filter('visitor_country_code', 'layeredUserCountryCodeWooCommerce');
add_filter('visitor_country_code', 'layeredUserCountryCodeWordfence');

function layeredUserCountryCodeWooCommerce(string $countryCode = null): ?string {
	if (empty($countryCode) && class_exists('WC_Geolocation')) {
		$location = WC_Geolocation::geolocate_ip();
		if ($location['country'] && !in_array($location['country'], ['A1', 'A2', 'EU', 'AP'])) {
			$countryCode = $location['country'];
		}
	}

	return $countryCode;
}

function layeredUserCountryCodeHeaders(string $countryCode = null): ?string {
	if (empty($countryCode)) {
		foreach (['HTTP_CF_IPCOUNTRY', 'X-AppEngine-country', 'CloudFront-Viewer-Country', 'GEOIP_COUNTRY_CODE', 'HTTP_X_COUNTRY_CODE', 'HTTP_X_GEO_COUNTRY'] as $key) {
			if (isset($_SERVER[$key]) && $_SERVER[$key] && !in_array($_SERVER[$key], ['XX', 'ZZ', 'A1', 'A2', 'EU', 'AP'])) {
				return $_SERVER[$key];
			}
		}
	}

	return $countryCode;
}

function layeredUserCountryCodeWordfence(string $countryCode = null): ?string {
	if (empty($countryCode) && class_exists('wfUtils') && ($country = wfUtils::IP2Country(wfUtils::getIP())) && !in_array($country, ['A1', 'A2', 'EU', 'AP'])) {
		return $country;
	}

	return $countryCode;
}
