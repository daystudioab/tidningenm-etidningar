<?php
/**
 * Plugin Name:       Tidningen M – E-tidningar
 * Description:       Egen innehållstyp för e-tidningar med ISSUU-läsare, automatiskt omslag, grid med alla nummer, korsordsfacit och import av befintliga nummer-sidor. Raderar eller ändrar aldrig befintliga sidor.
 * Version:           1.4.3
 * Update URI:        https://github.com/daystudioab/tidningenm-etidningar
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Tidningen M
 * Text Domain:       m-etidningar
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Uppdateringar från GitHub (daystudioab/tidningenm-etidningar).
 * Nya versioner publiceras som GitHub-releaser och visas under Tillägg som vanliga uppdateringar.
 * Privat repo: lägg in en läsnyckel i wp-config.php:
 *   define( 'STUDIODAY_GITHUB_TOKEN', 'github_pat_…' );
 */
require_once __DIR__ . '/lib/plugin-update-checker/plugin-update-checker.php';

$met_uppdaterare = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
	'https://github.com/daystudioab/tidningenm-etidningar/',
	__FILE__,
	'm-etidningar'
);
$met_uppdaterare->getVcsApi()->enableReleaseAssets( '/m-etidningar\\.zip$/' );
if ( defined( 'STUDIODAY_GITHUB_TOKEN' ) && STUDIODAY_GITHUB_TOKEN ) {
	$met_uppdaterare->setAuthentication( STUDIODAY_GITHUB_TOKEN );
}

define( 'MET_VERSION', '1.4.3' );
define( 'MET_CPT', 'etidning' );
define( 'MET_TAX', 'argang' );
define( 'MET_SLUG', 'e-tidning' ); // Ger adresser som /e-tidning/nr-2-2026/

/* -------------------------------------------------------------------------
 * 1. Innehållstyp + årgång
 * ---------------------------------------------------------------------- */

add_action( 'init', 'met_register_types' );
function met_register_types() {
	register_post_type( MET_CPT, array(
		'labels'        => array(
			'name'               => 'E-tidningar',
			'singular_name'      => 'E-tidning',
			'add_new'            => 'Lägg till nummer',
			'add_new_item'       => 'Lägg till nytt nummer',
			'edit_item'          => 'Redigera nummer',
			'new_item'           => 'Nytt nummer',
			'view_item'          => 'Visa nummer',
			'search_items'       => 'Sök nummer',
			'not_found'          => 'Inga nummer hittades',
			'not_found_in_trash' => 'Inga nummer i papperskorgen',
			'all_items'          => 'Alla nummer',
			'menu_name'          => 'E-tidningar',
			'featured_image'     => 'Omslag',
			'set_featured_image' => 'Välj omslag',
			'remove_featured_image' => 'Ta bort omslag',
			'use_featured_image' => 'Använd som omslag',
		),
		'public'        => true,
		'has_archive'   => false, // Den befintliga sidan /e-tidning/ får vara kvar orörd.
		'rewrite'       => array( 'slug' => MET_SLUG, 'with_front' => false ),
		'menu_icon'     => 'dashicons-book-alt',
		'menu_position' => 21,
		'supports'      => array( 'title', 'thumbnail', 'excerpt' ),
		'show_in_rest'  => true,
	) );

	register_taxonomy( MET_TAX, MET_CPT, array(
		'labels'            => array(
			'name'          => 'Årgångar',
			'singular_name' => 'Årgång',
			'menu_name'     => 'Årgångar',
		),
		'public'            => true,
		'hierarchical'      => true,
		'show_admin_column' => true,
		'show_in_rest'      => true,
		'rewrite'           => array( 'slug' => 'e-tidning-argang' ),
	) );
}

register_activation_hook( __FILE__, function () {
	met_register_types();
	flush_rewrite_rules();
} );
register_deactivation_hook( __FILE__, 'flush_rewrite_rules' );

