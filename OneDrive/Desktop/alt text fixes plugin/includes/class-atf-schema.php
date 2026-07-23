<?php
/**
 * Schema (structured data) generator.
 *
 * Outputs JSON-LD structured data on the front end so search engines better
 * understand the site. Supported automatically:
 *   - BlogPosting / NewsArticle for posts
 *   - Product for WooCommerce products (and any post with product meta)
 *   - LocalBusiness / MedicalBusiness / a generic Organization for the whole site
 *   - FAQPage built per-post from a metabox (or via the CSV import)
 *   - Custom JSON-LD entered by the user (global or per-post)
 *
 * Values are derived from existing post meta / SEO module data where possible,
 * so the generator works hands-off but stays editable.
 */
class ATF_Schema {

	const FAQ_META     = '_atf_faq';
	const CUSTOM_META  = '_atf_schema_custom';
	const TYPED_META   = '_atf_schema_type';
	const TYPED_FIELDS = '_atf_schema_fields';
	const ORG_OPTION   = 'atf_schema_org';

	/**
	 * Supported guided schema types (besides the automatic ones).
	 *
	 * @return array
	 */
	public static function typed_types() {
		return array(
			'Event'      => esc_html__( 'Event', 'alt-text-fixer' ),
			'JobPosting' => esc_html__( 'Job posting', 'alt-text-fixer' ),
			'VideoObject'=> esc_html__( 'Video', 'alt-text-fixer' ),
			'Service'    => esc_html__( 'Service', 'alt-text-fixer' ),
			'Recipe'     => esc_html__( 'Recipe', 'alt-text-fixer' ),
			'Course'     => esc_html__( 'Course', 'alt-text-fixer' ),
			'Person'     => esc_html__( 'Person', 'alt-text-fixer' ),
		);
	}

	/**
	 * Field definitions for guided types: field => array( label, types ).
	 *
	 * @return array
	 */
	public static function typed_field_defs() {
		$all = array_keys( self::typed_types() );
		return array(
			'name'        => array( esc_html__( 'Name / title', 'alt-text-fixer' ), $all ),
			'description' => array( esc_html__( 'Description', 'alt-text-fixer' ), $all ),
			'startDate'   => array( esc_html__( 'Start date/time (ISO 8601)', 'alt-text-fixer' ), array( 'Event', 'JobPosting', 'Course' ) ),
			'endDate'     => array( esc_html__( 'End date/time (ISO 8601)', 'alt-text-fixer' ), array( 'Event', 'Course' ) ),
			'location'    => array( esc_html__( 'Location (venue/address)', 'alt-text-fixer' ), array( 'Event', 'JobPosting' ) ),
			'image'       => array( esc_html__( 'Image URL', 'alt-text-fixer' ), array( 'Event', 'VideoObject', 'Recipe', 'Course', 'Person', 'Service' ) ),
			'url'         => array( esc_html__( 'URL', 'alt-text-fixer' ), array( 'Event', 'VideoObject', 'Service', 'Recipe', 'Course', 'Person' ) ),
			'datePosted'  => array( esc_html__( 'Date posted (ISO 8601)', 'alt-text-fixer' ), array( 'JobPosting' ) ),
			'hiringOrg'   => array( esc_html__( 'Hiring organization', 'alt-text-fixer' ), array( 'JobPosting' ) ),
			'contentUrl'  => array( esc_html__( 'Video URL', 'alt-text-fixer' ), array( 'VideoObject' ) ),
			'author'      => array( esc_html__( 'Author / chef / instructor', 'alt-text-fixer' ), array( 'Recipe', 'Course', 'Person' ) ),
			'provider'    => array( esc_html__( 'Provider / school', 'alt-text-fixer' ), array( 'Course', 'Service' ) ),
			'jobTitle'    => array( esc_html__( 'Job title / role', 'alt-text-fixer' ), array( 'Person' ) ),
			'price'       => array( esc_html__( 'Price', 'alt-text-fixer' ), array( 'Service', 'Course', 'Recipe' ) ),
		);
	}

	/**
	 * Initialise hooks.
	 */
	public static function init() {
		add_action( 'wp_head', array( __CLASS__, 'output_head' ), 2 );

		add_action( 'wp_ajax_atf_schema_count', array( __CLASS__, 'ajax_count' ) );
		add_action( 'wp_ajax_atf_schema_batch', array( __CLASS__, 'ajax_batch' ) );

		add_action( 'add_meta_boxes', array( __CLASS__, 'add_metabox' ) );
		add_action( 'save_post', array( __CLASS__, 'save_metabox' ), 30, 2 );
	}

