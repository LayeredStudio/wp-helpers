<?php

use Layered\Wp\MetaFields;

if (!function_exists('mf')) {

	function mf() {
		return MetaFields::instance();
	}

}



// Get visitor location - Country

if (!function_exists('getVisitorCountryCode')) {
	function getVisitorCountryCode(): string {
		return strtoupper(apply_filters('visitor_country_code', null));
	}
}

add_filter('visitor_country_code', 'layeredUserCountryCodeWooCommerce');
add_filter('visitor_country_code', 'layeredUserCountryCodeHeaders');

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

