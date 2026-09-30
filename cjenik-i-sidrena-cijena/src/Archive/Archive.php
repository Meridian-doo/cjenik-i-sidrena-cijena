<?php
/**
 * @package Cjenik
 */

namespace Cjenik\Archive;

use Cjenik\Outlet;
use Cjenik\Outlets;
use Cjenik\Publishing\Publication;
use Cjenik\Publishing\PublicationLog;
use Cjenik\Settings;
use Cjenik\Zagreb;

/**
 * Public access to published Price Lists: a stable "latest" URL per Outlet,
 * a download URL per Storage Number, an archive page and shortcode, and an
 * optional JSON index. Files are found through the Publication Log, never by
 * listing the folder.
 */
final class Archive {
	public const SHORTCODE     = 'cjenik_arhiva';
	public const PAGE_OPTION   = 'cjenik_archive_page_id';
	private const STYLE        = 'cjenik-arhiva';
	private const QUERY_OUTLET = 'cjenik_outlet';
	private const QUERY_FILE   = 'cjenik_file';

	public function __construct(
		private PublicationLog $log,
		private Outlets $outlets,
		private Settings $settings,
	) {}

	public static function register(): void {
		add_action( 'init', array( self::class, 'add_rewrite_rules' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'enqueue_style' ) );
		add_filter(
			'query_vars',
			static fn( array $vars ) => array_merge( $vars, array( self::QUERY_OUTLET, self::QUERY_FILE ) )
		);
		add_action(
			'template_redirect',
			static function (): void {
				$response = cjenik()->archive()->respond( $GLOBALS['wp']->query_vars );
				if ( $response ) {
					$response->send();
				}
			},
			0
		);
		add_shortcode(
			self::SHORTCODE,
			static fn( $atts ) => cjenik()->archive()->render( (string) ( shortcode_atts( array( 'outlet' => Outlets::WEBSHOP ), $atts, self::SHORTCODE )['outlet'] ) )
		);
		// A missing trailing slash must not redirect crawlers away from the file.
		add_filter(
			'redirect_canonical',
			static fn( $redirect ) => get_query_var( self::QUERY_OUTLET ) ? false : $redirect
		);
	}

	public static function add_rewrite_rules(): void {
		$outlet = '([a-z0-9_-]+)';
		add_rewrite_rule( "^cjenik/{$outlet}\\.(?:csv|xml)$", 'index.php?' . self::QUERY_OUTLET . '=$matches[1]&' . self::QUERY_FILE . '=latest', 'top' );
		add_rewrite_rule( "^cjenik/{$outlet}/index\\.json$", 'index.php?' . self::QUERY_OUTLET . '=$matches[1]&' . self::QUERY_FILE . '=index', 'top' );
		add_rewrite_rule( "^cjenik/{$outlet}/([0-9]+)/?$", 'index.php?' . self::QUERY_OUTLET . '=$matches[1]&' . self::QUERY_FILE . '=$matches[2]', 'top' );
	}

	/** Creates the default archive page, once. */
	public static function ensure_page(): void {
		$page_id = (int) get_option( self::PAGE_OPTION );
		if ( $page_id && get_post( $page_id ) ) {
			return;
		}
		$page_id = wp_insert_post(
			array(
				'post_type'    => 'page',
				'post_status'  => 'publish',
				'post_title'   => __( 'Price list archive', 'cjenik-i-sidrena-cijena' ),
				'post_name'    => 'arhiva-cjenika',
				'post_content' => '<!-- wp:shortcode -->[' . self::SHORTCODE . ']<!-- /wp:shortcode -->',
			)
		);
		if ( $page_id ) {
			update_option( self::PAGE_OPTION, $page_id );
		}
	}

	/** The stable URL that always serves the Outlet's newest file. */
	public function latest_url( Outlet $outlet ): string {
		$latest = $this->log->latest( $outlet->id );
		$format = $latest ? $latest->format : (string) $this->settings->get( 'format' );
		return $this->url( $outlet, 'latest', $outlet->id . '.' . $format );
	}

	public function index_url( Outlet $outlet ): string {
		return $this->url( $outlet, 'index', $outlet->id . '/index.json' );
	}

	public function download_url( Publication $publication ): string {
		$outlet = $this->outlets->get( $publication->outlet_id );
		return $outlet ? $this->url( $outlet, (string) $publication->storage_number, $outlet->id . '/' . $publication->storage_number . '/' ) : $publication->url();
	}

	public function page_url(): string {
		$page_id = (int) get_option( self::PAGE_OPTION );
		return $page_id && 'publish' === get_post_status( $page_id ) ? (string) get_permalink( $page_id ) : '';
	}