/* -------------------------------------------------------------------------
 * 2. Fält (ACF) – med reserv-metabox om ACF skulle vara avstängt
 * ---------------------------------------------------------------------- */

add_action( 'acf/init', 'met_register_acf_fields' );
function met_register_acf_fields() {
	if ( ! function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}
	acf_add_local_field_group( array(
		'key'      => 'group_met_etidning',
		'title'    => 'E-tidning',
		'position' => 'acf_after_title',
		'location' => array( array( array(
			'param'    => 'post_type',
			'operator' => '==',
			'value'    => MET_CPT,
		) ) ),
		'fields'   => array(
			array(
				'key'          => 'field_met_issuu_url',
				'label'        => 'ISSUU-länk',
				'name'         => 'issuu_url',
				'type'         => 'url',
				'required'     => 1,
				'instructions' => 'Klistra in länken till numret på ISSUU, t.ex. https://issuu.com/moderatkvinnorna/docs/tidningen_modig_nr_3_2026. Inbäddningslänkar (e.issuu.com/embed.html?d=…) fungerar också. Omslaget hämtas automatiskt om du inte väljer ett eget.',
			),
			array(
				'key'           => 'field_met_nummer',
				'label'         => 'Nummer',
				'name'          => 'nummer',
				'type'          => 'number',
				'min'           => 1,
				'max'           => 12,
				'wrapper'       => array( 'width' => '50' ),
				'instructions'  => 'Lämna tomt så läses det av från titeln (”Nr 3 2026”).',
			),
			array(
				'key'           => 'field_met_ar',
				'label'         => 'År',
				'name'          => 'ar',
				'type'          => 'number',
				'min'           => 1990,
				'max'           => 2100,
				'wrapper'       => array( 'width' => '50' ),
				'instructions'  => 'Lämna tomt så läses det av från titeln.',
			),
		),
	) );
}

// Reserv om ACF inte är aktivt: enkel egen ruta för ISSUU-länken.
add_action( 'add_meta_boxes', function () {
	if ( function_exists( 'acf_add_local_field_group' ) ) {
		return;
	}
	add_meta_box( 'met_issuu', 'ISSUU-länk', function ( $post ) {
		wp_nonce_field( 'met_save_meta', 'met_meta_nonce' );
		printf(
			'<p><input type="url" name="met_issuu_url" value="%s" style="width:100%%" placeholder="https://issuu.com/…/docs/…"></p>',
			esc_attr( get_post_meta( $post->ID, 'issuu_url', true ) )
		);
	}, MET_CPT, 'normal', 'high' );
} );
add_action( 'save_post_' . MET_CPT, function ( $post_id ) {
	if ( isset( $_POST['met_meta_nonce'] ) && wp_verify_nonce( $_POST['met_meta_nonce'], 'met_save_meta' ) && isset( $_POST['met_issuu_url'] ) ) {
		update_post_meta( $post_id, 'issuu_url', esc_url_raw( wp_unslash( $_POST['met_issuu_url'] ) ) );
	}
}, 5 );

/* -------------------------------------------------------------------------
 * 3. ISSUU-hjälpfunktioner
 * ---------------------------------------------------------------------- */

/**
 * Tolkar en ISSUU-länk i valfritt format och returnerar [user, document] eller null.
 */
function met_parse_issuu( $url ) {
	$url = html_entity_decode( trim( (string) $url ) );
	if ( '' === $url ) {
		return null;
	}
	// https://issuu.com/{user}/docs/{doc}
	if ( preg_match( '~issuu\.com/([^/?#]+)/docs/([^/?#]+)~i', $url, $m ) ) {
		return array( 'user' => $m[1], 'doc' => $m[2] );
	}
	// https://e.issuu.com/embed.html?d={doc}&u={user}  (även &amp;)
	$parts = wp_parse_url( $url );
	if ( ! empty( $parts['query'] ) ) {
		parse_str( $parts['query'], $q );
		if ( ! empty( $q['d'] ) && ! empty( $q['u'] ) ) {
			return array( 'user' => $q['u'], 'doc' => $q['d'] );
		}
	}
	return null;
}

