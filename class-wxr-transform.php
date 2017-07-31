<?php
/**
 * Transform from WXR 1.0, 1.1 or 1.2 instance into a proposed WXR 1.3 instance
 *
 * There is a very simple XSLT transform that accomplishes this; however,
 * since this importer is intended to be a 'true' streaming parser, that XSLT
 * transform is not a viable solution, since PHP's implementation of XSLT
 * must load the source document completely into memory (as either a DOMDocument
 * or a SimpleXML).
 *
 * The XSLT version of this transform is available at https://github.com/pbiron/wxr/1.3-proposed/transforms.
 * It is available there in 4 different versions: XSLT 3.0 (which should be considered
 * the version this class implements), XSLT 2.0 and 2 different implementations for
 * XSLT 1.0.
 *
 * And to boot, the XSLT 3.0 version of the transform is even guaranteed-streamable
 * as specified in https://www.w3.org/TR/xslt-30/#dt-guaranteed-streamable.
 *
 * Unfortunately, PHP's XSLTProcessor class can only handle XSLT 1.0 transforms.
 * Given that XSLT 2.0 is 10 years old, there is little chance that PHP will support
 * XSLT 3.0 any time soon (if ever) :-(  Hense, the need for this class.
 *
 * WXR_Importer_Transform_WXR::transform_wxr() is intended to be called
 * from within a function hooked to `admin_action_wxr-import-upload'...so that
 * WXR_Importer can seemlessly handle "native" WXR 1.3 instances as well as
 * old WXR 1.0, 1.1 or 1.2 instances.
 *
 * @author pbiron
 */
class Transform_WXR {
	/**
	 * The WXR namespace URI
	 * @var string
	 */
	const WXR_NAMESPACE_URI = 'http://wordpress.org/export/';

	/**
	 * The prefix to use for elements/attributes in the WXR namespace
	 * @var string
	 */
	const WXR_PREFIX = 'wxr';

	/**
	 * The version of WXR we transform into
	 * @var string
	 */
	const WXR_VERSION = '1.3';

	/**
	 * Original filename
	 * @var unknown
	 */
	protected $org_filename;

	/**
	 * Name of temporary file.
	 * @var string
	 */
	protected $tempnam ;

