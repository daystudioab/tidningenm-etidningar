<?php
/**
 * Import av befintliga nummer-sidor + inställningar.
 *
 * Importen LÄSER bara de gamla sidorna. Den skapar nya E-tidning-poster och
 * återanvänder befintliga omslagsbilder i mediabiblioteket. Inga sidor raderas
 * eller ändras.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'admin_menu', function () {
	add_submenu_page(
		'edit.php?post_type=' . MET_CPT,
		'Import & inställningar',
		'Import & inställningar',
		'edit_pages',
		'met-import',
		'met_import_page'
	);
} );

/**
 * Plattar ut sidans innehåll (post_content + Elementor-data) till en sökbar sträng.
 */
function met_page_haystack( $page_id ) {
	$hay = (string) get_post_field( 'post_content', $page_id );
	$el  = get_post_meta( $page_id, '_elementor_data', true );
	if ( $el ) {
		$decoded = is_string( $el ) ? json_decode( $el, true ) : $el;
		$hay    .= ' ' . ( $decoded ? wp_json_encode( $decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) : stripslashes( (string) $el ) );
	}
	return html_entity_decode( str_replace( array( '&', '\/' ), array( '&', '/' ), $hay ) );
}

/**
 * Letar upp en ISSUU-länk i en befintlig sida. Returnerar kanonisk URL eller ''.
 */
function met_find_issuu_in_page( $page_id ) {
	$hay = met_page_haystack( $page_id );
	// Vanlig länk, med eller utan https: (även //issuu.com/… och www.).
	if ( preg_match( '~(?:https?:)?//(?:www\.)?issuu\.com/[^/\s"\'<>]+/docs/[^\s"\'<>?#]+~i', $hay, $m ) ) {
		return met_issuu_canonical( 'https:' . preg_replace( '~^https?:~i', '', $m[0] ) );
	}
	// Inbäddningslänk e.issuu.com/embed.html?d=…&u=… (även protokollrelativ).
	if ( preg_match( '~(?:https?:)?//e\.issuu\.com/embed\.html\?[^\s"\'<>]+~i', $hay, $m ) ) {
		return met_issuu_canonical( 'https:' . preg_replace( '~^https?:~i', '', $m[0] ) );
	}
	return '';
}

/**
 * Bygger en karta "sid-URL => bild-ID" utifrån bilderna på den befintliga
 * e-tidningssidan (där varje omslag länkar till sitt nummer).
 */
function met_cover_map( $archive_page_id ) {
	$map = array();
	if ( ! $archive_page_id ) {
		return $map;
	}
	$el   = get_post_meta( $archive_page_id, '_elementor_data', true );
	$data = is_string( $el ) ? json_decode( $el, true ) : $el;
	if ( is_array( $data ) ) {
		$walk = function ( $elements ) use ( &$walk, &$map ) {
			foreach ( (array) $elements as $e ) {
				$s = isset( $e['settings'] ) ? $e['settings'] : array();
				if ( ! empty( $s['image']['id'] ) && ! empty( $s['link']['url'] ) ) {
					$map[ met_norm_url( $s['link']['url'] ) ] = (int) $s['image']['id'];
				}
				if ( ! empty( $e['elements'] ) ) {
					$walk( $e['elements'] );
				}
			}
		};
		$walk( $data );
	}
	// Reserv: klassisk HTML <a href="…"><img class="wp-image-123">.
	$html = (string) get_post_field( 'post_content', $archive_page_id );
	if ( preg_match_all( '~<a[^>]+href="([^"]+)"[^>]*>\s*<img[^>]+wp-image-(\d+)~i', $html, $mm, PREG_SET_ORDER ) ) {
		foreach ( $mm as $m ) {
			$key = met_norm_url( $m[1] );
			if ( ! isset( $map[ $key ] ) ) {
				$map[ $key ] = (int) $m[2];
			}
		}
	}
	return $map;
}

function met_norm_url( $url ) {
	$path = wp_parse_url( $url, PHP_URL_PATH );
	return trailingslashit( strtolower( (string) $path ) );
}

/**
 * Hittar kandidater: sidor som innehåller en ISSUU-inbäddning.
 */
