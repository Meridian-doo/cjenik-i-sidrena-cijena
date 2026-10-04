<?php
/**
 * @package Cjenik\Tests
 */

namespace Cjenik\Tests\Support;

use Cjenik\Admin\AdminPages;

/**
 * Renders a tab of WooCommerce → Price list as a shop manager sees it, and
 * reads it back like a browser would.
 */
trait AdminScreen {
	/**
	 * @param array<string, string> $query
	 */
	protected function admin_page( string $tab, array $query = array() ): \DOMXPath {
		$_GET = array(
			'page' => AdminPages::SLUG,
			'tab'  => $tab,
		) + $query;
		ob_start();
		AdminPages::render();
		$html     = (string) ob_get_clean();
		$document = new \DOMDocument();
		libxml_use_internal_errors( true );
		$document->loadHTML( '<?xml encoding="utf-8"?>' . $html );
		libxml_clear_errors();
		return new \DOMXPath( $document );
	}

	/**
	 * The body rows of the page's design-system table, as the text of each cell.
	 *
	 * @return list<list<string>>
	 */
	protected function table_rows( \DOMXPath $page ): array {
		$rows = array();
		foreach ( $page->query( '//table[contains(@class, "cjenik-table")]/tbody/tr' ) as $row ) {
			$cells = array();
			foreach ( $page->query( './td|./th', $row ) as $cell ) {
				$cells[] = $this->text( $cell );
			}
			$rows[] = $cells;
		}
		return $rows;
	}

	protected function text( ?\DOMNode $node ): string {
		return null === $node ? '' : trim( (string) preg_replace( '/[ \t\n\r]+/', ' ', $node->textContent ) );
	}
}
