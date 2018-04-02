<?php
/**
 * Class AMP_Style_Sanitizer
 *
 * @package AMP
 */

use \Sabberworm\CSS\RuleSet\DeclarationBlock;
use \Sabberworm\CSS\CSSList\CSSList;
use \Sabberworm\CSS\Property\Selector;
use \Sabberworm\CSS\RuleSet\RuleSet;
use \Sabberworm\CSS\Rule\Rule;
use \Sabberworm\CSS\Property\AtRule;
use \Sabberworm\CSS\CSSList\KeyFrame;
use \Sabberworm\CSS\RuleSet\AtRuleSet;
use \Sabberworm\CSS\Property\Import;
use \Sabberworm\CSS\CSSList\AtRuleBlockList;
use \Sabberworm\CSS\Value\RuleValueList;
use \Sabberworm\CSS\Value\URL;

/**
 * Class AMP_Style_Sanitizer
 *
 * Collects inline styles and outputs them in the amp-custom stylesheet.
 */
class AMP_Style_Sanitizer extends AMP_Base_Sanitizer {

	/**
	 * Styles.
	 *
	 * List of CSS styles in HTML content of DOMDocument ($this->dom).
	 *
	 * @since 0.4
	 * @var array[]
	 */
	private $styles = array();

	/**
	 * Stylesheets.
	 *
	 * Values are the CSS stylesheets. Keys are MD5 hashes of the stylesheets
	 *
	 * @since 0.7
	 * @var string[]
	 */
	private $stylesheets = array();

	/**
	 * Current amp-custom CSS size.
	 *
	 * Sum of CSS located in $styles and $stylesheets.
	 *
	 * @var int
	 */
	private $current_custom_size = 0;

	/**
	 * Spec for style[amp-custom] cdata.
	 *
	 * @since 1.0
	 * @var array
	 */
	private $style_custom_cdata_spec;

	/**
	 * The style[amp-custom] element.
	 *
	 * @var DOMElement
	 */
	private $amp_custom_style_element;

	/**
	 * The found style[amp-keyframe] stylesheets.
	 *
	 * @since 1.0
	 * @var string[]
	 */
	private $keyframes_stylesheets = array();

	/**
	 * Current amp-keyframes CSS size.
	 *
	 * @since 1.0
	 * @var int
	 */
	private $current_keyframes_size = 0;

	/**
	 * Spec for style[amp-keyframes] cdata.
	 *
	 * @since 1.0
	 * @var array
	 */
	private $style_keyframes_cdata_spec;

	/**
	 * Regex for allowed font stylesheet URL.
	 *
	 * @var string
	 */
	private $allowed_font_src_regex;

	/**
	 * Base URL for styles.
	 *
	 * Full URL with trailing slash.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * URL of the content directory.
	 *
	 * @var string
	 */
	private $content_url;

	/**
	 * Class names used in document.
	 *
	 * @since 1.0
	 * @var array
	 */
	private $used_class_names = array();

	/**
	 * XPath.
	 *
	 * @since 1.0
	 * @var DOMXPath
	 */
	private $xpath;

	/**
	 * Amount of time that was spent parsing CSS.
	 *
	 * @since 1.0
	 * @var float
	 */
	private $parse_css_duration = 0.0;

	/**
	 * AMP_Base_Sanitizer constructor.
	 *
	 * @since 0.7
	 *
	 * @param DOMDocument $dom  Represents the HTML document to sanitize.
	 * @param array       $args Args.
	 */
	public function __construct( DOMDocument $dom, array $args = array() ) {
		parent::__construct( $dom, $args );

		foreach ( AMP_Allowed_Tags_Generated::get_allowed_tag( 'style' ) as $spec_rule ) {
			if ( ! isset( $spec_rule[ AMP_Rule_Spec::TAG_SPEC ]['spec_name'] ) ) {
				continue;
			}
			if ( 'style[amp-keyframes]' === $spec_rule[ AMP_Rule_Spec::TAG_SPEC ]['spec_name'] ) {
				$this->style_keyframes_cdata_spec = $spec_rule[ AMP_Rule_Spec::CDATA ];
			} elseif ( 'style amp-custom' === $spec_rule[ AMP_Rule_Spec::TAG_SPEC ]['spec_name'] ) {
				$this->style_custom_cdata_spec = $spec_rule[ AMP_Rule_Spec::CDATA ];
			}
		}

		$spec_name = 'link rel=stylesheet for fonts'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
		foreach ( AMP_Allowed_Tags_Generated::get_allowed_tag( 'link' ) as $spec_rule ) {
			if ( isset( $spec_rule[ AMP_Rule_Spec::TAG_SPEC ]['spec_name'] ) && $spec_name === $spec_rule[ AMP_Rule_Spec::TAG_SPEC ]['spec_name'] ) {
				$this->allowed_font_src_regex = '@^(' . $spec_rule[ AMP_Rule_Spec::ATTR_SPEC_LIST ]['href']['value_regex'] . ')$@';
				break;
			}
		}

		$guessurl = site_url();
		if ( ! $guessurl ) {
			$guessurl = wp_guess_url();
		}
		$this->base_url    = $guessurl;
		$this->content_url = WP_CONTENT_URL;
		$this->xpath       = new DOMXPath( $dom );
	}