	/**
	 * The response for a request to one of the archive URLs, or null if the
	 * request isn't for the archive.
	 *
	 * @param array<string, mixed> $query_vars
	 */
	public function respond( array $query_vars ): ?Response {
		$outlet_id = (string) ( $query_vars[ self::QUERY_OUTLET ] ?? '' );
		if ( '' === $outlet_id ) {
			return null;
		}
		$outlet = $this->outlets->get( $outlet_id );
		$file   = (string) ( $query_vars[ self::QUERY_FILE ] ?? '' );
		if ( ! $outlet ) {
			return self::not_found();
		}
		if ( 'index' === $file ) {
			return $this->settings->get( 'json_index' ) ? $this->index_response( $outlet ) : self::not_found();
		}
		$publication = 'latest' === $file
			? $this->log->latest( $outlet->id )
			: $this->log->by_storage_number( $outlet->id, (int) $file );
		if ( ! $publication || ! $publication->is_available() || ! is_readable( $publication->path() ) ) {
			return self::not_found();
		}
		return new Response(
			200,
			array(
				'Content-Type'           => $publication->mime_type() . '; charset=utf-8',
				'Content-Disposition'    => "inline; filename*=UTF-8''" . rawurlencode( $publication->file_name ),
				'Content-Length'         => (string) filesize( $publication->path() ),
				'Last-Modified'          => gmdate( 'D, d M Y H:i:s', $publication->published_at->getTimestamp() ) . ' GMT',
				'Cache-Control'          => 'latest' === $file ? 'no-cache' : 'public, max-age=86400',
				'X-Content-Type-Options' => 'nosniff',
			),
			null,
			$publication->path()
		);
	}

	/**
	 * Registers the archive stylesheet, and enqueues it early when the page
	 * being shown carries the shortcode. Rendering enqueues it as well, for
	 * widgets and blocks.
	 */
	public static function enqueue_style(): void {
		if ( ! wp_style_is( self::STYLE, 'registered' ) ) {
			wp_register_style( self::STYLE, plugins_url( 'assets/archive.css', CJENIK_FILE ), array(), CJENIK_VERSION );
		}
		$post = is_singular() ? get_post() : null;
		if ( $post && has_shortcode( (string) $post->post_content, self::SHORTCODE ) ) {
			wp_enqueue_style( self::STYLE );
		}
	}

	/**
	 * The archive for the shortcode and the default archive page: the newest
	 * file with its download, then every file still published.
	 */
	public function render( string $outlet_id ): string {
		$outlet = $this->outlets->get( $outlet_id );
		if ( ! $outlet ) {
			return '';
		}
		self::enqueue_style();
		wp_enqueue_style( self::STYLE );
		$publications = $this->log->available( $outlet->id );
		if ( ! $publications ) {
			return '<div class="cjenik-arhiva cjenik-arhiva--empty"><p>' . esc_html__( 'No price list has been published yet.', 'cjenik-i-sidrena-cijena' ) . '</p></div>';
		}
		$id = wp_unique_id( 'cjenik-arhiva-' );
		return '<section class="cjenik-arhiva" aria-labelledby="' . esc_attr( $id ) . '">'
			. $this->latest_html( $outlet, $publications[0], $id )
			. $this->table_html( $publications )
			. '</section>';
	}

	private function latest_html( Outlet $outlet, Publication $latest, string $heading_id ): string {
		$moment = Zagreb::local( $latest->published_at );
		$meta   = array(
			esc_html( sprintf( '%1$s, %2$s (%3$s)', $outlet->type, $outlet->address, $outlet->code ) ),
			'<time datetime="' . esc_attr( $moment->format( DATE_ATOM ) ) . '">' . esc_html(
				sprintf(
					/* translators: 1: date, 2: time */
					__( 'Published on %1$s at %2$s', 'cjenik-i-sidrena-cijena' ),
					$moment->format( 'd.m.Y.' ),
					$moment->format( 'H:i' )
				)
			) . '</time>',
			/* translators: %d: number of items in the price list */
			esc_html( sprintf( _n( '%d item', '%d items', $latest->row_count, 'cjenik-i-sidrena-cijena' ), $latest->row_count ) ),
		);
		$links  = sprintf(
			'%s <a href="%s">%s</a>',
			esc_html__( 'Permanent link to the newest file:', 'cjenik-i-sidrena-cijena' ),
			esc_url( $this->latest_url( $outlet ) ),
			esc_html( $this->latest_url( $outlet ) )
		);
		if ( $this->settings->get( 'json_index' ) ) {
			$links .= ' <a href="' . esc_url( $this->index_url( $outlet ) ) . '">' . esc_html__( 'Machine-readable index (JSON)', 'cjenik-i-sidrena-cijena' ) . '</a>';
		}
		return '<div class="cjenik-arhiva__latest">'
			. '<div>'
			. '<h2 class="cjenik-arhiva__title" id="' . esc_attr( $heading_id ) . '">' . esc_html__( 'Latest price list', 'cjenik-i-sidrena-cijena' ) . '</h2>'
			. '<p class="cjenik-arhiva__meta"><span>' . implode( '</span><span>', $meta ) . '</span></p>'
			. '</div>'
			. '<a class="cjenik-arhiva__download" href="' . esc_url( $latest->url() ) . '">'
			. self::download_icon()
			/* translators: %s: file format, e.g. CSV */
			. esc_html( sprintf( __( 'Download (%s)', 'cjenik-i-sidrena-cijena' ), strtoupper( $latest->format ) ) )
			. '</a>'
			. '<p class="cjenik-arhiva__links">' . $links . '</p>'
			. '</div>';
	}