function met_find_candidates() {
	global $wpdb;
	$ids = $wpdb->get_col(
		"SELECT DISTINCT p.ID FROM {$wpdb->posts} p
		 LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_data'
		 WHERE p.post_type = 'page' AND p.post_status IN ('publish','private','draft')
		 AND ( p.post_title REGEXP '^[[:space:]]*nr' OR p.post_content LIKE '%issuu%' OR m.meta_value LIKE '%issuu%' )"
	);
	$imported = met_imported_map();
	$out      = array();
	foreach ( $ids as $id ) {
		$id    = (int) $id;
		$title = get_the_title( $id );
		$p     = met_parse_title( $title );
		$out[] = array(
			'id'       => $id,
			'title'    => $title,
			'url'      => get_permalink( $id ),
			'issuu'    => met_find_issuu_in_page( $id ),
			'nummer'   => $p['nummer'],
			'ar'       => $p['ar'],
			'is_issue' => $p['nummer'] && $p['ar'],
			'imported' => isset( $imported[ $id ] ) ? $imported[ $id ] : 0,
		);
	}
	// Flera gamla sidor för samma nummer? Välj den bästa, markera resten som dubletter.
	$covers = met_cover_map( met_default_archive_page() );
	$best   = array();
	foreach ( $out as $i => $c ) {
		$out[ $i ]['score']     = met_page_score( $c['id'], $covers, $c['issuu'] );
		$out[ $i ]['duplicate'] = false;
		if ( $c['is_issue'] ) {
			$k = $c['ar'] . '-' . $c['nummer'];
			if ( ! isset( $best[ $k ] ) || $out[ $i ]['score'] > $out[ $best[ $k ] ]['score'] ) {
				$best[ $k ] = $i;
			}
		}
	}
	foreach ( $out as $i => $c ) {
		if ( $c['is_issue'] && $best[ $c['ar'] . '-' . $c['nummer'] ] !== $i ) {
			$out[ $i ]['duplicate'] = true;
		}
	}
	usort( $out, function ( $a, $b ) {
		return array( $b['ar'], $b['nummer'], $b['score'] ) <=> array( $a['ar'], $a['nummer'], $a['score'] );
	} );
	return $out;
}

/**
 * Poäng för hur sannolikt en gammal sida är "den riktiga" versionen av ett nummer.
 */
function met_page_score( $page_id, $covers, $issuu = null ) {
	$score = 0;
	if ( isset( $covers[ met_norm_url( get_permalink( $page_id ) ) ] ) ) {
		$score += 8; // Länkas från nuvarande e-tidningssida.
	}
	if ( null === $issuu ) {
		$issuu = met_find_issuu_in_page( $page_id );
	}
	if ( $issuu ) {
		$score += 4;
	}
	if ( 'publish' === get_post_status( $page_id ) ) {
		$score += 2;
	}
	if ( ! preg_match( '~-\d+/?$~', (string) get_post_field( 'post_name', $page_id ) ) ) {
		$score += 1; // Adress utan "-2"-suffix.
	}
	return $score;
}

/** Finns redan en E-tidning (ej i papperskorgen) för nummer + år? */
function met_find_issue_post( $nummer, $ar ) {
	$ids = get_posts( array(
		'post_type'      => MET_CPT,
		'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'meta_query'     => array(
			array( 'key' => 'nummer', 'value' => (int) $nummer, 'type' => 'NUMERIC' ),
			array( 'key' => 'ar', 'value' => (int) $ar, 'type' => 'NUMERIC' ),
		),
	) );
	return $ids ? (int) $ids[0] : 0;
}

/** Karta gammal sid-ID => ny E-tidning-ID. */
function met_imported_map() {
	global $wpdb;
	$rows = $wpdb->get_results( $wpdb->prepare(
		"SELECT pm.meta_value AS src, pm.post_id AS dst FROM {$wpdb->postmeta} pm
		 JOIN {$wpdb->posts} p ON p.ID = pm.post_id
		 WHERE pm.meta_key = '_met_source_page' AND p.post_type = %s AND p.post_status <> 'trash'",
		MET_CPT
	) );
	$map = array();
	foreach ( $rows as $r ) {
		$map[ (int) $r->src ] = (int) $r->dst;
	}
	return $map;
}

function met_default_archive_page() {
	$saved = (int) get_option( 'met_archive_page' );
	if ( $saved ) {
		return $saved;
	}
	$p = get_page_by_path( 'e-tidning' );
	return $p ? (int) $p->ID : 0;
}