	/**
	 * Default organization / local-business settings.
	 *
	 * @return array
	 */
	public static function org_defaults() {
		return array(
			'type'        => 'Organization',
			'name'        => get_bloginfo( 'name' ),
			'enabled'     => 'yes',
			'description' => '',
			'url'         => home_url( '/' ),
			'logo'        => '',
			'image'       => '',
			'phone'       => '',
			'email'       => '',
			'address'     => '',
			'city'        => '',
			'region'      => '',
			'postal'      => '',
			'country'     => '',
			'priceRange'  => '',
			'geo'         => '',
			'hours'       => '',
			'sameAs'      => '',
			'medical'     => array(),
		);
	}

	/**
	 * Organization settings merged with defaults.
	 *
	 * @return array
	 */
	public static function get_org_settings() {
		return wp_parse_args( get_option( self::ORG_OPTION, array() ), self::org_defaults() );
	}

	/**
	 * Output all applicable JSON-LD blocks for the current view.
	 */
	public static function output_head() {
		if ( is_admin() ) {
			return;
		}

		// Site-wide organization / local business.
		$org = self::get_org_settings();
		if ( ! empty( $org['enabled'] ) && 'yes' === $org['enabled'] ) {
			$data = self::build_organization( $org );
			if ( $data ) {
				self::print_jsonld( $data );
			}
			// WebSite + potential search action (uses the site search URL).
			$website = self::build_website( $org );
			if ( $website ) {
				self::print_jsonld( $website );
			}
		}

		// BreadcrumbList on any view that has a trail.
		$breadcrumb = self::build_breadcrumb();
		if ( $breadcrumb ) {
			self::print_jsonld( $breadcrumb );
		}

		if ( is_singular() ) {
			$post_id = get_queried_object_id();

			// Per-post custom schema (user supplied).
			$custom = get_post_meta( $post_id, self::CUSTOM_META, true );
			if ( ! empty( $custom ) && is_array( $custom ) ) {
				self::print_jsonld( $custom );
			}

			// FAQ schema.
			$faq = get_post_meta( $post_id, self::FAQ_META, true );
			if ( ! empty( $faq ) && is_array( $faq ) ) {
				self::print_jsonld( self::build_faq( $faq ) );
			}

			// Guided typed schema (Event/Job/Video/Service/Recipe/Course/Person).
			$typed_type = get_post_meta( $post_id, self::TYPED_META, true );
			$typed_fields = get_post_meta( $post_id, self::TYPED_FIELDS, true );
			if ( $typed_type && is_array( $typed_fields ) ) {
				$typed = self::build_typed( $typed_type, $typed_fields );
				if ( $typed ) {
					self::print_jsonld( $typed );
				}
			}

			// Post / product schema.
			$post_schema = self::build_post_schema( $post_id );
			if ( $post_schema ) {
				self::print_jsonld( $post_schema );
			}
		}
	}

	/**
	 * Build the Organization / LocalBusiness / MedicalBusiness graph.
	 *
	 * @param array $org Org settings.
	 * @return array|null
	 */
	public static function build_organization( $org ) {
		$type = ! empty( $org['type'] ) ? $org['type'] : 'Organization';
		$allowed = array( 'Organization', 'LocalBusiness', 'MedicalBusiness', 'ProfessionalService', 'Store', 'Restaurant' );
		if ( ! in_array( $type, $allowed, true ) ) {
			$type = 'Organization';
		}

		$data = array(
			'@context' => 'https://schema.org',
			'@type'    => $type,
			'name'     => self::clean( $org['name'] ? $org['name'] : get_bloginfo( 'name' ) ),
			'url'      => self::clean_url( $org['url'] ? $org['url'] : home_url( '/' ) ),
		);

		if ( ! empty( $org['description'] ) ) {
			$data['description'] = self::clean( $org['description'] );
		}
		if ( ! empty( $org['logo'] ) ) {
			$data['logo'] = self::clean_url( $org['logo'] );
		}
		if ( ! empty( $org['image'] ) ) {
			$data['image'] = self::clean_url( $org['image'] );
		}
		if ( ! empty( $org['phone'] ) ) {
			$data['telephone'] = self::clean( $org['phone'] );
		}
		if ( ! empty( $org['email'] ) ) {
			$data['email'] = self::clean( $org['email'] );
		}
		if ( ! empty( $org['priceRange'] ) ) {
			$data['priceRange'] = self::clean( $org['priceRange'] );
		}

		// Postal address for local businesses.
		if ( 'LocalBusiness' === $type || 'MedicalBusiness' === $type || 'ProfessionalService' === $type || 'Store' === $type || 'Restaurant' === $type ) {
			$addr = self::build_address( $org );
			if ( $addr ) {
				$data['address'] = $addr;
			}
			if ( ! empty( $org['geo'] ) ) {
				$coords = self::parse_geo( $org['geo'] );
				if ( $coords ) {
					$data['geo'] = array(
						'@type'     => 'GeoCoordinates',
						'latitude'  => $coords['lat'],
						'longitude' => $coords['lng'],
					);
				}
			}
			if ( ! empty( $org['hours'] ) ) {
				$days = self::parse_hours( $org['hours'] );
				if ( $days ) {
					$data['openingHours'] = $days;
				}
			}
			if ( 'MedicalBusiness' === $type && ! empty( $org['medical'] ) && is_array( $org['medical'] ) ) {
				$specialties = array_filter( array_map( 'trim', $org['medical'] ) );
				if ( $specialties ) {
					$data['medicalSpecialty'] = array_values( $specialties );
				}
			}
		}

		if ( ! empty( $org['sameAs'] ) ) {
			$links = self::parse_lines( $org['sameAs'] );
			$links = array_filter( $links, function ( $u ) { return filter_var( $u, FILTER_VALIDATE_URL ); } );
			if ( $links ) {
				$data['sameAs'] = array_values( $links );
			}
		}

		return $data;
	}