function met_issuu_canonical( $url ) {
	$p = met_parse_issuu( $url );
	return $p ? sprintf( 'https://issuu.com/%s/docs/%s', rawurlencode( $p['user'] ), rawurlencode( $p['doc'] ) ) : '';
}

function met_issuu_embed_src( $url ) {
	$p = met_parse_issuu( $url );
	return $p ? sprintf( 'https://e.issuu.com/embed.html?d=%s&u=%s', rawurlencode( $p['doc'] ), rawurlencode( $p['user'] ) ) : '';
}

/**
 * Hämtar omslagsbildens URL via ISSUU:s oEmbed. Returnerar URL eller ''.
 */
function met_issuu_cover_url( $url ) {
	$canonical = met_issuu_canonical( $url );
	if ( ! $canonical ) {
		return '';
	}
	$res = wp_remote_get(
		add_query_arg( array( 'url' => rawurlencode( $canonical ), 'format' => 'json' ), 'https://issuu.com/oembed' ),
		array( 'timeout' => 15 )
	);
	if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
		return '';
	}
	$data = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( empty( $data['thumbnail_url'] ) ) {
		return '';
	}
	return esc_url_raw( $data['thumbnail_url'] );
}

/**
 * Laddar ner omslaget till mediabiblioteket och sätter det som utvald bild.
 * Försöker först med en större version av bilden, sedan med oEmbed-miniatyren.
 */
function met_fetch_cover( $post_id ) {
	$issuu = get_post_meta( $post_id, 'issuu_url', true );
	$thumb = met_issuu_cover_url( $issuu );
	if ( ! $thumb ) {
		return new WP_Error( 'met_no_cover', 'Kunde inte hämta omslag från ISSUU.' );
	}

	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$candidates = array_unique( array(
		preg_replace( '~_thumb_(small|medium|large)~', '', $thumb ), // helsida om den finns
		$thumb,
	) );

	$title = get_the_title( $post_id );
	foreach ( $candidates as $src ) {
		// ISSUU-bilder saknar ibland filändelse – ge en tydlig filändelse.
		$tmp = download_url( $src, 30 );
		if ( is_wp_error( $tmp ) ) {
			continue;
		}
		$file = array(
			'name'     => sanitize_file_name( 'omslag-' . sanitize_title( $title ) . '.jpg' ),
			'tmp_name' => $tmp,
		);
		$att_id = media_handle_sideload( $file, $post_id, 'Omslag ' . $title );
		if ( is_wp_error( $att_id ) ) {
			@unlink( $tmp );
			continue;
		}
		update_post_meta( $att_id, '_wp_attachment_image_alt', 'Omslag ' . $title );
		update_post_meta( $att_id, '_met_issuu_cover', 1 );
		$prev = (int) get_post_thumbnail_id( $post_id );
		if ( $prev && ! get_post_meta( $prev, '_met_issuu_cover', true ) ) {
			update_post_meta( $post_id, '_met_prev_thumb', $prev ); // Spara tidigare omslag så att det går att ångra.
		}
		set_post_thumbnail( $post_id, $att_id );
		return $att_id;
	}
	return new WP_Error( 'met_no_cover', 'Kunde inte ladda ner omslaget från ISSUU.' );
}

/**
 * Läser ut nummer och år ur en titel som ”Nr 3 2026”, ”Nr3 2026”, ”Nr 4 2025”.
 */
function met_parse_title( $title ) {
	if ( preg_match( '~nr\.?\s*(\d{1,2})\D+((?:19|20)\d{2})~i', $title, $m ) ) {
		return array( 'nummer' => (int) $m[1], 'ar' => (int) $m[2] );
	}
	if ( preg_match( '~((?:19|20)\d{2})~', $title, $m ) ) {
		return array( 'nummer' => 0, 'ar' => (int) $m[1] );
	}
	return array( 'nummer' => 0, 'ar' => 0 );
}