/* --------------------------------------------------------------------- */

add_action( 'admin_post_met_import', function () {
	check_admin_referer( 'met_import' );
	if ( ! current_user_can( 'edit_pages' ) ) {
		wp_die( 'Behörighet saknas.' );
	}
	@set_time_limit( 300 );

	$ids        = isset( $_POST['pages'] ) ? array_map( 'intval', (array) $_POST['pages'] ) : array();
	$status     = ( isset( $_POST['status'] ) && 'publish' === $_POST['status'] ) ? 'publish' : 'draft';
	$covers     = met_cover_map( met_default_archive_page() );
	$imported   = met_imported_map();
	$done       = 0;
	$no_cover   = 0;
	$no_issuu   = 0;

	$GLOBALS['met_importing'] = true; // Importen sköter omslagen själv.
	// Bästa versionen av varje nummer först, så att den vinner om flera är markerade.
	usort( $ids, function ( $a, $b ) use ( $covers ) {
		return met_page_score( $b, $covers ) <=> met_page_score( $a, $covers );
	} );
	foreach ( $ids as $page_id ) {
		if ( isset( $imported[ $page_id ] ) || 'page' !== get_post_type( $page_id ) ) {
			continue; // Redan importerad – skapa inga dubletter.
		}
		$title = get_the_title( $page_id );
		$p     = met_parse_title( $title );
		$issuu = met_find_issuu_in_page( $page_id );
		if ( $p['nummer'] && $p['ar'] ) {
			if ( met_find_issue_post( $p['nummer'], $p['ar'] ) ) {
				continue; // Numret finns redan – skapa ingen dublett.
			}
			$title = sprintf( 'Nr %d %d', $p['nummer'], $p['ar'] );
		}

		// Ungefärligt utgivningsdatum så att även datumsortering blir rätt.
		$months = array( 1 => 3, 2 => 5, 3 => 9, 4 => 12 );
		$date   = $p['ar'] ? sprintf( '%04d-%02d-01 12:00:00', $p['ar'], isset( $months[ $p['nummer'] ] ) ? $months[ $p['nummer'] ] : 1 ) : current_time( 'mysql' );

		$new_id = wp_insert_post( array(
			'post_type'   => MET_CPT,
			'post_title'  => $title,
			'post_status' => $issuu ? $status : 'draft', // Utan ISSUU-länk blir det alltid utkast.
			'post_date'   => $date,
			'post_name'   => sanitize_title( $title ),
			'meta_input'  => array(
				'_met_source_page' => $page_id,
				'nummer'           => $p['nummer'],
				'ar'               => $p['ar'],
				'issuu_url'        => $issuu,
			),
		), true );

		if ( is_wp_error( $new_id ) ) {
			continue;
		}
		if ( $p['ar'] ) {
			wp_set_object_terms( $new_id, (string) $p['ar'], MET_TAX );
		}

		$key = met_norm_url( get_permalink( $page_id ) );
		if ( ! empty( $covers[ $key ] ) && wp_attachment_is_image( $covers[ $key ] ) ) {
			set_post_thumbnail( $new_id, $covers[ $key ] );
		} elseif ( get_post_thumbnail_id( $page_id ) ) {
			set_post_thumbnail( $new_id, get_post_thumbnail_id( $page_id ) );
		} elseif ( $issuu && ! is_wp_error( met_fetch_cover( $new_id ) ) ) {
			// Hämtat från ISSUU.
		} else {
			$no_cover++;
		}
		if ( ! $issuu ) {
			$no_issuu++;
		}
		$done++;
	}

	wp_safe_redirect( add_query_arg( array(
		'post_type' => MET_CPT,
		'page'      => 'met-import',
		'done'      => $done,
		'nocover'   => $no_cover,
		'noissuu'   => $no_issuu,
	), admin_url( 'edit.php' ) ) );
	exit;
} );

add_action( 'admin_post_met_settings', function () {
	check_admin_referer( 'met_settings' );
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Behörighet saknas.' );
	}
	update_option( 'met_redirects', ! empty( $_POST['met_redirects'] ) ? 1 : 0 );
	update_option( 'met_archive_page', isset( $_POST['met_archive_page'] ) ? (int) $_POST['met_archive_page'] : 0 );
	wp_safe_redirect( add_query_arg( array( 'post_type' => MET_CPT, 'page' => 'met-import', 'saved' => 1 ), admin_url( 'edit.php' ) ) );
	exit;
} );

