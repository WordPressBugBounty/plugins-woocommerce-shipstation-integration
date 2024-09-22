<?php
/**
 * Class WC_Safe_DOMDocument file.
 *
 * @package WC_ShipStation
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Drop in replacement for DOMDocument that is secure against XML eXternal Entity (XXE) Injection.
 * Bails if any DOCTYPE is found
 *
 * Comments in quotes come from the DOMDocument documentation: http://php.net/manual/en/class.domdocument.php
 */
class WC_Safe_DOMDocument extends DOMDocument {
	/**
	 * When called non-statically (as an object method) with malicious data, no Exception is thrown, but the object is emptied of all DOM nodes.
	 *
	 * @param string $filename The path to the XML document.
	 * @param int    $options  Bitwise OR of the libxml option constants. http://us3.php.net/manual/en/libxml.constants.php.
	 *
	 * @return bool|DOMDocument true on success, false on failure.  If called statically (E_STRICT error), returns DOMDocument on success.
	 *
	 * @throws Exception When the file is empty or not readable.
	 */
	public function load( $filename, $options = 0 ) {
		if ( '' === $filename ) {
			// "If an empty string is passed as the filename or an empty file is named, a warning will be generated."
			// "This warning is not generated by libxml and cannot be handled using libxml's error handling functions."
			throw new Exception( 'WC_Safe_DOMDocument::load(): Empty string supplied as input' );
		}

		if ( ! is_file( $filename ) || ! is_readable( $filename ) ) {
			// This warning probably would have been generated by libxml and could have been handled using libxml's error handling functions.
			// In WC_Safe_DOMDocument, however, we catch it before libxml, so it can't.
			// The alternative is to let file_get_contents() handle the error, but that's annoying.
			throw new Exception( 'WC_Safe_DOMDocument::load(): I/O warning : failed to load external entity "' . esc_html( sanitize_file_name( $filename ) ) . '"' );
		}

		if ( is_object( $this ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents --- Need to use this function to get the content on the file
			return $this->loadXML( file_get_contents( $filename ), $options );
		} else {
			// "This method *may* be called statically, but will issue an E_STRICT error."
			return self::loadXML( file_get_contents( $filename ), $options ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents  --- Need to use this function to get the content on the file
		}
	}

	/**
	 * When called non-statically (as an object method) with malicious data, no Exception is thrown, but the object is emptied of all DOM nodes.
	 *
	 * @param string $source  The string containing the XML.
	 * @param int    $options Bitwise OR of the libxml option constants. http://us3.php.net/manual/en/libxml.constants.php.
	 *
	 * @return bool|DOMDocument true on success, false on failure.  If called statically (E_STRICT error), returns DOMDocument on success.
	 *
	 * @throws Exception When the file is empty or not readable.
	 *
	 * @todo Once PHP 8.0 is the minimum version, remove all libxml_disable_entity_loader() calls and the $old variable.
	 * @see  https://www.php.net/manual/en/function.libxml-disable-entity-loader.php#125661
	 */
	public function loadXML( $source, $options = 0 ) {
		if ( '' === $source ) {
			// "If an empty string is passed as the source, a warning will be generated."
			// "This warning is not generated by libxml and cannot be handled using libxml's error handling functions."
			throw new Exception( 'WC_Safe_DOMDocument::loadXML(): Empty string supplied as input' );
		}

		$old = null;

		if ( function_exists( 'libxml_disable_entity_loader' ) && \PHP_VERSION_ID < 80000 ) {
			$old = libxml_disable_entity_loader( true ); // phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated --- This is the only way to disable the entity loader in versions of PHP prior to 8.0.
		}

		$return = parent::loadXML( $source, $options );

		if ( ! is_null( $old ) && \PHP_VERSION_ID < 80000 ) {
			libxml_disable_entity_loader( $old ); // phpcs:ignore Generic.PHP.DeprecatedFunctions.Deprecated --- This is the only way to disable the entity loader in versions of PHP prior to 8.0.
		}

		if ( ! $return ) {
			return $return;
		}

		// "This method *may* be called statically, but will issue an E_STRICT error."
		$is_this = is_object( $this );

		$object = $is_this ? $this : $return;

		if ( isset( $object->doctype ) ) {
			if ( $is_this ) {
				// Get rid of the dangerous input by removing *all* nodes.
				while ( $this->firstChild ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
					$this->removeChild( $this->firstChild ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				}
			}

			throw new Exception( 'WC_Safe_DOMDocument::loadXML(): Unsafe DOCTYPE Detected' );
		}

		return $return;
	}
}