/* -------------------------------------------------------------------------
 * 4. När ett nummer sparas: fyll i nummer/år, årgång och omslag
 * ---------------------------------------------------------------------- */

add_action( 'save_post_' . MET_CPT, 'met_on_save', 20, 3 );
function met_on_save( $post_id, $post, $update ) {
	if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'auto-draft' === $post->post_status ) {
		return;
	}
	// Säkerställ att ACF hunnit spara sina värden (ACF sparar på prioritet 10).
	// Nummer och år måste alltid finnas (sorteringen kräver det) – läs av titeln om de är tomma.
	$parsed = met_parse_title( $post->post_title );
	foreach ( array( 'nummer', 'ar' ) as $key ) {
		if ( ! (int) get_post_meta( $post_id, $key, true ) ) {
			update_post_meta( $post_id, $key, (int) $parsed[ $key ] );
		}
	}
	$ar = (int) get_post_meta( $post_id, 'ar', true );
	if ( $ar && ! has_term( '', MET_TAX, $post_id ) ) {
		wp_set_object_terms( $post_id, (string) $ar, MET_TAX );
	}
	// Normalisera ISSUU-länken till kanonisk form.
	$issuu     = get_post_meta( $post_id, 'issuu_url', true );
	$canonical = met_issuu_canonical( $issuu );
	if ( $canonical && $canonical !== $issuu ) {
		update_post_meta( $post_id, 'issuu_url', $canonical );
	}
	// Automatiskt omslag.
	if ( $canonical && ! has_post_thumbnail( $post_id ) && empty( $GLOBALS['met_importing'] ) ) {
		$result = met_fetch_cover( $post_id );
		if ( is_wp_error( $result ) ) {
			set_transient( 'met_notice_' . get_current_user_id(), $result->get_error_message() . ' Välj ett omslag manuellt under ”Omslag”.', 60 );
		}
	}
}

add_action( 'admin_notices', function () {
	$key = 'met_notice_' . get_current_user_id();
	$msg = get_transient( $key );
	if ( $msg ) {
		delete_transient( $key );
		printf( '<div class="notice notice-warning is-dismissible"><p><strong>E-tidning:</strong> %s</p></div>', esc_html( $msg ) );
	}
} );

/* -------------------------------------------------------------------------
 * 5. Sortering: nyast först (år, sedan nummer)
 * ---------------------------------------------------------------------- */

function met_order_args() {
	return array(
		'meta_query' => array(
			'relation' => 'AND',
			'met_ar'     => array( 'key' => 'ar', 'type' => 'NUMERIC', 'compare' => 'EXISTS' ),
			'met_nummer' => array( 'key' => 'nummer', 'type' => 'NUMERIC', 'compare' => 'EXISTS' ),
		),
		'orderby'    => array( 'met_ar' => 'DESC', 'met_nummer' => 'DESC', 'date' => 'DESC' ),
	);
}

// Elementor Loop Grid / Posts: sätt Query ID = "etidningar" i widgeten för samma sortering.
add_action( 'elementor/query/etidningar', function ( $query ) {
	$query->set( 'post_type', MET_CPT );
	foreach ( met_order_args() as $k => $v ) {
		$query->set( $k, $v );
	}
} );

// Samma ordning i adminlistan som standard.
add_action( 'pre_get_posts', function ( $q ) {
	if ( is_admin() && $q->is_main_query() && MET_CPT === $q->get( 'post_type' ) && ! $q->get( 'orderby' ) ) {
		foreach ( met_order_args() as $k => $v ) {
			$q->set( $k, $v );
		}
	}
} );

/* -------------------------------------------------------------------------
 * 6. Läsaren (ISSUU) på nummersidan
 * ---------------------------------------------------------------------- */

