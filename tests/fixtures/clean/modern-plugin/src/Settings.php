<?php
declare(strict_types=1);

namespace Acme\Modern;

enum Status: string {
	case Active = 'active';
	case Paused = 'paused';

	public function label(): string {
		return match ( $this ) {
			self::Active => 'Running',
			self::Paused => 'Paused',
		};
	}
}

#[\AllowDynamicProperties]
final class Settings {
	private static ?self $instance = null;

	public function __construct(
		private readonly Status $status,
		private int|string $limit = 10,
		private ?\Closure $formatter = null,
	) {}

	public static function boot( Status $status ): self {
		return self::$instance ??= new self( $status );
	}

	public function summary(): string {
		$format = $this->formatter ?? strtoupper( ... );
		return $format( $this->status->label() . ' / ' . $this->limit );
	}

	public function owner_name( ?\WP_User $user = null ): ?string {
		return $user?->display_name;
	}

	public function fail( string $reason ): never {
		throw new \RuntimeException( $reason );
	}
}
