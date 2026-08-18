<?php
/**
 * Provider registry and host-based adapter selection.
 *
 * @package CourtneyRDev\SocialWebmentionImporter
 */

namespace CourtneyRDev\SocialWebmentionImporter\Provider;

/**
 * Owns the adapter list and picks the adapter for a URL.
 */
class Provider_Registry {

	/**
	 * Registered adapters, consulted in order; Generic must stay last.
	 *
	 * @var Provider[]
	 */
	protected $providers = array();

	/**
	 * Build the default registry.
	 */
	public function __construct() {
		$providers = array(
			new X_Provider(),
			new LinkedIn_Provider(),
			new Generic_Provider(),
		);

		/**
		 * Filters the provider adapter list.
		 *
		 * Adapters earlier in the list win. The Generic adapter should stay
		 * last because it claims every URL.
		 *
		 * @param Provider[] $providers Default adapters.
		 */
		$this->providers = apply_filters( 'swi_providers', $providers );
	}

	/**
	 * Pick the adapter for a URL.
	 *
	 * @param string $url Absolute URL.
	 * @return Provider
	 */
	public function for_url( $url ) {
		foreach ( $this->providers as $provider ) {
			if ( $provider instanceof Provider && $provider->handles( $url ) ) {
				return $provider;
			}
		}
		return new Generic_Provider();
	}
}