	/**
	 * Get list of CSS styles in HTML content of DOMDocument ($this->dom).
	 *
	 * @since 0.4
	 *
	 * @return array[] Mapping CSS selectors to array of properties, or mapping of keys starting with 'stylesheet:' with value being the stylesheet.
	 */
	public function get_styles() {
		if ( ! $this->did_convert_elements ) {
			return array();
		}
		return $this->styles;
	}

	/**
	 * Get stylesheets.
	 *
	 * @since 0.7
	 * @returns array Values are the CSS stylesheets. Keys are MD5 hashes of the stylesheets.
	 */
	public function get_stylesheets() {
		return array_merge( $this->stylesheets, parent::get_stylesheets() );
	}

	/**
	 * Get list of all the class names used in the document.
	 *
	 * @since 1.0
	 * @return array Used class names.
	 */
	private function get_used_class_names() {
		$classes = ' ';
		foreach ( $this->xpath->query( '//*/@class' ) as $class_attribute ) {
			$classes .= ' ' . $class_attribute->nodeValue;
		}
		return array_unique( array_filter( preg_split( '/\s+/', trim( $classes ) ) ) );
	}

	/**
	 * Sanitize CSS styles within the HTML contained in this instance's DOMDocument.
	 *
	 * @since 0.4
	 */
	public function sanitize() {
		$elements = array();

		// Do nothing if inline styles are allowed.
		if ( ! empty( $this->args['allow_dirty_styles'] ) ) {
			return;
		}

		$this->used_class_names = $this->get_used_class_names();

		/*
		 * Note that xpath is used to query the DOM so that the link and style elements will be
		 * in document order. DOMNode::compareDocumentPosition() is not yet implemented.
		 */
		$xpath = $this->xpath;

		$lower_case = 'translate( %s, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz" )'; // In XPath 2.0 this is lower-case().
		$predicates = array(
			sprintf( '( self::style and not( @amp-boilerplate ) and ( not( @type ) or %s = "text/css" ) )', sprintf( $lower_case, '@type' ) ),
			sprintf( '( self::link and @href and %s = "stylesheet" )', sprintf( $lower_case, '@rel' ) ),
		);

		foreach ( $xpath->query( '//*[ ' . implode( ' or ', $predicates ) . ' ]' ) as $element ) {
			$elements[] = $element;
		}

		/**
		 * Element.
		 *
		 * @var DOMElement $element
		 */
		foreach ( $elements as $element ) {
			$node_name = strtolower( $element->nodeName );
			if ( 'style' === $node_name ) {
				$this->process_style_element( $element );
			} elseif ( 'link' === $node_name ) {
				$this->process_link_element( $element );
			}
		}

		$elements = array();
		foreach ( $xpath->query( '//*[ @style ]' ) as $element ) {
			$elements[] = $element;
		}
		foreach ( $elements as $element ) {
			$this->collect_inline_styles( $element );
		}

		$this->finalize_amp_keyframes_styles();

		$this->did_convert_elements = true;

		// Now make sure the amp-custom style is in the DOM and populated, if we're working with the document element.
		if ( ! empty( $this->args['use_document_element'] ) ) {
			if ( ! $this->amp_custom_style_element ) {
				$this->amp_custom_style_element = $this->dom->createElement( 'style' );
				$this->amp_custom_style_element->setAttribute( 'amp-custom', '' );
				$head = $this->dom->getElementsByTagName( 'head' )->item( 0 );
				if ( ! $head ) {
					$head = $this->dom->createElement( 'head' );
					$this->dom->documentElement->insertBefore( $head, $this->dom->documentElement->firstChild );
				}
				$head->appendChild( $this->amp_custom_style_element );
			}

			$css = implode( '', $this->get_stylesheets() );

			/*
			 * Let the style[amp-custom] be populated with the concatenated CSS.
			 * !important: Updating the contents of this style element by setting textContent is not
			 * reliable across PHP/libxml versions, so this is why the children are removed and the
			 * text node is then explicitly added containing the CSS.
			 */
			while ( $this->amp_custom_style_element->firstChild ) {
				$this->amp_custom_style_element->removeChild( $this->amp_custom_style_element->firstChild );
			}
			$this->amp_custom_style_element->appendChild( $this->dom->createTextNode( $css ) );
		}

		if ( $this->parse_css_duration > 0.0 ) {
			AMP_Response_Headers::send_server_timing( 'amp_parse_css', $this->parse_css_duration, 'AMP Parse CSS' );
		}
	}