function met_reader_html( $post_id = 0 ) {
	$post_id = $post_id ? $post_id : get_the_ID();
	$src     = met_issuu_embed_src( get_post_meta( $post_id, 'issuu_url', true ) );
	if ( ! $src ) {
		return '';
	}
	return sprintf(
		'<div class="met-reader"><iframe src="%s" title="%s" allow="fullscreen" allowfullscreen loading="lazy"></iframe></div>',
		esc_url( $src ),
		esc_attr( get_the_title( $post_id ) )
	);
}

// [etidning_lasare] – för Elementors single-mall. Utan mall läggs läsaren in automatiskt.
add_shortcode( 'etidning_lasare', function ( $atts ) {
	$atts = shortcode_atts( array( 'id' => 0 ), $atts );
	met_enqueue_assets();
	return met_reader_html( (int) $atts['id'] );
} );

add_filter( 'the_content', function ( $content ) {
	if ( is_singular( MET_CPT ) && in_the_loop() && is_main_query() && false === strpos( $content, 'met-reader' ) ) {
		met_enqueue_assets();
		$back = get_option( 'met_archive_page' ) ? get_permalink( (int) get_option( 'met_archive_page' ) ) : home_url( '/e-tidning/' );
		$content = met_reader_html() . $content . sprintf( '<p class="met-back"><a href="%s">&larr; Alla nummer</a></p>', esc_url( $back ) );
	}
	return $content;
} );

/* -------------------------------------------------------------------------
 * 7. Griddet: [etidningar]
 *
 * Attribut:
 *   per_ar="1"        Gruppera under årsrubriker (standard 1)
 *   kolumner="4"      Antal kolumner på dator (standard 4)
 *   antal="-1"        Max antal nummer (standard alla)
 *   oppna="sida"      "sida" = gå till nummersidan, "ruta" = öppna läsaren i en ruta direkt
 * ---------------------------------------------------------------------- */

add_shortcode( 'etidningar', 'met_grid_shortcode' );
function met_grid_shortcode( $atts ) {
	$atts = shortcode_atts( array(
		'per_ar'   => '1',
		'kolumner' => '4',
		'antal'    => '-1',
		'oppna'    => 'sida',
	), $atts, 'etidningar' );

	met_enqueue_assets();

	$q = new WP_Query( array_merge( array(
		'post_type'      => MET_CPT,
		'post_status'    => 'publish',
		'posts_per_page' => (int) $atts['antal'],
		'no_found_rows'  => true,
	), met_order_args() ) );

	if ( ! $q->have_posts() ) {
		return '<p class="met-empty">Inga nummer publicerade ännu.</p>';
	}

	$cols  = max( 1, min( 6, (int) $atts['kolumner'] ) );
	$modal = ( 'ruta' === $atts['oppna'] );
	$group = ( '1' === (string) $atts['per_ar'] );

	$out      = sprintf( '<div class="met-archive" style="--met-cols:%d">', $cols );
	$curr_ar  = null;
	$open     = false;

	while ( $q->have_posts() ) {
		$q->the_post();
		$id = get_the_ID();
		$ar = (int) get_post_meta( $id, 'ar', true );

		if ( $group && $ar !== $curr_ar ) {
			if ( $open ) {
				$out .= '</div>';
			}
			$out    .= sprintf( '<h3 class="met-year">%s</h3><div class="met-grid">', $ar ? esc_html( $ar ) : 'Övriga' );
			$open    = true;
			$curr_ar = $ar;
		} elseif ( ! $open ) {
			$out .= '<div class="met-grid">';
			$open = true;
		}

		$img = has_post_thumbnail( $id )
			? get_the_post_thumbnail( $id, 'large', array( 'class' => 'met-cover', 'loading' => 'lazy', 'alt' => 'Omslag ' . get_the_title( $id ) ) )
			: '<span class="met-cover met-cover--empty"></span>';

		$attrs = sprintf( 'href="%s"', esc_url( get_permalink( $id ) ) );
		if ( $modal ) {
			$attrs .= sprintf( ' data-met-embed="%s"', esc_url( met_issuu_embed_src( get_post_meta( $id, 'issuu_url', true ) ) ) );
		}

		$out .= sprintf(
			'<a class="met-item" %s>%s<span class="met-title">%s</span></a>',
			$attrs,
			$img,
			esc_html( get_the_title( $id ) )
		);
	}
	if ( $open ) {
		$out .= '</div>';
	}
	$out .= '</div>';
	wp_reset_postdata();

	if ( $modal ) {
		$out .= '<div class="met-modal" hidden><div class="met-modal__inner"><button type="button" class="met-modal__close" aria-label="Stäng">&times;</button><div class="met-reader"><iframe allow="fullscreen" allowfullscreen title="E-tidning"></iframe></div></div></div>';
	}
	return $out;
}

