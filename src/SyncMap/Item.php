<?php
/**
 * One post or attachment as listed for matching: enough to recognise it on
 * another site, and to show it to the admin who reviews the match.
 *
 * @package RedC\Sceneshifter
 */

declare(strict_types=1);

namespace RedC\Sceneshifter\SyncMap;

final class Item {

	/**
	 * @param int         $id     Post ID on the site that listed it.
	 * @param string      $type   Post type.
	 * @param string      $key    Path for posts (page URI or slug), file path under uploads for attachments.
	 * @param int|null    $size   File size in bytes for attachments, when known.
	 * @param string|null $uuid   Sceneshifter ID, when it has one.
	 * @param string      $title  Title, shortened.
	 * @param string      $status Post status.
	 * @param bool        $dupe   Another post on the same site has the same UUID.
	 */
	public function __construct(
		public readonly int $id,
		public readonly string $type,
		public readonly string $key,
		public readonly ?int $size,
		public readonly ?string $uuid,
		public readonly string $title,
		public readonly string $status,
		public readonly bool $dupe = false
	) {}

	public function isAttachment(): bool {
		return 'attachment' === $this->type;
	}

	/**
	 * The UUID, unless another post shares it.
	 */
	public function usableUuid(): ?string {
		return $this->dupe ? null : $this->uuid;
	}

	/**
	 * @return array{id: int, type: string, key: string, size: int|null, uuid: string|null, title: string, status: string, dupe?: true}
	 */
	public function toArray(): array {
		$data = array(
			'id'     => $this->id,
			'type'   => $this->type,
			'key'    => $this->key,
			'size'   => $this->size,
			'uuid'   => $this->uuid,
			'title'  => $this->title,
			'status' => $this->status,
		);
		if ( $this->dupe ) {
			$data['dupe'] = true;
		}
		return $data;
	}

	/**
	 * @param mixed $data What another site sent, or what was cached from it.
	 */
	public static function fromArray( mixed $data ): ?self {
		if ( ! is_array( $data ) || ! is_int( $data['id'] ?? null ) || $data['id'] < 1 || ! is_string( $data['type'] ?? null ) || ! is_string( $data['key'] ?? null ) ) {
			return null;
		}
		$size = $data['size'] ?? null;
		$uuid = $data['uuid'] ?? null;
		return new self(
			$data['id'],
			substr( $data['type'], 0, 20 ),
			substr( $data['key'], 0, 400 ),
			is_int( $size ) && $size >= 0 ? $size : null,
			Uuid::isValid( $uuid ) ? $uuid : null,
			is_string( $data['title'] ?? null ) ? mb_substr( $data['title'], 0, 120 ) : '',
			is_string( $data['status'] ?? null ) ? substr( $data['status'], 0, 20 ) : '',
			true === ( $data['dupe'] ?? false )
		);
	}
}
