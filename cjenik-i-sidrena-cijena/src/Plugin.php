<?php
/**
 * @package Cjenik
 */

namespace Cjenik;

use Cjenik\Anchors\AnchorRegistry;
use Cjenik\Archive\Archive;
use Cjenik\Import\CsvImporter;
use Cjenik\Prices\PriceHistory;
use Cjenik\Prices\PriceResolver;
use Cjenik\PriceList\FieldMapping;
use Cjenik\PriceList\PriceListBuilder;
use Cjenik\Publishing\Publisher;
use Cjenik\Publishing\PublicationLog;
use Cjenik\Publishing\Scheduler;
use Cjenik\Storefront\PriceDisplay;

/**
 * Service container and hook wiring. Services are created lazily and share one
 * Clock; swapping the Clock (tests) drops the cached services.
 */
final class Plugin {
	private static ?Plugin $instance = null;

	private Clock $clock;

	/** @var array<string, object> */
	private array $services = array();

	private bool $booted = false;

	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		$this->clock = new SystemClock();
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		Install\Installer::maybe_upgrade();
		add_action(
			'init',
			static function (): void {
				load_plugin_textdomain( 'cjenik-i-sidrena-cijena', false, dirname( plugin_basename( CJENIK_FILE ) ) . '/languages' );
			}
		);

		Prices\HistoryHooks::register();
		Archive::register();
		Scheduler::register();
		PriceDisplay::register();
		Alerts::register();
		SelfCheck::register();

		if ( is_admin() ) {
			Admin\AdminPages::register();
			Admin\SetupWizard::register();
			Admin\ProductFields::register();
			Admin\Notices::register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'cjenik', Cli\Command::class );
		}
		add_action( 'woocommerce_before_product_object_save', fn( $product ) => $this->anchors()->on_before_save( $product ), 5 );
	}

	public function clock(): Clock {
		return $this->clock;
	}

	public function use_clock( Clock $clock ): void {
		$this->clock    = $clock;
		$this->services = array();
	}

	public function settings(): Settings {
		return $this->service( Settings::class, fn() => new Settings() );
	}

	public function prices(): PriceResolver {
		return $this->service( PriceResolver::class, fn() => new PriceResolver( $this->settings() ) );
	}

	public function history(): PriceHistory {
		return $this->service( PriceHistory::class, fn() => new PriceHistory( $this->prices(), $this->clock ) );
	}

	public function anchors(): AnchorRegistry {
		return $this->service(
			AnchorRegistry::class,
			fn() => new AnchorRegistry( $this->history(), $this->prices(), $this->fields(), $this->settings(), $this->clock )
		);
	}

	public function fields(): FieldMapping {
		return $this->service( FieldMapping::class, fn() => new FieldMapping( $this->settings() ) );
	}

	public function outlets(): Outlets {
		return $this->service( Outlets::class, fn() => new Outlets( $this->settings() ) );
	}

	public function builder(): PriceListBuilder {
		return $this->service(
			PriceListBuilder::class,
			fn() => new PriceListBuilder( $this->settings(), $this->prices(), $this->history(), $this->anchors(), $this->fields() )
		);
	}

	public function log(): PublicationLog {
		return $this->service( PublicationLog::class, fn() => new PublicationLog() );
	}

	public function publisher(): Publisher {
		return $this->service(
			Publisher::class,
			fn() => new Publisher( $this->builder(), $this->log(), $this->settings() )
		);
	}

	public function scheduler(): Scheduler {
		return $this->service(
			Scheduler::class,
			fn() => new Scheduler( $this->publisher(), $this->log(), $this->outlets(), $this->history(), $this->settings(), $this->clock )
		);
	}

	public function archive(): Archive {
		return $this->service( Archive::class, fn() => new Archive( $this->log(), $this->outlets(), $this->settings() ) );
	}

	public function importer(): CsvImporter {
		return $this->service(
			CsvImporter::class,
			fn() => new CsvImporter( $this->anchors(), $this->history(), $this->settings() )
		);
	}

	public function status(): Status {
		return $this->service( Status::class, fn() => new Status( $this->log(), $this->scheduler(), $this->clock ) );
	}

	public function alerts(): Alerts {
		return $this->service( Alerts::class, fn() => new Alerts( $this->settings(), $this->outlets(), $this->status(), $this->clock ) );
	}

	public function self_check(): SelfCheck {
		return $this->service( SelfCheck::class, fn() => new SelfCheck( $this->archive(), $this->log(), $this->outlets(), $this->clock ) );
	}

	public function price_display(): PriceDisplay {
		return $this->service(
			PriceDisplay::class,
			fn() => new PriceDisplay( $this->settings(), $this->prices(), $this->history(), $this->anchors(), $this->clock )
		);
	}

	/**
	 * @template T of object
	 * @param class-string<T> $id
	 * @param callable(): T   $factory
	 * @return T
	 */
	private function service( string $id, callable $factory ): object {
		if ( ! isset( $this->services[ $id ] ) ) {
			$this->services[ $id ] = $factory();
		}
		/** @var T */
		return $this->services[ $id ];
	}
}
