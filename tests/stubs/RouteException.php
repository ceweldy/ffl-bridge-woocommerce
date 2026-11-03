<?php
/**
 * Minimal copy of the WooCommerce Store API RouteException for unit tests.
 *
 * @package FFL_Bridge_WooCommerce
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\StoreApi\Exceptions;

/** Same constructor and accessors as the WooCommerce class. */
class RouteException extends \Exception {
	/** @var string */
	public $error_code;

	/** @var array<string, mixed> */
	public $additional_data = array();

	/** @param array<string, mixed> $additional_data Extra data. */
	public function __construct( $error_code, $message, $http_status_code = 400, $additional_data = array() ) {
		$this->error_code      = $error_code;
		$this->additional_data = (array) $additional_data;
		parent::__construct( $message, $http_status_code );
	}

	public function getErrorCode() {
		return $this->error_code;
	}

	/** @return array<string, mixed> */
	public function getAdditionalData() {
		return $this->additional_data;
	}
}