	/**
	 * Build a WebSite node with an optional SearchAction.
	 *
	 * @param array $org Org settings.
	 * @return array|null
	 */
	public static function build_website( $org ) {
		$home = self::clean_url( home_url( '/' ) );
		$data = array(
			'@context' => 'https://schema.org',
			'@type'    => 'WebSite',
			'name'     => self::clean( $org['name'] ? $org['name'] : get_bloginfo( 'name' ) ),
			'url'      => $home,
		);

		// Add a potential search action using the standard WordPress search URL.
		$search_url = home_url( '/?s={search_term_string}' );
		$data['potentialAction'] = array(
			'@type'       => 'SearchAction',
			'target'      => self::clean_url( $search_url ),
			'query-input' => 'required name=search_term_string',
		);

		return $data;
	}

	/**
	 * Build a BreadcrumbList for the current view.
	 *
	 * Uses WordPress' own breadcrumb trail when available (rankmath/yoast
	 * filters), otherwise assembles a simple Home > (taxonomy) > post trail.
	 *
	 * @return array|null
	 */
	public static function build_breadcrumb() {
		// Allow SEO plugins / themes to supply their own crumbs.
		$crumbs = apply_filters( 'atf_schema_breadcrumbs', null );
		if ( is_array( $crumbs ) && $crumbs ) {
			return self::breadcrumb_node( $crumbs );
		}

		$trail = array();
		$trail[] = array( 'name' => self::clean( get_bloginfo( 'name' ) ), 'url' => self::clean_url( home_url( '/' ) ) );

		if ( is_singular() ) {
			$post = get_post( get_queried_object_id() );
			if ( $post ) {
				$tax = get_object_taxonomies( $post->post_type, 'objects' );
				foreach ( $tax as $t ) {
					if ( empty( $t->public ) || empty( $t->hierarchical ) ) {
						continue;
					}
					$terms = get_the_terms( $post->ID, $t->name );
					if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
						usort( $terms, function ( $a, $b ) { return $a->parent - $b->parent; } );
						foreach ( $terms as $term ) {
							$trail[] = array( 'name' => self::clean( $term->name ), 'url' => self::clean_url( get_term_link( $term ) ) );
						}
					}
					break;
				}
				$trail[] = array( 'name' => self::clean( get_the_title( $post ) ), 'url' => self::clean_url( get_permalink( $post->ID ) ) );
			}
		} elseif ( is_category() || is_tag() || is_tax() ) {
			$term = get_queried_object();
			if ( $term ) {
				$trail[] = array( 'name' => self::clean( $term->name ), 'url' => self::clean_url( get_term_link( $term ) ) );
			}
		} elseif ( is_post_type_archive() ) {
			$post_type = get_queried_object();
			if ( $post_type ) {
				$trail[] = array( 'name' => self::clean( $post_type->label ), 'url' => self::clean_url( get_post_type_archive_link( $post_type->name ) ) );
			}
		} elseif ( is_search() ) {
			$trail[] = array( 'name' => self::clean( sprintf( __( 'Search: %s', 'alt-text-fixer' ), get_search_query() ) ), 'url' => self::clean_url( get_search_link() ) );
		} elseif ( is_404() ) {
			return null;
		}