	/**
	 * Generates an enqueued style's fully-qualified file path.
	 *
	 * @since 0.7
	 * @see WP_Styles::_css_href()
	 *
	 * @param string $src The source URL of the enqueued style.
	 * @return string|WP_Error Style's absolute validated filesystem path, or WP_Error when error.
	 */
	public function get_validated_css_file_path( $src ) {
		$needs_base_url = (
			! is_bool( $src )
			&&
			! preg_match( '|^(https?:)?//|', $src )
			&&
			! ( $this->content_url && 0 === strpos( $src, $this->content_url ) )
		);
		if ( $needs_base_url ) {
			$src = $this->base_url . $src;
		}

		// Strip query and fragment from URL.
		$src = preg_replace( ':[\?#].*$:', '', $src );

		if ( ! preg_match( '/\.(css|less|scss|sass)$/i', $src ) ) {
			/* translators: %s is stylesheet URL */
			return new WP_Error( 'amp_css_bad_file_extension', sprintf( __( 'Skipped stylesheet which does not have recognized CSS file extension (%s).', 'amp' ), $src ) );
		}

		$includes_url = includes_url( '/' );
		$content_url  = content_url( '/' );
		$admin_url    = get_admin_url( null, '/' );
		$css_path     = null;
		if ( 0 === strpos( $src, $content_url ) ) {
			$css_path = WP_CONTENT_DIR . substr( $src, strlen( $content_url ) - 1 );
		} elseif ( 0 === strpos( $src, $includes_url ) ) {
			$css_path = ABSPATH . WPINC . substr( $src, strlen( $includes_url ) - 1 );
		} elseif ( 0 === strpos( $src, $admin_url ) ) {
			$css_path = ABSPATH . 'wp-admin' . substr( $src, strlen( $admin_url ) - 1 );
		}

		if ( ! $css_path || false !== strpos( '../', $css_path ) || 0 !== validate_file( $css_path ) || ! file_exists( $css_path ) ) {
			/* translators: %s is stylesheet URL */
			return new WP_Error( 'amp_css_path_not_found', sprintf( __( 'Unable to locate filesystem path for stylesheet %s.', 'amp' ), $src ) );
		}

		return $css_path;
	}

	/**
	 * Process style element.
	 *
	 * @param DOMElement $element Style element.
	 */
	private function process_style_element( DOMElement $element ) {

		// @todo Any @keyframes rules could be removed from amp-custom and instead added to amp-keyframes.
		$is_keyframes = $element->hasAttribute( 'amp-keyframes' );
		$stylesheet   = trim( $element->textContent );
		$cdata_spec   = $is_keyframes ? $this->style_keyframes_cdata_spec : $this->style_custom_cdata_spec;
		if ( $stylesheet ) {
			$stylesheet = $this->process_stylesheet( $stylesheet, $element, array(
				'allowed_at_rules'            => $cdata_spec['css_spec']['allowed_at_rules'],
				'property_whitelist'          => $cdata_spec['css_spec']['allowed_declarations'],
				'validate_keyframes'          => $cdata_spec['css_spec']['validate_keyframes'],
				'class_selector_tree_shaking' => ! $cdata_spec['css_spec']['validate_keyframes'],
			) );
		}

		// Remove if surpasses max size.
		$length       = strlen( $stylesheet );
		$current_size = $is_keyframes ? $this->current_keyframes_size : $this->current_custom_size;
		if ( $current_size + $length > $cdata_spec['max_bytes'] ) {
			$this->remove_invalid_child( $element, array(
				/* translators: %d is the number of bytes over the limit */
				'message' => sprintf( __( 'Too much CSS enqueued (by %d bytes).', 'amp' ), ( $current_size + $length ) - $cdata_spec['max_bytes'] ),
			) );
			return;
		}

		$hash = md5( $stylesheet );

		if ( $is_keyframes ) {
			$this->keyframes_stylesheets[ $hash ] = $stylesheet;
			$this->current_keyframes_size        += $length;
		} else {
			$this->stylesheets[ $hash ] = $stylesheet;
			$this->current_custom_size += $length;
		}

		if ( $element->hasAttribute( 'amp-custom' ) ) {
			if ( ! $this->amp_custom_style_element ) {
				$this->amp_custom_style_element = $element;
			} else {
				$element->parentNode->removeChild( $element ); // There can only be one. #highlander.
			}
		} else {

			// Remove from DOM since we'll be adding it to amp-custom.
			$element->parentNode->removeChild( $element );
		}
	}

	/**
	 * Process link element.
	 *
	 * @param DOMElement $element Link element.
	 */
	private function process_link_element( DOMElement $element ) {
		$href = $element->getAttribute( 'href' );

		// Allow font URLs.
		if ( $this->allowed_font_src_regex && preg_match( $this->allowed_font_src_regex, $href ) ) {
			return;
		}

		$css_file_path = $this->get_validated_css_file_path( $href );
		if ( is_wp_error( $css_file_path ) ) {
			$this->remove_invalid_child( $element, array(
				'message' => $css_file_path->get_error_message(),
			) );
			return;
		}

		// Load the CSS from the filesystem.
		$stylesheet = file_get_contents( $css_file_path ); // phpcs:ignore -- It's a local filesystem path not a remote request.
		if ( false === $stylesheet ) {
			$this->remove_invalid_child( $element, array(
				'message' => __( 'Unable to load stylesheet from filesystem.', 'amp' ),
			) );
			return;
		}

		// Honor the link's media attribute.
		$media = $element->getAttribute( 'media' );
		if ( $media && 'all' !== $media ) {
			$stylesheet = sprintf( '@media %s { %s }', $media, $stylesheet );
		}

		$stylesheet = $this->process_stylesheet( $stylesheet, $element, array(
			'allowed_at_rules'            => $this->style_custom_cdata_spec['css_spec']['allowed_at_rules'],
			'property_whitelist'          => $this->style_custom_cdata_spec['css_spec']['allowed_declarations'],
			'class_selector_tree_shaking' => true,
			'stylesheet_url'              => $href,
			'stylesheet_path'             => $css_file_path,
		) );

		// Skip if surpasses max size.
		$length = strlen( $stylesheet );
		if ( $this->current_custom_size + $length > $this->style_custom_cdata_spec['max_bytes'] ) {
			$this->remove_invalid_child( $element, array(
				/* translators: %d is the number of bytes over the limit */
				'message' => sprintf( __( 'Too much CSS enqueued (by %d bytes).', 'amp' ), ( $this->current_custom_size + $length ) - $this->style_custom_cdata_spec['max_bytes'] ),
			) );
			return;
		}
		$hash = md5( $stylesheet );

		$this->stylesheets[ $hash ] = $stylesheet;
		$this->current_custom_size += $length;

		// Remove now that styles have been processed.
		$element->parentNode->removeChild( $element );
	}