/* --------------------------------------------------------------------- */

function met_import_page() {
	$candidates = met_find_candidates();
	$covers     = met_cover_map( met_default_archive_page() );
	?>
	<div class="wrap">
		<h1>E-tidningar – import &amp; inställningar</h1>

		<?php if ( isset( $_GET['done'] ) ) : ?>
			<div class="notice notice-success"><p>
				<?php printf( '%d nummer importerades.', (int) $_GET['done'] ); ?>
				<?php if ( ! empty( $_GET['nocover'] ) ) printf( ' %d saknar omslag.', (int) $_GET['nocover'] ); ?>
				<?php if ( ! empty( $_GET['noissuu'] ) ) printf( ' %d saknar ISSUU-länk och sparades som utkast – fyll i länken och publicera.', (int) $_GET['noissuu'] ); ?>
				De gamla sidorna är orörda.
			</p></div>
		<?php endif; ?>
		<?php if ( isset( $_GET['cleaned'] ) ) : ?>
			<div class="notice notice-success"><p><?php
				printf( '%d dubletter flyttades till papperskorgen (kan återställas där). %d titlar/adresser snyggades till. %d ISSUU-länkar hittades i efterhand.', (int) $_GET['cleaned'], (int) $_GET['renamed'], (int) $_GET['found'] );
			?> Inga gamla sidor har rörts.</p></div>
		<?php endif; ?>
		<?php if ( isset( $_GET['covers_done'] ) ) : ?>
			<div class="notice notice-success"><p><?php
				printf( '%d omslag hämtades från ISSUU.', (int) $_GET['covers_done'] );
				if ( ! empty( $_GET['covers_failed'] ) ) {
					printf( ' %d kunde inte hämtas och behöll sitt tidigare omslag.', (int) $_GET['covers_failed'] );
				}
			?> Tidigare omslag finns kvar i mediabiblioteket.</p></div>
		<?php endif; ?>
		<?php if ( isset( $_GET['covers_restored'] ) ) : ?>
			<div class="notice notice-success"><p><?php printf( '%d nummer fick tillbaka sitt tidigare omslag.', (int) $_GET['covers_restored'] ); ?></p></div>
		<?php endif; ?>
		<?php if ( isset( $_GET['trashed'] ) ) : ?>
			<div class="notice notice-success"><p><?php printf( '%d gamla nummersidor flyttades till papperskorgen. Deras adresser omdirigeras till de nya numren. Sidorna kan återställas från papperskorgen.', (int) $_GET['trashed'] ); ?></p></div>
		<?php endif; ?>
		<?php if ( isset( $_GET['saved'] ) ) : ?>
			<div class="notice notice-success"><p>Inställningarna sparades.</p></div>
		<?php endif; ?>

		<?php met_trash_section(); ?>
		<?php met_cover_section(); ?>
		<?php met_cleanup_section(); ?>

		<h2>1. Importera befintliga nummer</h2>
		<p>Listan visar sidor som ser ut som tidningsnummer eller innehåller en ISSUU-inbäddning. Importen <strong>skapar nya E-tidning-poster</strong> och återanvänder omslagen från din nuvarande e-tidningssida. <strong>Inga befintliga sidor ändras eller raderas.</strong></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'met_import' ); ?>
			<input type="hidden" name="action" value="met_import">
			<table class="widefat striped" style="max-width:1100px">
				<thead><tr>
					<td class="check-column"><input type="checkbox" onclick="document.querySelectorAll('.met-cb:not(:disabled)').forEach(c=>c.checked=this.checked)"></td>
					<th>Sida</th><th>Nr / år</th><th>ISSUU</th><th>Omslag</th><th>Status</th>
				</tr></thead>
				<tbody>
				<?php foreach ( $candidates as $c ) :
					$cover = isset( $covers[ met_norm_url( $c['url'] ) ] ) ? $covers[ met_norm_url( $c['url'] ) ] : 0;
					?>
					<tr>
						<th class="check-column"><input class="met-cb" type="checkbox" name="pages[]" value="<?php echo (int) $c['id']; ?>" <?php checked( $c['is_issue'] && ! $c['imported'] && ! $c['duplicate'] ); ?> <?php disabled( (bool) $c['imported'] ); ?>></th>
						<td><a href="<?php echo esc_url( $c['url'] ); ?>" target="_blank"><?php echo esc_html( $c['title'] ); ?></a></td>
						<td><?php echo $c['is_issue'] ? esc_html( $c['nummer'] . ' / ' . $c['ar'] ) : '<em>okänt</em>'; ?></td>
						<td><?php echo $c['issuu'] ? '<a href="' . esc_url( $c['issuu'] ) . '" target="_blank">✓ hittad</a>' : '<span style="color:#b32d2e">saknas</span>'; ?></td>
						<td><?php echo $cover ? wp_get_attachment_image( $cover, array( 40, 56 ) ) : '<span style="color:#996800">hämtas från ISSUU</span>'; ?></td>
						<td><?php
							if ( $c['imported'] ) {
								echo '<a href="' . esc_url( get_edit_post_link( $c['imported'] ) ) . '">Redan importerad</a>';
							} elseif ( $c['duplicate'] ) {
								echo '<span style="color:#996800">Dublett – hoppas över</span>';
							} else {
								echo '—';
							}
						?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p>
				<label><input type="radio" name="status" value="draft" checked> Importera som utkast (granska först)</label><br>
				<label><input type="radio" name="status" value="publish"> Publicera direkt</label>
			</p>
			<?php submit_button( 'Importera markerade nummer', 'primary', 'submit', false ); ?>
		</form>

		<hr>
		<h2>2. Inställningar</h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'met_settings' ); ?>
			<input type="hidden" name="action" value="met_settings">
			<table class="form-table">
				<tr>
					<th>Sida med alla nummer</th>
					<td><?php wp_dropdown_pages( array( 'name' => 'met_archive_page', 'selected' => met_default_archive_page(), 'show_option_none' => '— Välj —', 'option_none_value' => 0 ) ); ?>
					<p class="description">Används för länken ”Alla nummer” och för att hitta befintliga omslag vid import.</p></td>
				</tr>
				<tr>
					<th>Omdirigera gamla sidor</th>
					<td><label><input type="checkbox" name="met_redirects" value="1" <?php checked( get_option( 'met_redirects' ) ); ?>> Skicka besökare från gamla nummer-sidor till de nya (301)</label>
					<p class="description">Avstängt som standard. Slå på när du har kontrollerat de nya numren. Gäller bara gamla sidor vars nya nummer är publicerat. De gamla sidorna finns kvar och kan när som helst användas igen genom att slå av detta.</p></td>
				</tr>
			</table>
			<?php submit_button( 'Spara inställningar' ); ?>
		</form>

		<hr>
		<h2>3. Visa nummer på sajten</h2>
		<p>Lägg kortkoden i en Kortkod-widget i Elementor (eller i en vanlig sida):</p>
		<p><code>[etidningar]</code> – alla nummer i grid, grupperade per år, klick öppnar nummersidan.</p>
		<p><code>[etidningar oppna="ruta"]</code> – klick öppnar ISSUU-läsaren direkt i en ruta ovanpå sidan.</p>
		<p><code>[etidningar per_ar="0" antal="4" kolumner="4"]</code> – t.ex. de fyra senaste på startsidan.</p>
		<p>På nummersidorna läggs ISSUU-läsaren in automatiskt. Bygger du en egen Elementor-mall för E-tidning kan du placera läsaren med <code>[etidning_lasare]</code>. I Elementors Loop Grid ger Query ID <code>etidningar</code> rätt sortering.</p>
	</div>
	<?php
}

/* --------------------------------------------------------------------- */
// Valfria 301-omdirigeringar från gamla sidor (avstängt som standard).

add_action( 'template_redirect', function () {
	if ( ! get_option( 'met_redirects' ) || ! is_page() ) {
		return;
	}
	$page_id = get_queried_object_id();
	$target  = get_posts( array(
		'post_type'      => MET_CPT,
		'post_status'    => 'publish',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'meta_key'       => '_met_source_page',
		'meta_value'     => $page_id,
	) );
	if ( $target ) {
		wp_safe_redirect( get_permalink( $target[0] ), 301 );
		exit;
	}
} );

require_once __DIR__ . '/cleanup.php';