function met_enqueue_assets() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;
	$url  = plugin_dir_url( __FILE__ );
	wp_enqueue_style( 'met', $url . 'assets/etidningar.css', array(), MET_VERSION );
	wp_enqueue_script( 'met', $url . 'assets/etidningar.js', array(), MET_VERSION, true );
}

/* -------------------------------------------------------------------------
 * 8. Admin: omslag i listan + snabbknapp för att hämta omslag igen
 * ---------------------------------------------------------------------- */

add_filter( 'manage_' . MET_CPT . '_posts_columns', function ( $cols ) {
	$new = array();
	foreach ( $cols as $k => $v ) {
		if ( 'title' === $k ) {
			$new['met_cover'] = 'Omslag';
		}
		$new[ $k ] = $v;
	}
	$new['met_issuu'] = 'ISSUU';
	return $new;
} );
add_action( 'manage_' . MET_CPT . '_posts_custom_column', function ( $col, $post_id ) {
	if ( 'met_cover' === $col ) {
		echo has_post_thumbnail( $post_id ) ? get_the_post_thumbnail( $post_id, array( 50, 70 ) ) : '—';
	}
	if ( 'met_issuu' === $col ) {
		$u = get_post_meta( $post_id, 'issuu_url', true );
		echo $u ? sprintf( '<a href="%s" target="_blank" rel="noopener">Öppna</a>', esc_url( $u ) ) : '<span style="color:#b32d2e">Saknas</span>';
	}
}, 10, 2 );
add_action( 'admin_head', function () {
	echo '<style>.column-met_cover{width:60px}.column-met_cover img{width:50px;height:auto;display:block}</style>';
} );

add_filter( 'post_row_actions', function ( $actions, $post ) {
	if ( MET_CPT === $post->post_type && current_user_can( 'edit_post', $post->ID ) ) {
		$actions['met_cover'] = sprintf(
			'<a href="%s">Hämta omslag från ISSUU</a>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=met_refetch&post=' . $post->ID ), 'met_refetch_' . $post->ID ) )
		);
	}
	return $actions;
}, 10, 2 );
add_action( 'admin_post_met_refetch', function () {
	$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
	check_admin_referer( 'met_refetch_' . $post_id );
	if ( ! current_user_can( 'edit_post', $post_id ) ) {
		wp_die( 'Behörighet saknas.' );
	}
	$result = met_fetch_cover( $post_id ); // Ersätter omslaget, raderar inte den gamla bilden.
	if ( is_wp_error( $result ) ) {
		set_transient( 'met_notice_' . get_current_user_id(), $result->get_error_message(), 60 );
	}
	wp_safe_redirect( admin_url( 'edit.php?post_type=' . MET_CPT ) );
	exit;
} );

/* -------------------------------------------------------------------------
 * 9. Import av befintliga nummer + inställningar
 * ---------------------------------------------------------------------- */

require_once __DIR__ . '/includes/import.php';

/* -------------------------------------------------------------------------
 * 10. Korsordsfacit
 * ---------------------------------------------------------------------- */

require_once __DIR__ . '/includes/facit.php';