	/**
	 * Check whether a namespaceURI is one that has been used for WXR
	 *
	 * @param string $namespaceURI The namespaceURI to check.
	 * @param bool $accept_excerpt_ns Whether to accept the WXR 'excerpt' namespaceURIs.
	 * @return bool
	 */
	protected function is_wxr_namespaceURI( $namespaceURI, $accept_excerpt_ns = false ) {
		$regex = '#^' . self::WXR_NAMESPACE_URI . '\d+\.\d+/';

		if ( ! preg_match( "{$regex}#", $namespaceURI ) ) {
			return false;
		}

		if ( ! $accept_excerpt_ns && preg_match( "{$regex}excerpt/$#", $namespaceURI ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Get a stream reader for the file.
	 *
	 * @param string $file Path to the XML file.
	 * @return XMLReader|WP_Error Reader instance on success, error otherwise.
	 */
	protected function get_reader( $file ) {
		// Avoid loading external entities for security
		$old_value = null;
		if ( function_exists( 'libxml_disable_entity_loader' ) ) {
			// $old_value = libxml_disable_entity_loader( true );
		}

		$reader = new XMLReader();
		$status = $reader->open( $file );

		if ( ! is_null( $old_value ) ) {
			// libxml_disable_entity_loader( $old_value );
		}

		if ( ! $status ) {
			return new WP_Error( 'wxr_importer.cannot_parse', __( 'Could not open the file for parsing', 'wordpress-importer' ) );
		}

		return $reader;
	}

	/**
	 * Get a stream writer for output of the transform file.
	 *
	 * @param string $file Path to the XML file.
	 * @return XMLReader|WP_Error Writer instance on success, error otherwise.
	 */
	function get_writer( $file ) {
		$writer = new XMLWriter();
		$this->tempnam = tempnam( dirname( $file ), 'transform' );

		$status = $writer->openUri( $this->tempnam );
		if ( ! $status ) {
			return new WP_Error( 'wxr_importer.cannot_transform', __( 'Could not open the file for writing', 'wordpress-importer' ) );
		}

		$writer->setIndent( true );
		$writer->setIndentString( "\t" );

		return $writer;
	}

	/**
	 *
	 * @param XMLReader $reader
	 * @param XMLWriter $writer
	 * @param array $parent
	 * @return boolean
	 */
	function startElement( $reader, $writer, $parent ) {
		if ( $this->is_wxr_namespaceURI( $reader->namespaceURI, true ) ) {
			// element is from one of the old WXR namespaces

			if ( in_array( $reader->localName, array( 'wxr_version', 'base_blog_url' ) ) ) {
				// skip the old wp:wxr_version & base_blog_url elements
				$reader->next();

				return true;
			}
			elseif ( 'encoded' === $reader->localName ) {
				// rewrite <excerpt:encoded> to RSS standard <description>
				$writer->startElement( 'description' );

				return true;
			}

			$localName = $this->map_localName( $reader, $parent );

			$writer->startElementNs( self::WXR_PREFIX, $localName, null );
		}
		elseif ( '' !== $reader->namespaceURI ) {
			// element is from a "foreign" namespace, e.g., dublin core, rss_content
			// or a plugin-specific namespace...just copy the start tag unchanged

			$writer->startElementNs( $reader->prefix, $reader->localName, null );
		}
		else {
			// element is from the empty namespace, i.e., standard RSS

			if ( isset( $parent['localName'] ) &&
					'' === $parent['namespaceURI'] && 'item' === $parent['localName'] &&
					'description' === $reader->localName ) {
				// skip item/description element
				$reader->next();

				return true;
			}

			$writer->startElement( $reader->localName );

			if ( 'generator' === $reader->localName ) {
				$wp_version = $reader->readString();
				$wp_version = substr( $wp_version, stripos( $wp_version, '?v=' ) + 3 );
				$writer->writeAttributeNS( 'wxr', 'wp_version', null, $wp_version );

				$writer->endElement();
				$reader->next();
			}

			if ( 'rss' === $reader->localName && 0 === $reader->depth ) {
				if ( $reader->moveToAttributeNS( 'version', self::WXR_NAMESPACE_URI ) ) {
					// file is already in the WXR 1.3 (or later) markup, no need to transform
					return false;
				}

				// passing the WXR namespaceURI here will cause XMLWriter to also
				// generate a namespace decl for it.  After this, we always pass
				// null for our namespace; otherwise, XMLWriter will generate additional
				// namespace decls for it...which, while perfectly fine from a
				// namespace-aware XML perspective, it results in instances that are
				// much larger than they need to be.
				// @link https://bugs.php.net/bug.php?id=74491
				$writer->writeAttributeNS( self::WXR_PREFIX, 'version', self::WXR_NAMESPACE_URI, '1.3' );
			}
		}

		// save whether we are currently processing an RSS category element
		// so that we can rewrite @nicename
		$category = '' === $reader->namespaceURI && $reader->localName;

		if ( $reader->hasAttributes ) {
			$this->transformAttributes( $reader, $writer, $category );
		}


		if ( $reader->isEmptyElement ) {
			$writer->endElement();
		}

		return true;
	}

	/**
	 *
	 * @param XMLReader $reader
	 * @param XMLWriter $writer
	 * @param bool $is_rss_category
	 */
	function transformAttributes( $reader, $writer, $is_rss_category ) {
		// save whether we are currently processing an RSS category element
		// so that we can rewrite @nicename
		$is_rss_category = '' === $reader->namespaceURI && $reader->localName;

		while ( $reader->moveToNextAttribute() ) {
			if ( '' === $reader->namespaceURI ) {
				if ( $is_rss_category && 'nicename' === $reader->name ) {
					// rewrite category/@nicename to category/@wxr:slug
					$writer->writeAttributeNs( self::WXR_PREFIX, 'slug', null, $reader->value );
				}
				else {
					$writer->writeAttribute( $reader->name, $reader->value );
				}
			}
			elseif ( 'http://www.w3.org/2000/xmlns/' === $reader->namespaceURI ) {
				// stupid XMLReader treats namespace decls as attributes :-(
				if ( $this->is_wxr_namespaceURI( $reader->value, true ) ) {
					// strip the old WXR namespace decl
					continue;
				}

				// because of what I consider a bug in XMLWriter,
				// if you write these namespace decl "attributes" via
				// XMLWriter::writeAttributeNs() then it generates an
				// illegal xmlns:xmlns='http://www.w3.org/2000/xmlns/'
				$writer->writeAttribute( $reader->name, $reader->value );
			}
			else {
				// namespace-qualified attribute
				if ( $reader->lookupNamespace( $reader->prefix ) ) {
					$writer->writeAttributeNs( $reader->prefix, $reader->localName, null, $reader->value );
				}
				else {
					$writer->writeAttributeNs( $reader->prefix, $reader->localName, $reader->namespaceURI, $reader->value );
				}
			}
		}

		$reader->moveToElement();
	}

	/**
	 * Transform a WXR 1.0, 1.1 or 1.2 instance into the proposed 1.3 markup
	 *
	 * @param string $file ??
	 * @return boolean|WP_Error true if transformation performed, false if no transformation necessary,
	 *                          WP_Error on error
	 */
	function transform( $file ) {
		$this->org_filename = $file;

		$reader = $this->get_reader( $file );
		if ( is_wp_error( $reader ) ) {
			return $reader;
		}

		$writer = $this->get_writer( $file );
		if ( is_wp_error( $writer ) ) {
			return $writer;
		}

		// @todo XMLReader::read() never returns XMLReader::XML_DECLARATION
		//       so we just have to "guess" and hardcode the xmldecl
		//       I don't think it really matters because XMLReader will do any necessary
		//       decoding
		$writer->startDocument( '1.0', 'UTF-8' );

		$ancestors = array();
		while ( $reader->read() ) {
			switch ( $reader->nodeType ) {
				case XMLReader::ELEMENT:
					if ( ! $this->startElement( $reader, $writer, count( $ancestors ) > 0 ? $ancestors[0] : null ) ) {
						$this->cleanup( $reader, $writer, false );

						return false;
					}

					array_unshift( $ancestors,
						array(
							'namespaceURI' => $reader->namespaceURI,
							'localName' => $reader->localName,
							)
					);

					break;

				case XMLReader::CDATA:
				case XMLReader::TEXT:
					// outputing CDATA sections when not necessary needlessly increases file size
					$writer->text( $reader->value );

					break;

				case XMLReader::END_ELEMENT:
					if ( $this->is_wxr_namespaceURI( $reader->namespaceURI, true ) ) {
						if ( in_array( $reader->localName, array( 'tag', 'category' ) ) ) {
							// add the appropriate <wxr:taxonomy> before closing the <wxr:term>
							$writer->startElementNs( self::WXR_PREFIX, 'taxonomy', null );

							$writer->text( 'category' === $reader->localName ? 'category' : 'post_tag' );

							$writer->endElement();
						}
					}

					$writer->endElement();

					array_shift( $ancestors );

					break;

				case XMLReader::COMMENT:
					$writer->writeComment( $reader->value );

					break;

				case XMLReader::PI:
					// the standard exporter doesn't generate any PIs and it is unlikely a plugin
					// will either, but just in case...
					$writer->writePI( $reader->localName, $reader->value );

					break;
			}
		}

		$this->cleanup( $reader, $writer, true );

		return true;
	}

	/**
	 * This implements the <xsl:variable> statement in the template with
	 * @match='*[starts-with( namespace-uri(), "http://wordpress.org/export/" )]'>
	 * in the XSLT transforms this class mimics.
	 *
	 * @param XMLReader $reader
	 * @param array $parent
	 * @return string
	 */
	function map_localName( $reader, $parent ) {
		// first, start with the simple renames
		if ( in_array( $reader->localName, array( 'tag', 'category' ) ) ) {
			return 'term';
		}
		elseif ( 'author' === $reader->localName ) {
			return 'user';
		}
		elseif ( in_array( $reader->localName, array( 'termmeta', 'postmeta', 'commentmeta' ) ) ) {
			return 'meta';
		}
		elseif ( 'category_nicename' === $reader->localName ) {
			return 'slug';
		}

// 		// even tho this keeps the current local-name(), we have to do it here
// 		// rather than in <xsl:otherwise/> or else it would get handled by
// 		// the "strip a leading" <xsl:when/>

// 		if ( 'comment_status' === $reader->localName ) {
// 			return $reader->localName;
// 		}

		// store the location of the 1st '_' character.  We need it several times below
		// and this saves up having to call strpos() multiple times
		$_underscore = strpos( $reader->localName, '_' ) + 1;

		// strip leading string before "_" in SOME localName's
		if ( ( in_array(
				substr ( $reader->localName, 0, $_underscore ),
				array( 'author_', 'post_', 'meta_', 'comment_', 'base_' ) ) &&
						'comment_status' !== $reader->localName ) ||
				( $this->is_wxr_namespaceURI( $parent['namespaceURI'] ) &&
					in_array( $parent['localName'], array( 'tag', 'category', 'term' ) ) ) ) {
			return substr( $reader->localName, $_underscore );
		}

		// localName is unchanged
		return $reader->localName;
	}

	/**
	 * Cleanup at the end of the transform
	 *
	 * If $success is true, then rename the temporary file to the original file;
	 * If $success is false, then unlink the temporary file and the original file
	 * in tact.
	 *
	 * @param XMLReader $reader
	 * @param XMLWriter $writer
	 * @param bool $success
	 * @param string $file
	 */
	function cleanup( $reader, &$writer, $success ) {
		$reader->close();

		$writer->endDocument();

		// set $writer to null so that it closes the file...otherwise
		// the rename() will fail!  The $writer parameter to this method must be
		// declared 'by-reference' for this to work.
		$writer = null;

		if ( $success ) {
			rename( $this->tempnam, $this->org_filename );
		}
		else {
			unlink( $this->tempnam );
		}
	}
}