		if ( count( $trail ) < 2 ) {
			return null;
		}
		return self::breadcrumb_node( $trail );
	}

	/**
	 * Wrap a name/url list into a BreadcrumbList node.
	 *
	 * @param array $trail List of arrays with 'name' and 'url'.
	 * @return array
	 */
	public static function breadcrumb_node( $trail ) {
		$items = array();
		$i = 1;
		foreach ( $trail as $c ) {
			$items[] = array(
				'@type'    => 'ListItem',
				'position' => $i ++,
				'name'     => self::clean( $c['name'] ),
				'item'     => self::clean_url( $c['url'] ),
			);
		}
		return array(
			'@context'        => 'https://schema.org',
			'@type'           => 'BreadcrumbList',
			'itemListElement' => $items,
		);
	}

	/**
	 * Build a postalAddress node from org settings.
	 *
	 * @param array $org Org settings.
	 * @return array|null
	 */
	public static function build_address( $org ) {
		$has = false;
		$addr = array( '@type' => 'PostalAddress' );
		if ( ! empty( $org['address'] ) ) { $addr['streetAddress'] = self::clean( $org['address'] ); $has = true; }
		if ( ! empty( $org['city'] ) )    { $addr['addressLocality'] = self::clean( $org['city'] ); $has = true; }
		if ( ! empty( $org['region'] ) )  { $addr['addressRegion'] = self::clean( $org['region'] ); $has = true; }
		if ( ! empty( $org['postal'] ) )  { $addr['postalCode'] = self::clean( $org['postal'] ); $has = true; }
		if ( ! empty( $org['country'] ) ) { $addr['addressCountry'] = self::clean( $org['country'] ); $has = true; }
		return $has ? $addr : null;
	}

	/**
	 * Build FAQPage schema from a list of Q/A pairs.
	 *
	 * @param array $faq Array of arrays with 'q' and 'a' keys.
	 * @return array|null
	 */
	public static function build_faq( $faq ) {
		$items = array();
		foreach ( (array) $faq as $row ) {
			if ( empty( $row['q'] ) || empty( $row['a'] ) ) {
				continue;
			}
			$items[] = array(
				'@type'          => 'Question',
				'name'           => self::clean( $row['q'] ),
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => self::clean( $row['a'] ),
				),
			);
		}
		if ( ! $items ) {
			return null;
		}
		return array(
			'@context' => 'https://schema.org',
			'@type'    => 'FAQPage',
			'mainEntity' => $items,
		);
	}

	/**
	 * Build a guided typed schema (Event/JobPosting/VideoObject/Service/Recipe/Course/Person).
	 *
	 * @param string $type   Schema @type.
	 * @param array  $fields Field values.
	 * @return array|null
	 */
	public static function build_typed( $type, $fields ) {
		if ( ! array_key_exists( $type, self::typed_types() ) || empty( $fields ) || ! is_array( $fields ) ) {
			return null;
		}
		$data = array(
			'@context' => 'https://schema.org',
			'@type'    => $type,
		);
		$fields = array_map( 'trim', $fields );
		if ( ! empty( $fields['name'] ) ) {
			$data['name'] = self::clean( $fields['name'] );
		}
		if ( ! empty( $fields['description'] ) ) {
			$data['description'] = self::clean( $fields['description'] );
		}
		if ( ! empty( $fields['url'] ) ) {
			$data['url'] = self::clean_url( $fields['url'] );
		}
		if ( ! empty( $fields['image'] ) ) {
			$data['image'] = self::clean_url( $fields['image'] );
		}
		if ( ! empty( $fields['startDate'] ) ) {
			$data['startDate'] = self::clean( $fields['startDate'] );
		}
		if ( ! empty( $fields['endDate'] ) ) {
			$data['endDate'] = self::clean( $fields['endDate'] );
		}
		if ( ! empty( $fields['location'] ) ) {
			$data['location'] = array(
				'@type' => 'Place',
				'name'  => self::clean( $fields['location'] ),
			);
		}
		if ( ! empty( $fields['datePosted'] ) ) {
			$data['datePosted'] = self::clean( $fields['datePosted'] );
		}
		if ( ! empty( $fields['hiringOrg'] ) ) {
			$data['hiringOrganization'] = array(
				'@type' => 'Organization',
				'name'  => self::clean( $fields['hiringOrg'] ),
			);
		}
		if ( ! empty( $fields['contentUrl'] ) ) {
			$data['contentUrl'] = self::clean_url( $fields['contentUrl'] );
		}
		if ( ! empty( $fields['author'] ) ) {
			$data['author'] = self::clean( $fields['author'] );
		}
		if ( ! empty( $fields['provider'] ) ) {
			$data['provider'] = array(
				'@type' => 'Organization',
				'name'  => self::clean( $fields['provider'] ),
			);
		}
		if ( ! empty( $fields['jobTitle'] ) ) {
			$data['jobTitle'] = self::clean( $fields['jobTitle'] );
		}
		if ( ! empty( $fields['price'] ) ) {
			$data['offers'] = array(
				'@type'         => 'Offer',
				'price'         => self::clean( $fields['price'] ),
				'priceCurrency' => self::clean( $fields['currency'] ?? 'USD' ),
			);
		}
		// Require at least a name to be valid output.
		if ( empty( $data['name'] ) ) {
			return null;
		}
		return apply_filters( 'atf_schema_typed_data', $data, $type, $fields );
	}

	/**
	 * Build schema for the current singular post (BlogPosting / Product).
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	public static function build_post_schema( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return null;
		}

		// WooCommerce product.
		if ( function_exists( 'wc_get_product' ) && 'product' === $post->post_type ) {
			return self::build_product( $post_id, $post );
		}

		$supported = apply_filters(
			'atf_schema_post_types',
			array( 'post' => 'BlogPosting', 'page' => 'WebPage' )
		);
		if ( ! isset( $supported[ $post->post_type ] ) ) {
			return null;
		}

		$type = $supported[ $post->post_type ];
		$data = array(
			'@context'         => 'https://schema.org',
			'@type'            => $type,
			'headline'         => self::clean( $post->post_title ),
			'url'              => self::clean_url( get_permalink( $post_id ) ),
			'datePublished'    => mysql2date( 'c', $post->post_date_gmt ? $post->post_date_gmt : $post->post_date ),
			'dateModified'     => mysql2date( 'c', $post->post_modified_gmt ? $post->post_modified_gmt : $post->post_modified ),
			'author'           => array(
				'@type' => 'Person',
				'name'  => self::clean( get_the_author_meta( 'display_name', $post->post_author ) ),
			),
			'publisher'        => self::publisher_node(),
		);

		$img = self::post_image_node( $post_id );
		if ( $img ) {
			$data['image'] = $img;
		}

		// Speakable markup: let assistants read the headline + main content.
		$speakable = self::speakable_node( $post_id );
		if ( $speakable ) {
			$data['speakable'] = $speakable;
		}

		$desc = ATF_SEO::get_description( $post_id );
		if ( $desc ) {
			$data['description'] = self::clean( $desc );
		}

		return apply_filters( 'atf_schema_post_data', $data, $post_id );
	}

	/**
	 * Build Product schema (WooCommerce-aware, falls back to post meta).
	 *
	 * @param int    $post_id Post ID.
	 * @param object $post    Post object.
	 * @return array|null
	 */
	public static function build_product( $post_id, $post ) {
		$data = array(
			'@context'    => 'https://schema.org',
			'@type'       => 'Product',
			'name'        => self::clean( $post->post_title ),
			'description' => self::clean( wp_strip_all_tags( $post->post_excerpt ? $post->post_excerpt : $post->post_content ) ),
			'url'         => self::clean_url( get_permalink( $post_id ) ),
		);

		$sku = get_post_meta( $post_id, '_sku', true );
		if ( $sku ) {
			$data['sku'] = self::clean( $sku );
		}

		$img = self::post_image_node( $post_id );
		if ( $img ) {
			$data['image'] = $img;
		}

		$price = get_post_meta( $post_id, '_price', true );
		$currency = function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : 'USD';
		if ( '' !== $price && null !== $price ) {
			$data['offers'] = array(
				'@type'         => 'Offer',
				'price'         => self::clean( $price ),
				'priceCurrency' => self::clean( $currency ),
				'availability'  => 'https://schema.org/InStock',
				'url'           => self::clean_url( get_permalink( $post_id ) ),
			);
		} else {
			$data['offers'] = array(
				'@type'         => 'Offer',
				'priceCurrency' => self::clean( $currency ),
				'availability'  => 'https://schema.org/InStock',
				'url'           => self::clean_url( get_permalink( $post_id ) ),
			);
		}

		return apply_filters( 'atf_schema_product_data', $data, $post_id );
	}

	/**
	 * Publisher node (site organization) used by article schema.
	 *
	 * @return array
	 */
	public static function publisher_node() {
		$org = self::get_org_settings();
		$node = array(
			'@type' => 'Organization',
			'name'  => self::clean( $org['name'] ? $org['name'] : get_bloginfo( 'name' ) ),
		);
		if ( ! empty( $org['logo'] ) ) {
			$node['logo'] = array(
				'@type' => 'ImageObject',
				'url'   => self::clean_url( $org['logo'] ),
			);
		}
		return $node;
	}

	/**
	 * Best available image URL for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return string
	 */
	public static function post_image( $post_id ) {
		if ( has_post_thumbnail( $post_id ) ) {
			$src = wp_get_attachment_image_src( get_post_thumbnail_id( $post_id ), 'full' );
			if ( ! empty( $src[0] ) ) {
				return self::clean_url( $src[0] );
			}
		}
		$override = get_post_meta( $post_id, ATF_SEO::IMAGE_META, true );
		if ( ! empty( $override ) ) {
			return self::clean_url( $override );
		}
		return '';
	}

	/**
	 * Build an ImageObject node for a post's featured/social image.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	public static function post_image_node( $post_id ) {
		$url = self::post_image( $post_id );
		if ( ! $url ) {
			return null;
		}
		$node = array(
			'@type' => 'ImageObject',
			'url'   => $url,
		);
		if ( has_post_thumbnail( $post_id ) ) {
			$meta = wp_get_attachment_metadata( get_post_thumbnail_id( $post_id ) );
			if ( ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
				$node['width']  = (int) $meta['width'];
				$node['height'] = (int) $meta['height'];
			}
		}
		return $node;
	}

	/**
	 * Build a speakable node pointing assistants at the headline and content.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	public static function speakable_node( $post_id ) {
		$css = apply_filters( 'atf_schema_speakable_css', array( 'article', '.entry-content' ), $post_id );
		if ( empty( $css ) ) {
			return null;
		}
		return array(
			'@type'           => 'SpeakableSpecification',
			'xPath'           => array_merge( array( '/html/head/title' ), array_map( function ( $c ) { return '//' . ltrim( $c, '/' ); }, (array) $css ) ),
		);
	}


	/**
	 * Whether a post has no schema generated yet (for counts / batching).
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	public static function post_needs_schema( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}
		// Any post that has FAQ, custom schema, or is a supported type counts as
		// "has schema output" once we've marked it. We mark a post when we visit.
		if ( get_post_meta( $post_id, '_atf_schema_done', true ) ) {
			return false;
		}
		$supported = apply_filters(
			'atf_schema_post_types',
			array( 'post' => 'BlogPosting', 'page' => 'WebPage' )
		);
		$is_product = function_exists( 'wc_get_product' ) && 'product' === $post->post_type;
		return $is_product || isset( $supported[ $post->post_type ] )
			|| (bool) get_post_meta( $post_id, self::FAQ_META, true )
			|| (bool) get_post_meta( $post_id, self::CUSTOM_META, true )
			|| (bool) get_post_meta( $post_id, self::TYPED_META, true );
	}

	/**
	 * Count posts that would emit schema (for the dashboard card).
	 *
	 * @return int
	 */
	public static function count_posts_with_schema() {
		$count = 0;
		$posts = ATF_Content_Fixer::get_posts( 0, -1 );
		foreach ( $posts as $pid ) {
			if ( self::post_needs_schema( $pid ) ) {
				$count ++;
			}
		}
		return $count;
	}

	/**
	 * Mark posts as processed (schema is auto-output, this just tracks state).
	 *
	 * @param int $offset Start offset.
	 * @param int $limit  Batch size.
	 * @return int Number marked.
	 */
	public static function mark_batch( $offset = 0, $limit = 50 ) {
		$posts = ATF_Content_Fixer::get_posts( (int) $offset, (int) $limit );
		$done  = 0;
		foreach ( $posts as $pid ) {
			if ( self::post_needs_schema( $pid ) ) {
				update_post_meta( $pid, '_atf_schema_done', 1 );
				$done ++;
			}
		}
		return $done;
	}

	/**
	 * AJAX: count schema-eligible posts.
	 */
	public static function ajax_count() {
		check_ajax_referer( 'atf_batch_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'permission' );
		}
		$total = 0;
		$posts = ATF_Content_Fixer::get_posts( 0, -1 );
		foreach ( $posts as $pid ) {
			if ( self::post_needs_schema( $pid ) ) {
				$total ++;
			}
		}
		wp_send_json_success( array( 'total' => $total ) );
	}

	/**
	 * AJAX: process a batch (mark posts so the card reflects processed state).
	 */
	public static function ajax_batch() {
		check_ajax_referer( 'atf_batch_nonce', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'permission' );
		}
		$offset = isset( $_POST['offset'] ) ? (int) $_POST['offset'] : 0;
		$limit  = isset( $_POST['limit'] ) ? (int) $_POST['limit'] : 50;
		if ( $limit < 1 || $limit > 200 ) {
			$limit = 50;
		}
		$done = self::mark_batch( $offset, $limit );

		$posts    = ATF_Content_Fixer::get_posts( 0, -1 );
		$total    = 0;
		foreach ( $posts as $pid ) {
			if ( self::post_needs_schema( $pid ) ) {
				$total ++;
			}
		}
		$processed = $offset + count( $posts );
		wp_send_json_success(
			array(
				'fixed'     => $done,
				'processed' => $processed,
				'remaining' => $total,
				'finished'  => $total <= 0,
			)
		);
	}

	/**
	 * Add the per-post FAQ / custom schema metabox.
	 */
	public static function add_metabox() {
		$post_types = get_post_types( array( 'public' => true ), 'names' );
		add_meta_box(
			'atf_schema',
			esc_html__( 'Schema / Structured data', 'alt-text-fixer' ),
			array( __CLASS__, 'render_metabox' ),
			$post_types,
			'normal',
			'default'
		);
	}

	/**
	 * Render the FAQ / custom schema metabox.
	 *
	 * @param object $post Post object.
	 */
	public static function render_metabox( $post ) {
		$faq    = get_post_meta( $post->ID, self::FAQ_META, true );
		$custom = get_post_meta( $post->ID, self::CUSTOM_META, true );
		if ( ! is_array( $faq ) ) {
			$faq = array();
		}
		wp_nonce_field( 'atf_schema_meta', 'atf_schema_nonce' );
		?>
		<p><?php esc_html_e( 'Add FAQ pairs to generate FAQPage structured data for this page. Leave empty to skip.', 'alt-text-fixer' ); ?></p>
		<div id="atf-faq-rows">
			<?php foreach ( $faq as $row ) : ?>
				<p class="atf-faq-row">
					<input type="text" class="regular-text" name="atf_faq_q[]" placeholder="<?php esc_attr_e( 'Question', 'alt-text-fixer' ); ?>" value="<?php echo esc_attr( $row['q'] ?? '' ); ?>">
					<br>
					<textarea class="large-text" name="atf_faq_a[]" rows="2" placeholder="<?php esc_attr_e( 'Answer', 'alt-text-fixer' ); ?>"><?php echo esc_textarea( $row['a'] ?? '' ); ?></textarea>
					<button type="button" class="button atf-faq-remove"><?php esc_html_e( 'Remove', 'alt-text-fixer' ); ?></button>
				</p>
			<?php endforeach; ?>
		</div>
		<p><button type="button" class="button" id="atf-faq-add"><?php esc_html_e( 'Add FAQ', 'alt-text-fixer' ); ?></button></p>

		<p style="margin-top:16px">
			<strong><?php esc_html_e( 'Guided schema type', 'alt-text-fixer' ); ?></strong><br>
			<span class="description"><?php esc_html_e( 'Pick a type to auto-generate that structured data for this page (Event, Job, Video, Service, Recipe, Course, Person). Fill the fields below; leave blank to skip.', 'alt-text-fixer' ); ?></span>
		</p>
		<?php
		$typed_type = get_post_meta( $post->ID, self::TYPED_META, true );
		$typed_fields = get_post_meta( $post->ID, self::TYPED_FIELDS, true );
		if ( ! is_array( $typed_fields ) ) {
			$typed_fields = array();
		}
		?>
		<p>
			<label for="atf_schema_type"><?php esc_html_e( 'Type', 'alt-text-fixer' ); ?></label>
			<select name="atf_schema_type" id="atf_schema_type">
				<option value=""><?php esc_html_e( '— None —', 'alt-text-fixer' ); ?></option>
				<?php foreach ( self::typed_types() as $key => $label ) : ?>
					<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $key, $typed_type ); ?>><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<div id="atf-typed-fields">
			<?php foreach ( self::typed_field_defs() as $name => $label ) : ?>
				<p class="atf-typed-field" data-type="<?php echo esc_attr( $name ); ?>">
					<label><?php echo esc_html( $label ); ?></label><br>
					<input type="text" class="regular-text" name="atf_schema_fields[<?php echo esc_attr( $name ); ?>]" value="<?php echo esc_attr( $typed_fields[ $name ] ?? '' ); ?>">
				</p>
			<?php endforeach; ?>
		</div>

		<p style="margin-top:16px">
			<strong><?php esc_html_e( 'Custom JSON-LD', 'alt-text-fixer' ); ?></strong><br>
			<span class="description"><?php esc_html_e( 'Paste a valid JSON-LD object (without the script tags) to output on this page. Advanced / client-specific schemas go here.', 'alt-text-fixer' ); ?></span>
			<textarea class="large-text code" name="atf_schema_custom" rows="5" placeholder='{ "@context": "https://schema.org", "@type": "Event", ... }'><?php echo esc_textarea( is_array( $custom ) ? wp_json_encode( $custom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) : '' ); ?></textarea>
		</p>
		<script>
			jQuery( function ( $ ) {
				$( '#atf-faq-add' ).on( 'click', function () {
					$( '#atf-faq-rows' ).append(
						'<p class="atf-faq-row"><input type="text" class="regular-text" name="atf_faq_q[]" placeholder="<?php esc_attr_e( 'Question', 'alt-text-fixer' ); ?>"><br><textarea class="large-text" name="atf_faq_a[]" rows="2" placeholder="<?php esc_attr_e( 'Answer', 'alt-text-fixer' ); ?>"></textarea> <button type="button" class="button atf-faq-remove"><?php esc_html_e( 'Remove', 'alt-text-fixer' ); ?></button></p>'
					);
				} );
				$( document ).on( 'click', '.atf-faq-remove', function () {
					$( this ).closest( '.atf-faq-row' ).remove();
				} );

				var defs = <?php echo wp_json_encode( self::typed_field_defs() ); ?>;
				function atfFilterTyped() {
					var t = $( '#atf_schema_type' ).val();
					$( '#atf-typed-fields .atf-typed-field' ).each( function () {
						var name = $( this ).data( 'type' );
						var types = defs[ name ] ? defs[ name ][1] : [];
						$( this ).toggle( -1 !== types.indexOf( t ) );
					} );
				}
				$( '#atf_schema_type' ).on( 'change', atfFilterTyped );
				atfFilterTyped();
			} );
		</script>
		<?php
	}

	/**
	 * Save the metabox data.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function save_metabox( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['atf_schema_nonce'] ) || ! wp_verify_nonce( $_POST['atf_schema_nonce'], 'atf_schema_meta' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$faq = array();
		if ( ! empty( $_POST['atf_faq_q'] ) && is_array( $_POST['atf_faq_q'] ) ) {
			$qs = wp_unslash( $_POST['atf_faq_q'] );
			$as = isset( $_POST['atf_faq_a'] ) && is_array( $_POST['atf_faq_a'] ) ? wp_unslash( $_POST['atf_faq_a'] ) : array();
			foreach ( $qs as $i => $q ) {
				$a = $as[ $i ] ?? '';
				if ( '' !== trim( $q ) && '' !== trim( $a ) ) {
					$faq[] = array(
						'q' => sanitize_text_field( $q ),
						'a' => wp_kses_post( $a ),
					);
				}
			}
		}
		if ( $faq ) {
			update_post_meta( $post_id, self::FAQ_META, $faq );
		} else {
			delete_post_meta( $post_id, self::FAQ_META );
		}

		$custom_raw = isset( $_POST['atf_schema_custom'] ) ? wp_unslash( $_POST['atf_schema_custom'] ) : '';
		$custom_raw = trim( (string) $custom_raw );
		if ( '' !== $custom_raw ) {
			$decoded = json_decode( $custom_raw, true );
			if ( is_array( $decoded ) ) {
				update_post_meta( $post_id, self::CUSTOM_META, $decoded );
			}
		} else {
			delete_post_meta( $post_id, self::CUSTOM_META );
		}

		$typed_type = isset( $_POST['atf_schema_type'] ) ? sanitize_key( $_POST['atf_schema_type'] ) : '';
		if ( $typed_type && array_key_exists( $typed_type, self::typed_types() ) ) {
			update_post_meta( $post_id, self::TYPED_META, $typed_type );
			$fields = array();
			if ( ! empty( $_POST['atf_schema_fields'] ) && is_array( $_POST['atf_schema_fields'] ) ) {
				foreach ( $_POST['atf_schema_fields'] as $k => $v ) {
					$k = sanitize_key( $k );
					$fields[ $k ] = sanitize_text_field( wp_unslash( $v ) );
				}
			}
			update_post_meta( $post_id, self::TYPED_FIELDS, $fields );
		} else {
			delete_post_meta( $post_id, self::TYPED_META );
			delete_post_meta( $post_id, self::TYPED_FIELDS );
		}
	}

	/* --------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	/**
	 * Print a JSON-LD script block safely.
	 *
	 * @param array $data Schema array.
	 */
	public static function print_jsonld( $data ) {
		if ( empty( $data ) || ! is_array( $data ) ) {
			return;
		}
		echo "\t" . '<script type="application/ld+json">' . wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . '</script>' . "\n";
	}

	/**
	 * Sanitize a text value for schema output.
	 *
	 * @param string $v Raw value.
	 * @return string
	 */
	public static function clean( $v ) {
		return wp_strip_all_tags( trim( (string) $v ) );
	}

	/**
	 * Sanitize a URL for schema output.
	 *
	 * @param string $u Raw URL.
	 * @return string
	 */
	public static function clean_url( $u ) {
		$u = esc_url_raw( trim( (string) $u ) );
		return $u;
	}

	/**
	 * Parse newline/separator-delimited text into lines.
	 *
	 * @param string $text Raw text.
	 * @return array
	 */
	public static function parse_lines( $text ) {
		$out = preg_split( '/[\r\n]+/', (string) $text );
		return array_map( 'trim', (array) $out );
	}

	/**
	 * Parse "lat,lng" into an array.
	 *
	 * @param string $geo Raw geo string.
	 * @return array|null
	 */
	public static function parse_geo( $geo ) {
		if ( ! preg_match( '/^\s*([-\d.]+)\s*,\s*([-\d.]+)\s*$/', (string) $geo, $m ) ) {
			return null;
		}
		return array( 'lat' => (float) $m[1], 'lng' => (float) $m[2] );
	}

	/**
	 * Parse opening hours lines ("Mon-Fri 09:00-17:00") into schema format.
	 *
	 * @param string $text Raw hours.
	 * @return array
	 */
	public static function parse_hours( $text ) {
		$out = array();
		foreach ( self::parse_lines( $text ) as $line ) {
			if ( '' === $line ) {
				continue;
			}
			// Map common day abbreviations to schema day names.
			$line = preg_replace( '/\bmon\b/i', 'Monday', $line );
			$line = preg_replace( '/\btue\b|\btues\b/i', 'Tuesday', $line );
			$line = preg_replace( '/\bwed\b|\bweds\b/i', 'Wednesday', $line );
			$line = preg_replace( '/\bthu\b|\bthur\b|\bthurs\b/i', 'Thursday', $line );
			$line = preg_replace( '/\bfri\b/i', 'Friday', $line );
			$line = preg_replace( '/\bsat\b/i', 'Saturday', $line );
			$line = preg_replace( '/\bsun\b/i', 'Sunday', $line );
			$out[] = self::clean( $line );
		}
		return $out;
	}
}