	/**
	 * Process stylesheet.
	 *
	 * Sanitized invalid CSS properties and rules, removes rules which do not
	 * apply to the current document, and compresses the CSS to remove whitespace and comments.
	 *
	 * @since 1.0
	 *
	 * @param string             $stylesheet Stylesheet.
	 * @param DOMElement|DOMAttr $node       Element (link/style) or style attribute where the stylesheet came from.
	 * @param array              $options {
	 *     Options.
	 *
	 *     @type bool     $class_selector_tree_shaking Whether to perform tree shaking to delete rules that reference class names not extant in the current document.
	 *     @type string[] $property_whitelist          Exclusively-allowed properties.
	 *     @type string[] $property_blacklist          Disallowed properties.
	 *     @type bool     $convert_width_to_max_width  Convert width to max-width.
	 *     @type string   $stylesheet_url              Original URL for stylesheet when originating via link (or @import?).
	 *     @type string   $stylesheet_path             Original filesystem path for stylesheet when originating via link (or @import?).
	 * }
	 * @return string Processed stylesheet.
	 */
	private function process_stylesheet( $stylesheet, $node, $options = array() ) {
		$should_tree_shake = ! empty( $options['class_selector_tree_shaking'] );
		unset( $options['class_selector_tree_shaking'] );
		$cache_key = md5( $stylesheet . serialize( $options ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize

		$cache_group = 'amp-parsed-stylesheet-v1';
		if ( wp_using_ext_object_cache() ) {
			$parsed = wp_cache_get( $cache_key, $cache_group );
		} else {
			$parsed = get_transient( $cache_key . $cache_group );
		}
		if ( ! $parsed || ! isset( $parsed['stylesheet'] ) || ! is_array( $parsed['stylesheet'] ) ) {
			$parsed = $this->parse_stylesheet( $stylesheet, $options );
			if ( wp_using_ext_object_cache() ) {
				wp_cache_set( $cache_key, $parsed, $cache_group );
			} else {
				// The expiration is to ensure transient doesn't stick around forever since no LRU flushing like with external object cache.
				set_transient( $cache_key . $cache_group, $parsed, MONTH_IN_SECONDS );
			}
		}

		if ( ! empty( $this->args['validation_error_callback'] ) && ! empty( $parsed['validation_errors'] ) ) {
			foreach ( $parsed['validation_errors'] as $validation_error ) {
				call_user_func( $this->args['validation_error_callback'], array_merge( $validation_error, compact( 'node' ) ) );
			}
		}

		$stylesheet = '';
		foreach ( $parsed['stylesheet'] as $stylesheet_part ) {
			if ( is_array( $stylesheet_part ) ) {
				list( $selectors_parsed, $declaration_block ) = $stylesheet_part;
				if ( $should_tree_shake ) {
					$selectors = array();
					foreach ( $selectors_parsed as $selector => $class_names ) {
						if ( 0 === count( array_diff( $class_names, $this->used_class_names ) ) ) { // If all class names are used in the doc.
							$selectors[] = $selector;
						}
					}
				} else {
					$selectors = array_keys( $selectors_parsed );
				}
				if ( ! empty( $selectors ) ) {
					$stylesheet .= implode( ',', $selectors ) . $declaration_block;
				}
			} else {
				$stylesheet .= $stylesheet_part;
			}
		}

		return $stylesheet;
	}

	/**
	 * Parse stylesheet.
	 *
	 * @since 1.0
	 *
	 * @param string $stylesheet_string Stylesheet.
	 * @param array  $options           Options. See definition in \AMP_Style_Sanitizer::process_stylesheet().
	 * @return array {
	 *    Parsed stylesheet.
	 *
	 *    @type array $stylesheet        Stylesheet parts, where arrays are tuples for declaration blocks.
	 *    @type array $validation_errors Validation errors.
	 * }
	 */
	private function parse_stylesheet( $stylesheet_string, $options = array() ) {
		$start_time = microtime( true );

		$options = array_merge(
			array(
				'allowed_at_rules'           => array(),
				'convert_width_to_max_width' => false,
				'property_blacklist'         => array(
					// See <https://www.ampproject.org/docs/design/responsive/style_pages#disallowed-styles>.
					'behavior',
					'-moz-binding',
				),
				'property_whitelist'         => array(),
				'validate_keyframes'         => false,
				'stylesheet_url'             => null,
				'stylesheet_path'            => null,
			),
			$options
		);

		$stylesheet        = array();
		$validation_errors = array();
		try {
			$parser_settings = Sabberworm\CSS\Settings::create()->withMultibyteSupport( false );
			$css_parser      = new Sabberworm\CSS\Parser( $stylesheet_string, $parser_settings );
			$css_document    = $css_parser->parse();

			$validation_errors = $this->process_css_list( $css_document, $options );

			$output_format = Sabberworm\CSS\OutputFormat::createCompact();

			$before_declaration_block          = '/*AMP_WP_BEFORE_DECLARATION_BLOCK*/';
			$between_selectors                 = '/*AMP_WP_BETWEEN_SELECTORS*/';
			$after_declaration_block_selectors = '/*AMP_WP_BEFORE_DECLARATION_SELECTORS*/';
			$after_declaration_block           = '/*AMP_WP_AFTER_DECLARATION*/';

			$output_format->set( 'BeforeDeclarationBlock', $before_declaration_block );
			$output_format->set( 'SpaceBeforeSelectorSeparator', $between_selectors );
			$output_format->set( 'AfterDeclarationBlockSelectors', $after_declaration_block_selectors );
			$output_format->set( 'AfterDeclarationBlock', $after_declaration_block );

			$stylesheet_string = $css_document->render( $output_format );

			$pattern  = '#';
			$pattern .= '(' . preg_quote( $before_declaration_block, '#' ) . ')';
			$pattern .= '(.+?)';
			$pattern .= preg_quote( $after_declaration_block_selectors, '#' );
			$pattern .= '(.+?)';
			$pattern .= preg_quote( $after_declaration_block, '#' );
			$pattern .= '#s';

			$split_stylesheet = preg_split( $pattern, $stylesheet_string, -1, PREG_SPLIT_DELIM_CAPTURE );
			$length           = count( $split_stylesheet );
			for ( $i = 0; $i < $length; $i++ ) {
				if ( $before_declaration_block === $split_stylesheet[ $i ] ) {
					$selectors   = explode( $between_selectors . ',', $split_stylesheet[ ++$i ] );
					$declaration = $split_stylesheet[ ++$i ];

					$selectors_parsed = array();
					foreach ( $selectors as $selector ) {
						$classes = array();

						// Remove :not() to eliminate false negatives, such as with `body:not(.title-tagline-hidden) .site-branding-text`.
						$reduced_selector = preg_replace( '/:not\(.+?\)/', '', $selector );

						// Remove attribute selectors to eliminate false negative, such as with `.social-navigation a[href*="example.com"]:before`.
						$reduced_selector = preg_replace( '/\[\w.*?\]/', '', $reduced_selector );

						if ( preg_match_all( '/(?<=\.)([a-zA-Z0-9_-]+)/', $reduced_selector, $matches ) ) {
							$classes = $matches[0];
						}
						$selectors_parsed[ $selector ] = $classes;
					}

					$stylesheet[] = array(
						$selectors_parsed,
						$declaration,
					);
				} else {
					$stylesheet[] = $split_stylesheet[ $i ];
				}
			}
		} catch ( Exception $exception ) {
			$validation_errors[] = array(
				'code'    => 'css_parse_error',
				'message' => $exception->getMessage(),
			);
		}

		$this->parse_css_duration += ( microtime( true ) - $start_time );

		return compact( 'stylesheet', 'validation_errors' );
	}

	/**
	 * Process CSS list.
	 *
	 * @since 1.0
	 *
	 * @param CSSList $css_list CSS List.
	 * @param array   $options Options.
	 * @return array Validation errors.
	 */
	private function process_css_list( CSSList $css_list, $options ) {
		$validation_errors = array();

		foreach ( $css_list->getContents() as $css_item ) {
			if ( $css_item instanceof DeclarationBlock && empty( $options['validate_keyframes'] ) ) {
				$validation_errors = array_merge(
					$validation_errors,
					$this->process_css_declaration_block( $css_item, $css_list, $options )
				);
			} elseif ( $css_item instanceof AtRuleBlockList ) {
				if ( in_array( $css_item->atRuleName(), $options['allowed_at_rules'], true ) ) {
					$validation_errors = array_merge(
						$validation_errors,
						$this->process_css_list( $css_item, $options )
					);
				} else {
					$validation_errors[] = array(
						'code'    => 'illegal_css_at_rule',
						/* translators: %s is the CSS at-rule name. */
						'message' => sprintf( __( 'CSS @%s rules are currently disallowed.', 'amp' ), $css_item->atRuleName() ),
					);
					$css_list->remove( $css_item );
				}
			} elseif ( $css_item instanceof Import ) {
				$validation_errors[] = array(
					'code'    => 'illegal_css_import_rule',
					'message' => __( 'CSS @import is currently disallowed.', 'amp' ),
				);
				$css_list->remove( $css_item );
			} elseif ( $css_item instanceof AtRuleSet ) {
				if ( in_array( $css_item->atRuleName(), $options['allowed_at_rules'], true ) ) {
					$validation_errors = array_merge(
						$validation_errors,
						$this->process_css_declaration_block( $css_item, $css_list, $options )
					);
				} else {
					$validation_errors[] = array(
						'code'    => 'illegal_css_at_rule',
						/* translators: %s is the CSS at-rule name. */
						'message' => sprintf( __( 'CSS @%s rules are currently disallowed.', 'amp' ), $css_item->atRuleName() ),
					);
					$css_list->remove( $css_item );
				}
			} elseif ( $css_item instanceof KeyFrame ) {
				if ( in_array( 'keyframes', $options['allowed_at_rules'], true ) ) {
					$validation_errors = array_merge(
						$validation_errors,
						$this->process_css_keyframes( $css_item, $options )
					);
				} else {
					$validation_errors[] = array(
						'code'    => 'illegal_css_at_rule',
						/* translators: %s is the CSS at-rule name. */
						'message' => sprintf( __( 'CSS @%s rules are currently disallowed.', 'amp' ), $css_item->atRuleName() ),
					);
				}
			} elseif ( $css_item instanceof AtRule ) {
				$validation_errors[] = array(
					'code'    => 'illegal_css_at_rule',
					/* translators: %s is the CSS at-rule name. */
					'message' => sprintf( __( 'CSS @%s rules are currently disallowed.', 'amp' ), $css_item->atRuleName() ),
				);
				$css_list->remove( $css_item );
			} else {
				$validation_errors[] = array(
					'code'    => 'unrecognized_css',
					'message' => __( 'Unrecognized CSS removed.', 'amp' ),
				);
				$css_list->remove( $css_item );
			}
		}
		return $validation_errors;
	}

	/**
	 * Process CSS rule set.
	 *
	 * @since 1.0
	 * @link https://www.ampproject.org/docs/design/responsive/style_pages#disallowed-styles
	 * @link https://www.ampproject.org/docs/design/responsive/style_pages#restricted-styles
	 *
	 * @param RuleSet $ruleset  Ruleset.
	 * @param CSSList $css_list CSS List.
	 * @param array   $options  Options.
	 *
	 * @return array Validation errors.
	 */
	private function process_css_declaration_block( RuleSet $ruleset, CSSList $css_list, $options ) {
		$validation_errors = array();

		// Remove disallowed properties.
		if ( ! empty( $options['property_whitelist'] ) ) {
			$properties = $ruleset->getRules();
			foreach ( $properties as $property ) {
				$vendorless_property_name = preg_replace( '/^-\w+-/', '', $property->getRule() );
				if ( ! in_array( $vendorless_property_name, $options['property_whitelist'], true ) ) {
					$validation_errors[] = array(
						'code'           => 'illegal_css_property',
						'property_name'  => $property->getRule(),
						'property_value' => $property->getValue(),
					);
					$ruleset->removeRule( $property->getRule() );
				}
			}
		} else {
			foreach ( $options['property_blacklist'] as $illegal_property_name ) {
				$properties = $ruleset->getRules( $illegal_property_name );
				foreach ( $properties as $property ) {
					$validation_errors[] = array(
						'code'           => 'illegal_css_property',
						'property_name'  => $property->getRule(),
						'property_value' => $property->getValue(),
					);
					$ruleset->removeRule( $property->getRule() );
				}
			}
		}

		if ( $ruleset instanceof AtRuleSet && 'font-face' === $ruleset->atRuleName() ) {
			$this->process_font_face_at_rule( $ruleset, $options );
		}

		$validation_errors = array_merge(
			$validation_errors,
			$this->transform_important_qualifiers( $ruleset, $css_list )
		);

		// Convert width to max-width when requested. See <https://github.com/Automattic/amp-wp/issues/494>.
		if ( $options['convert_width_to_max_width'] ) {
			$properties = $ruleset->getRules( 'width' );
			foreach ( $properties as $property ) {
				$width_property = new Rule( 'max-width' );
				$width_property->setValue( $property->getValue() );
				$ruleset->removeRule( $property );
				$ruleset->addRule( $width_property, $property );
			}
		}

		// Remove the ruleset if it is now empty.
		if ( 0 === count( $ruleset->getRules() ) ) {
			$css_list->remove( $ruleset );
		}
		// @todo Delete rules with selectors for -amphtml- class and i-amphtml- tags.
		return $validation_errors;
	}

	/**
	 * Process @font-face by making src URLs non-relative and converting data: URLs into (assumed) file URLs.
	 *
	 * @since 1.0
	 *
	 * @param AtRuleSet $ruleset Ruleset for @font-face.
	 * @param array     $options Options.
	 */
	private function process_font_face_at_rule( AtRuleSet $ruleset, $options ) {
		$src_properties = $ruleset->getRules( 'src' );
		if ( empty( $src_properties ) ) {
			return;
		}

		$base_url = null;
		if ( ! empty( $options['stylesheet_url'] ) ) {
			$base_url = preg_replace( ':[^/]+(\?.*)?(#.*)?$:', '', $options['stylesheet_url'] );
		}

		/**
		 * Convert a relative path URL into a real/absolute path.
		 *
		 * @param URL $url Stylesheet URL.
		 */
		$real_path = function ( URL $url ) use ( $base_url ) {
			if ( empty( $base_url ) ) {
				return;
			}
			$parsed_url = wp_parse_url( $url->getURL()->getString() );
			if ( ! empty( $parsed_url['host'] ) || empty( $parsed_url['path'] ) || '/' === substr( $parsed_url['path'], 0, 1 ) ) {
				return;
			}
			$relative_url = preg_replace( '#^\./#', '', $url->getURL()->getString() );
			$url->getURL()->setString( $base_url . $relative_url );
		};

		foreach ( $src_properties as $src_property ) {
			$value = $src_property->getValue();
			if ( $value instanceof URL ) {
				$real_path( $value );
			} elseif ( $value instanceof RuleValueList ) {
				/*
				 * The CSS Parser parses a src such as:
				 *
				 *    url(data:application/font-woff;...) format('woff'),
				 *    url('Genericons.ttf') format('truetype'),
				 *    url('Genericons.svg#genericonsregular') format('svg')
				 *
				 * As a list of components consisting of:
				 *
				 *    URL,
				 *    RuleValueList( CSSFunction, URL ),
				 *    RuleValueList( CSSFunction, URL ),
				 *    CSSFunction
				 *
				 * Clearly the components here are not logically grouped. So the first step is to fix the order.
				 */
				$sources = array();
				foreach ( $value->getListComponents() as $component ) {
					if ( $component instanceof RuleValueList ) {
						$subcomponents = $component->getListComponents();
						$subcomponent  = array_shift( $subcomponents );
						if ( $subcomponent ) {
							if ( empty( $sources ) ) {
								$sources[] = array( $subcomponent );
							} else {
								$sources[ count( $sources ) - 1 ][] = $subcomponent;
							}
						}
						foreach ( $subcomponents as $subcomponent ) {
							$sources[] = array( $subcomponent );
						}
					} else {
						if ( empty( $sources ) ) {
							$sources[] = array( $component );
						} else {
							$sources[ count( $sources ) - 1 ][] = $component;
						}
					}
				}

				/**
				 * Source URL lists.
				 *
				 * @var URL[] $source_file_urls
				 * @var URL[] $source_data_urls
				 */
				$source_file_urls = array();
				$source_data_urls = array();
				foreach ( $sources as $i => $source ) {
					if ( $source[0] instanceof URL ) {
						if ( 'data:' === substr( $source[0]->getURL()->getString(), 0, 5 ) ) {
							$source_data_urls[ $i ] = $source[0];
						} else {
							$real_path( $source[0] );
							$source_file_urls[ $i ] = $source[0];
						}
					}
				}

				// Convert data: URLs into regular URLs, assuming there will be a file present (e.g. woff fonts in core themes).
				if ( empty( $source_file_urls ) ) {
					continue;
				}
				$source_file_url = current( $source_file_urls );
				foreach ( $source_data_urls as $i => $data_url ) {
					$mime_type = strtok( substr( $data_url->getURL()->getString(), 5 ), ';' );
					if ( ! $mime_type ) {
						continue;
					}
					$extension   = preg_replace( ':.+/(.+-)?:', '', $mime_type );
					$guessed_url = preg_replace(
						':(?<=\.)\w+(\?.*)?(#.*)?$:', // Match the file extension in the URL.
						$extension,
						$source_file_url->getURL()->getString(),
						1,
						$count
					);
					if ( $count ) {
						$data_url->getURL()->setString( $guessed_url );
					}
				}
			}
		}
	}

	/**
	 * Process CSS keyframes.
	 *
	 * @since 1.0
	 * @link https://www.ampproject.org/docs/design/responsive/style_pages#restricted-styles.
	 * @link https://github.com/ampproject/amphtml/blob/b685a0780a7f59313666225478b2b79b463bcd0b/validator/validator-main.protoascii#L1002-L1043
	 * @todo Tree shaking could be extended to keyframes, to omit a keyframe if it is not referenced by any rule.
	 *
	 * @param KeyFrame $css_list Ruleset.
	 * @param array    $options  Options.
	 * @return array Validation errors.
	 */
	private function process_css_keyframes( KeyFrame $css_list, $options ) {
		$validation_errors = array();
		if ( ! empty( $options['property_whitelist'] ) ) {
			foreach ( $css_list->getContents() as $rules ) {
				if ( ! ( $rules instanceof DeclarationBlock ) ) {
					$validation_errors[] = array(
						'code'    => 'unrecognized_css',
						'message' => __( 'Unrecognized CSS removed.', 'amp' ),
					);
					$css_list->remove( $rules );
					continue;
				}

				$validation_errors = array_merge(
					$validation_errors,
					$this->transform_important_qualifiers( $rules, $css_list )
				);

				$properties = $rules->getRules();
				foreach ( $properties as $property ) {
					$vendorless_property_name = preg_replace( '/^-\w+-/', '', $property->getRule() );
					if ( ! in_array( $vendorless_property_name, $options['property_whitelist'], true ) ) {
						$validation_errors[] = array(
							'code'           => 'illegal_css_property',
							'property_name'  => $property->getRule(),
							'property_value' => $property->getValue(),
						);
						$rules->removeRule( $property->getRule() );
					}
				}
			}
		}
		return $validation_errors;
	}

	/**
	 * Replace !important qualifiers with more specific rules.
	 *
	 * @since 1.0
	 * @see https://www.npmjs.com/package/replace-important
	 * @see https://www.ampproject.org/docs/fundamentals/spec#important
	 * @todo Further tailor for specificity. See https://github.com/ampproject/ampstart/blob/4c21d69afdd07b4c60cd190937bda09901955829/tools/replace-important/lib/index.js#L87-L126.
	 *
	 * @param RuleSet|DeclarationBlock $ruleset  Rule set.
	 * @param CSSList                  $css_list CSS List.
	 * @return array Validation errors.
	 */
	private function transform_important_qualifiers( RuleSet $ruleset, CSSList $css_list ) {
		$validation_errors    = array();
		$allow_transformation = (
			$ruleset instanceof DeclarationBlock
			&&
			! ( $css_list instanceof KeyFrame )
		);

		$properties = $ruleset->getRules();
		$importants = array();
		foreach ( $properties as $property ) {
			if ( $property->getIsImportant() ) {
				$property->setIsImportant( false );

				// An !important doesn't make sense for rulesets that don't have selectors.
				if ( $allow_transformation ) {
					$importants[] = $property;
					$ruleset->removeRule( $property->getRule() );
				} else {
					$validation_errors[] = array(
						'code'    => 'illegal_css_important',
						'message' => __( 'Illegal CSS !important qualifier.', 'amp' ),
					);
				}
			}
		}
		if ( ! $allow_transformation || empty( $importants ) ) {
			return $validation_errors;
		}

		$important_ruleset = clone $ruleset;
		$important_ruleset->setSelectors( array_map(
			function( Selector $old_selector ) {
				return new Selector( ':root:not(#FK_ID) ' . $old_selector->getSelector() );
			},
			$ruleset->getSelectors()
		) );
		$important_ruleset->setRules( $importants );
		$css_list->append( $important_ruleset ); // @todo It would be preferable if the important ruleset were inserted adjacent to the original rule.

		return $validation_errors;
	}

	/**
	 * Collect and store all CSS style attributes.
	 *
	 * Collects the CSS styles from within the HTML contained in this instance's DOMDocument.
	 *
	 * @see Retrieve array of styles using $this->get_styles() after calling this method.
	 *
	 * @since 0.4
	 * @since 0.7 Modified to use element passed by XPath query.
	 *
	 * @note Uses recursion to traverse down the tree of DOMDocument nodes.
	 *
	 * @param DOMElement $element Node.
	 */
	private function collect_inline_styles( $element ) {
		$style_attribute = $element->getAttributeNode( 'style' );
		if ( ! $style_attribute || ! trim( $style_attribute->nodeValue ) ) {
			return;
		}

		// @todo Use hash from resulting processed CSS so that we can potentially re-use? We need to use the hash of the original rules as the cache key.
		$class  = 'amp-wp-' . substr( md5( $style_attribute->nodeValue ), 0, 7 );
		$rule   = sprintf( '.%s { %s }', $class, $style_attribute->nodeValue );
		$hash   = md5( $rule );
		$rule   = $this->process_stylesheet( $rule, $style_attribute, array(
			'convert_width_to_max_width'  => true,
			'allowed_at_rules'            => array(),
			'property_whitelist'          => $this->style_custom_cdata_spec['css_spec']['allowed_declarations'],
			'class_selector_tree_shaking' => false,
		) );
		$length = strlen( $rule );

		if ( 0 === $length ) {
			$element->removeAttribute( 'style' );
			return;
		}

		if ( $this->current_custom_size + $length > $this->style_custom_cdata_spec['max_bytes'] ) {
			$this->remove_invalid_attribute( $element, $style_attribute, array(
				/* translators: %d is the number of bytes over the limit */
				'message' => sprintf( __( 'Too much CSS enqueued (by %d bytes).', 'amp' ), ( $this->current_custom_size + $length ) - $this->style_custom_cdata_spec['max_bytes'] ),
			) );
			return;
		}

		$this->current_custom_size += $length;
		$this->stylesheets[ $hash ] = $rule;

		$element->removeAttribute( 'style' );
		if ( $element->hasAttribute( 'class' ) ) {
			$element->setAttribute( 'class', $element->getAttribute( 'class' ) . ' ' . $class );
		} else {
			$element->setAttribute( 'class', $class );
		}
	}

	/**
	 * Finalize style[amp-keyframes] elements.
	 *
	 * Combine all amp-keyframe elements and enforce that it is at the end of the body.
	 *
	 * @since 1.0
	 * @see https://www.ampproject.org/docs/fundamentals/spec#keyframes-stylesheet
	 */
	private function finalize_amp_keyframes_styles() {
		if ( empty( $this->keyframes_stylesheets ) ) {
			return;
		}

		$body = $this->dom->getElementsByTagName( 'body' )->item( 0 );
		if ( ! $body ) {
			if ( ! empty( $this->args['validation_error_callback'] ) ) {
				call_user_func( $this->args['validation_error_callback'], array(
					'code'    => 'missing_body_element',
					'message' => __( 'amp-keyframes must be last child of body element.', 'amp' ),
				) );
			}
			return;
		}

		$style_element = $this->dom->createElement( 'style' );
		$style_element->setAttribute( 'amp-keyframes', '' );
		$style_element->appendChild( $this->dom->createTextNode( implode( '', $this->keyframes_stylesheets ) ) );
		$body->appendChild( $style_element );
	}
}
