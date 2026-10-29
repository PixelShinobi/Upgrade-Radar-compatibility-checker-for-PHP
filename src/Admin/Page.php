<?php
/**
 * The Tools → Upgrade Radar screen.
 *
 * @package UpgradeRadar
 */

namespace UpgradeRadar\Admin;

use UpgradeRadar\Jobs\Runner;
use UpgradeRadar\Plugin;
use UpgradeRadar\Scanner\FileReport;
use UpgradeRadar\Scanner\Scanner;
use UpgradeRadar\Scanner\TargetVersion;

/**
 * Lets the site owner pick a PHP version, start a scan, follow its
 * progress, and read the verdicts.
 */
final class Page {

	public const SLUG = 'upgrade-radar';

	/**
	 * Issues listed per item. The CSV export has all of them.
	 */
	private const MAX_ISSUES_SHOWN = 200;

	/**
	 * The screen's hook suffix.
	 *
	 * @var string
	 */
	private static $hook = '';

	/**
	 * Adds the screen under Tools.
	 */
	public static function register(): void {
		$hook = add_management_page( Plugin::NAME, Plugin::MENU_TITLE, 'manage_options', self::SLUG, array( self::class, 'render' ) );
		if ( false !== $hook ) {
			self::$hook = $hook;
			add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		}
	}

