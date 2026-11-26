<?php
/**
 * On the site that received a push: deletes the media that push copied
 * here, once nothing else uses it. Used when a push is undone or a held
 * update is discarded.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\Transfer;

use RedC\Sceneshifter\SyncMap\MapEntry;
use RedC\Sceneshifter\SyncMap\MapStore;

final class CopiedMedia {

	public function __construct(
		private readonly MediaFiles $files,
		private readonly MapStore $map
	) {}

	/**
	 * @param string $sister     The site that pushed.
	 * @param string $transfer   The batch.
	 * @param int    $sourcePost The pushed post's ID on that site.
	 * @param int    $ignorePost The post being undone here: its own use doesn't count.
	 * @return list<int> The attachments deleted.
	 */
	public function remove( string $sister, string $transfer, int $sourcePost, int $ignorePost ): array {
		$copied = MediaReceiver::copied( $transfer, $sourcePost );
		if ( null === $copied ) {
			return array();
		}
		$removed = array();
		foreach ( $this->files->copiedBy( $copied ) as $id ) {
			if ( $this->files->inUse( $id, $ignorePost ) ) {
				continue;
			}
			try {
				$this->files->deleteAttachment( $id );
			} catch ( \Throwable $e ) {
				continue;
			}
			$this->map->delete( $sister, MapEntry::POST, $id );
			$removed[] = $id;
		}
		return $removed;
	}
}