	/**
	 * @param list<Publication> $publications
	 */
	private function table_html( array $publications ): string {
		$storage = __( 'Storage number', 'cjenik-i-sidrena-cijena' );
		$items   = __( 'Items', 'cjenik-i-sidrena-cijena' );
		$html    = '<h2 class="cjenik-arhiva__heading">' . esc_html__( 'All published price lists', 'cjenik-i-sidrena-cijena' ) . '</h2>';
		$html   .= '<table class="cjenik-arhiva__table"><thead><tr>';
		$html   .= '<th scope="col">' . esc_html__( 'Published', 'cjenik-i-sidrena-cijena' ) . '</th>';
		$html   .= '<th scope="col" class="cjenik-arhiva__num">' . esc_html( $storage ) . '</th>';
		$html   .= '<th scope="col" class="cjenik-arhiva__num">' . esc_html( $items ) . '</th>';
		$html   .= '<th scope="col">' . esc_html__( 'File', 'cjenik-i-sidrena-cijena' ) . '</th>';
		$html   .= '</tr></thead><tbody>';
		foreach ( $publications as $publication ) {
			$moment = Zagreb::local( $publication->published_at );
			$html  .= '<tr>';
			$html  .= '<td><time class="cjenik-arhiva__time" datetime="' . esc_attr( $moment->format( DATE_ATOM ) ) . '"><b>' . esc_html( $moment->format( 'd.m.Y.' ) ) . '</b> ' . esc_html( $moment->format( 'H:i' ) ) . '</time></td>';
			$html  .= '<td class="cjenik-arhiva__num" data-label="' . esc_attr( $storage ) . '">' . esc_html( (string) $publication->storage_number ) . '</td>';
			$html  .= '<td class="cjenik-arhiva__num" data-label="' . esc_attr( $items ) . '">' . esc_html( (string) $publication->row_count ) . '</td>';
			// The legal file name is long; let it wrap at its underscores.
			$html .= '<td class="cjenik-arhiva__file"><a href="' . esc_url( $publication->url() ) . '">' . str_replace( '_', '_<wbr>', esc_html( $publication->file_name ) ) . '</a></td>';
			$html .= '</tr>';
		}
		$days = $this->settings->retention_days();
		return $html . '</tbody></table>'
			/* translators: %d: number of days */
			. '<p class="cjenik-arhiva__note">' . esc_html( sprintf( _n( 'Each file stays available for at least %d day after it is published.', 'Each file stays available for at least %d days after it is published.', $days, 'cjenik-i-sidrena-cijena' ), $days ) ) . '</p>';
	}

	private static function download_icon(): string {
		return '<svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 4v11"/><path d="m7 10 5 5 5-5"/><path d="M4 20h16"/></svg>';
	}

	/**
	 * The machine-readable list of archived files.
	 *
	 * @return array<string, mixed>
	 */
	public function index( Outlet $outlet ): array {
		return array(
			'prodajni_objekt' => $outlet->describe(),
			'najnoviji'       => $this->latest_url( $outlet ),
			'datoteke'        => array_map(
				static fn( Publication $publication ) => array(
					'broj_pohrane' => $publication->storage_number,
					'objavljeno'   => Zagreb::local( $publication->published_at )->format( DATE_ATOM ),
					'naziv'        => $publication->file_name,
					'url'          => $publication->url(),
					'redaka'       => $publication->row_count,
					'sha256'       => $publication->sha256,
				),
				$this->log->available( $outlet->id )
			),
		);
	}

	private function index_response( Outlet $outlet ): Response {
		return new Response(
			200,
			array(
				'Content-Type'  => 'application/json; charset=utf-8',
				'Cache-Control' => 'no-cache',
			),
			(string) wp_json_encode( $this->index( $outlet ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT )
		);
	}

	private function url( Outlet $outlet, string $file, string $pretty_path ): string {
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( '/cjenik/' . $pretty_path );
		}
		return add_query_arg(
			array(
				self::QUERY_OUTLET => $outlet->id,
				self::QUERY_FILE   => $file,
			),
			home_url( '/' )
		);
	}

	private static function not_found(): Response {
		return new Response( 404, array( 'Content-Type' => 'text/plain; charset=utf-8' ), 'Not found' );
	}
}