	/**
	 * The screen's URL.
	 *
	 * @param array<string, string> $args Extra query arguments.
	 * @return string
	 */
	public static function url( array $args = array() ): string {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'tools.php' ) );
	}

	/**
	 * Loads the screen's script and styles.
	 *
	 * @param string $hook The current screen's hook suffix.
	 */
	public static function enqueue( string $hook ): void {
		if ( self::$hook !== $hook ) {
			return;
		}

		$assets = plugins_url( 'assets/', Plugin::file() );
		wp_enqueue_style( 'upgrade-radar-admin', $assets . 'admin.css', array(), Plugin::VERSION );
		wp_enqueue_script( 'upgrade-radar-admin', $assets . 'admin.js', array(), Plugin::VERSION, true );
		wp_add_inline_script(
			'upgrade-radar-admin',
			'window.upgradeRadar = ' . wp_json_encode(
				array(
					'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
					'nonce'            => wp_create_nonce( 'upgrade_radar_step' ),
					'connectionFailed' => __( "Can't reach the site right now. Trying again…", 'upgrade-radar' ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Renders the screen.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to access this page.', 'upgrade-radar' ) );
		}

		$scan    = Runner::scan();
		$status  = null === $scan ? 'idle' : (string) $scan['status'];
		$missing = self::missing_requirements();
		?>
		<div class="wrap upgrade-radar">
			<h1><?php echo esc_html( Plugin::NAME ); ?></h1>
			<p>
				<?php
				printf(
					/* translators: %s: PHP version, such as 8.2.12. */
					esc_html__( 'Find out whether your active plugins and theme are ready for a newer PHP version before you switch. This site runs PHP %s.', 'upgrade-radar' ),
					esc_html( PHP_VERSION )
				);
				?>
			</p>
			<div class="notice notice-info inline">
				<p>
					<?php
					printf(
						/* translators: %s: PHPCompatibility version, such as 10.0.0-alpha2. */
						esc_html__( "Results may include false alarms: the checks come from PHPCompatibility %s, an early release. Errors are code that breaks on the chosen PHP version. Warnings are deprecated code that still works, so they don't affect the verdict.", 'upgrade-radar' ),
						esc_html( Scanner::RULES['phpcompatibility/php-compatibility'] )
					);
					?>
				</p>
			</div>
			<?php
			foreach ( $missing as $problem ) {
				printf( '<div class="notice notice-error inline"><p>%s</p></div>', esc_html( $problem ) );
			}

			if ( Runner::RUNNING === $status || Runner::PAUSED === $status ) {
				self::render_progress( $scan );
			} elseif ( array() === $missing ) {
				self::render_form( null === $scan ? self::default_target() : (string) $scan['target'] );
			}

			if ( Runner::DONE === $status ) {
				self::render_results( $scan );
			}
			?>
		</div>
		<?php
	}

	/**
	 * Renders the form that starts a scan.
	 *
	 * @param string $selected The target version to preselect.
	 */
	private static function render_form( string $selected ): void {
		$current = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
		?>
		<form class="upgrade-radar-start" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="upgrade_radar_start">
			<?php wp_nonce_field( 'upgrade_radar_start' ); ?>
			<label for="upgrade-radar-target"><?php esc_html_e( 'Check for PHP', 'upgrade-radar' ); ?></label>
			<select id="upgrade-radar-target" name="target">
				<?php foreach ( TargetVersion::SUPPORTED as $version ) : ?>
					<option value="<?php echo esc_attr( $version ); ?>" <?php selected( $version, $selected ); ?>>
						<?php
						echo esc_html(
							$version === $current
								/* translators: %s: PHP version, such as 8.3. */
								? sprintf( __( '%s (this site now)', 'upgrade-radar' ), $version )
								: $version
						);
						?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( __( 'Scan now', 'upgrade-radar' ), 'primary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Renders a running or paused scan.
	 *
	 * @param array<string, mixed> $scan The stored scan.
	 */
	private static function render_progress( array $scan ): void {
		$summary = Progress::summary( $scan, false );
		$paused  = Runner::PAUSED === $scan['status'];
		?>
		<div id="upgrade-radar-progress" class="upgrade-radar-progress" data-status="<?php echo esc_attr( (string) $scan['status'] ); ?>">
			<h2>
				<?php
				/* translators: %s: PHP version, such as 8.3. */
				printf( esc_html( $paused ? __( 'Scan for PHP %s paused', 'upgrade-radar' ) : __( 'Scanning for PHP %s', 'upgrade-radar' ) ), esc_html( (string) $scan['target'] ) );
				?>
			</h2>
			<progress max="100" value="<?php echo esc_attr( (string) $summary['percent'] ); ?>"><?php echo esc_html( $summary['percent'] . '%' ); ?></progress>
			<p class="upgrade-radar-progress-text" aria-live="polite"><?php echo esc_html( $summary['text'] ); ?></p>
			<div class="notice notice-warning inline upgrade-radar-problem" <?php echo '' === $summary['problem'] ? 'hidden' : ''; ?>>
				<p><?php echo esc_html( $summary['problem'] ); ?></p>
			</div>
			<?php if ( ! $paused ) : ?>
				<p class="description">
					<?php
					if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
						esc_html_e( 'Keep this page open until the scan finishes. WP-Cron is turned off on this site, so the scan only carries on in the background if your host runs WP-Cron on a schedule.', 'upgrade-radar' );
					} else {
						esc_html_e( 'You can leave this page. The scan carries on in the background whenever your site gets visits, and picks up where it left off.', 'upgrade-radar' );
					}
					?>
				</p>
			<?php endif; ?>
			<div class="upgrade-radar-actions">
				<?php
				if ( $paused ) {
					self::render_button( 'upgrade_radar_resume', __( 'Resume', 'upgrade-radar' ), 'primary' );
				}
				self::render_button( 'upgrade_radar_cancel', __( 'Cancel scan', 'upgrade-radar' ), 'secondary' );
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Renders a one-button form for an admin-post action.
	 *
	 * @param string $action The action, which is also the nonce action.
	 * @param string $label  Button text.
	 * @param string $type   Button type: "primary" or "secondary".
	 */
	private static function render_button( string $action, string $label, string $type ): void {
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<?php
			wp_nonce_field( $action );
			submit_button( $label, $type, 'submit', false );
			?>
		</form>
		<?php
	}

	/**
	 * Renders the verdicts of a finished scan.
	 *
	 * @param array<string, mixed> $scan The stored scan.
	 */
	private static function render_results( array $scan ): void {
		$rows   = Results::rows( $scan );
		$target = (string) $scan['target'];
		$files  = 0;
		foreach ( $rows as $row ) {
			$files += $row['result']->files_found();
		}
		?>
		<h2>
			<?php
			/* translators: %s: PHP version, such as 8.3. */
			printf( esc_html__( 'Results for PHP %s', 'upgrade-radar' ), esc_html( $target ) );
			?>
		</h2>
		<p>
			<?php
			printf(
				/* translators: 1: date and time, 2: number of plugins and themes, such as "3 plugins and themes", 3: number of files, such as "120 files", 4: duration, such as "3 mins". */
				esc_html__( 'Scanned on %1$s: %2$s, %3$s, in %4$s.', 'upgrade-radar' ),
				esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $scan['finished'] ) ),
				/* translators: %s: number of plugins and themes. */
				esc_html( sprintf( _n( '%s plugin or theme', '%s plugins and themes', count( $rows ), 'upgrade-radar' ), number_format_i18n( count( $rows ) ) ) ),
				/* translators: %s: number of files. */
				esc_html( sprintf( _n( '%s file', '%s files', $files, 'upgrade-radar' ), number_format_i18n( $files ) ) ),
				esc_html( human_time_diff( 0, max( 1, (int) round( (float) $scan['seconds'] ) ) ) )
			);
			?>
		</p>
		<ul class="upgrade-radar-summary">
			<?php foreach ( Results::counts( $rows ) as $verdict => $count ) : ?>
				<li><span class="upgrade-radar-verdict upgrade-radar-verdict--<?php echo esc_attr( $verdict ); ?>"><?php echo esc_html( Results::label( $verdict ) ); ?></span> <?php echo esc_html( number_format_i18n( $count ) ); ?></li>
			<?php endforeach; ?>
		</ul>
		<p>
			<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=upgrade_radar_csv' ), 'upgrade_radar_csv' ) ); ?>"><?php esc_html_e( 'Download CSV', 'upgrade-radar' ); ?></a>
		</p>
		<table class="widefat upgrade-radar-results">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Plugin or theme', 'upgrade-radar' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Verdict', 'upgrade-radar' ); ?></th>
					<th scope="col" class="num"><?php esc_html_e( 'Errors', 'upgrade-radar' ); ?></th>
					<th scope="col" class="num"><?php esc_html_e( 'Warnings', 'upgrade-radar' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $rows as $row ) : ?>
					<?php $result = $row['result']; ?>
					<tr>
						<td>
							<strong><?php echo esc_html( $row['name'] ); ?></strong><br>
							<span class="description">
								<?php
								echo esc_html( Results::type_label( $row['type'] ) );
								if ( '' !== $row['version'] ) {
									/* translators: %s: version number. */
									echo ' · ' . esc_html( sprintf( __( 'version %s', 'upgrade-radar' ), $row['version'] ) );
								}
								?>
							</span>
						</td>
						<td>
							<span class="upgrade-radar-verdict upgrade-radar-verdict--<?php echo esc_attr( $row['verdict'] ); ?>"><?php echo esc_html( Results::label( $row['verdict'] ) ); ?></span>
							<p><?php echo esc_html( Results::explanation( $row, $target ) ); ?></p>
						</td>
						<td class="num"><?php echo esc_html( number_format_i18n( $result->error_count() ) ); ?></td>
						<td class="num"><?php echo esc_html( number_format_i18n( $result->warning_count() ) ); ?></td>
					</tr>
					<?php if ( array() !== $result->reports() ) : ?>
						<tr class="upgrade-radar-details-row">
							<td colspan="4"><?php self::render_details( $result->reports() ); ?></td>
						</tr>
					<?php endif; ?>
				<?php endforeach; ?>
			</tbody>
		</table>
		<p class="description"><?php esc_html_e( "Upgrade Radar doesn't list itself: it runs on PHP 7.4 to 8.5.", 'upgrade-radar' ); ?></p>
		<?php
	}

	/**
	 * Renders the problems found in an item, file by file.
	 *
	 * @param FileReport[] $reports Reports for files that weren't clean.
	 */
	private static function render_details( array $reports ): void {
		$issues    = 0;
		$unchecked = 0;
		foreach ( $reports as $report ) {
			$issues += count( $report->issues() );
			if ( FileReport::SCANNED !== $report->status() ) {
				++$unchecked;
			}
		}

		$summary = array();
		if ( 0 < $issues ) {
			/* translators: %s: number of problems. */
			$summary[] = sprintf( _n( 'Show %s problem', 'Show %s problems', $issues, 'upgrade-radar' ), number_format_i18n( $issues ) );
		}
		if ( 0 < $unchecked ) {
			/* translators: %s: number of files. */
			$summary[] = sprintf( _n( '%s file not checked', '%s files not checked', $unchecked, 'upgrade-radar' ), number_format_i18n( $unchecked ) );
		}

		$shown = 0;
		?>
		<details class="upgrade-radar-details">
			<summary><?php echo esc_html( implode( ', ', $summary ) ); ?></summary>
			<?php foreach ( $reports as $report ) : ?>
				<?php
				if ( $shown >= self::MAX_ISSUES_SHOWN ) {
					break;
				}
				?>
				<div class="upgrade-radar-file">
					<h3><code><?php echo esc_html( $report->path() ); ?></code></h3>
					<?php if ( FileReport::SCANNED !== $report->status() ) : ?>
						<p class="upgrade-radar-unchecked"><?php echo esc_html( __( 'Not checked:', 'upgrade-radar' ) . ' ' . $report->detail() ); ?></p>
					<?php endif; ?>
					<ul>
						<?php foreach ( $report->issues() as $issue ) : ?>
							<?php
							if ( $shown >= self::MAX_ISSUES_SHOWN ) {
								break;
							}
							++$shown;
							?>
							<li>
								<code><?php echo esc_html( $issue->line() . ':' . $issue->column() ); ?></code>
								<span class="upgrade-radar-type upgrade-radar-type--<?php echo esc_attr( $issue->type() ); ?>"><?php echo esc_html( $issue->is_error() ? __( 'Error', 'upgrade-radar' ) : __( 'Warning', 'upgrade-radar' ) ); ?></span>
								<?php echo esc_html( $issue->message() ); ?>
								<br><small><?php echo esc_html( $issue->source() ); ?></small>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endforeach; ?>
			<?php if ( $issues > $shown ) : ?>
				<p>
					<?php
					/* translators: %s: number of problems. */
					printf( esc_html( _n( '%s more problem is in the CSV download.', '%s more problems are in the CSV download.', $issues - $shown, 'upgrade-radar' ) ), esc_html( number_format_i18n( $issues - $shown ) ) );
					?>
				</p>
			<?php endif; ?>
		</details>
		<?php
	}

	/**
	 * Problems that stop scans from running at all.
	 *
	 * @return string[]
	 */
	private static function missing_requirements(): array {
		$problems   = array();
		$extensions = Scanner::missing_extensions();
		if ( array() !== $extensions ) {
			/* translators: %s: list of PHP extension names. */
			$problems[] = sprintf( __( 'Scans need PHP extensions this server is missing: %s. Ask your host to enable them.', 'upgrade-radar' ), implode( ', ', $extensions ) );
		}

		if ( ! is_readable( dirname( Plugin::file() ) . '/vendor/squizlabs/php_codesniffer/autoload.php' ) ) {
			$problems[] = __( "Upgrade Radar's files are incomplete, so it can't scan. Reinstall the plugin.", 'upgrade-radar' );
		}

		return $problems;
	}

	/**
	 * The next supported PHP version above this site's, or the newest.
	 *
	 * @return string
	 */
	private static function default_target(): string {
		$current = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
		foreach ( TargetVersion::SUPPORTED as $version ) {
			if ( version_compare( $version, $current, '>' ) ) {
				return $version;
			}
		}

		return TargetVersion::SUPPORTED[ count( TargetVersion::SUPPORTED ) - 1 ];
	}
}
