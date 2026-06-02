<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Factory_Single_Adapter {

	private array $execution_results = [];

	public function register( array $blueprint ): void {
		add_filter( 'template_include', [ $this, 'override_single_template' ], 99 );
	}

	public function apply( array $blueprint ): void {
		$this->execution_results = [];

		foreach ( $blueprint['single'] ?? [] as $post_type => $config ) {
			$exists = post_type_exists( $post_type );

			$this->execution_results[] = $this->execution_item(
				$exists ? 'ok' : 'error',
				$post_type,
				$exists
					? "Single template registered for: {$post_type}"
					: "Single template post type missing: {$post_type}"
			);
		}
	}

	public function get_execution_results(): array {
		return $this->execution_results;
	}

	public function plan( array $blueprint ): array {
		$plan = [];

		foreach ( $blueprint['single'] ?? [] as $post_type => $config ) {
			$exists = post_type_exists( $post_type );

			$plan[] = [
				'action'  => $exists ? 'skip' : 'error',
				'type'    => 'single',
				'entity'  => $post_type,
				'message' => $exists
					? "Single template registered for: {$post_type}"
					: "Single template post type missing: {$post_type}",
				'diff'    => [],
			];
		}

		return $plan;
	}

	public function validate( array $blueprint ): array {
		$checks = [];

		foreach ( $blueprint['single'] ?? [] as $post_type => $config ) {
			$checks[] = [
				'status'  => post_type_exists( $post_type ) ? 'ok' : 'error',
				'message' => post_type_exists( $post_type )
					? "Single template registered for: {$post_type}"
					: "Single template post type missing: {$post_type}",
			];
		}

		return $checks;
	}

	private function execution_item(
		string $status,
		string $entity,
		string $message
	): array {
		return [
			'status'  => $status,
			'action'  => 'skip',
			'type'    => 'single',
			'entity'  => $entity,
			'message' => $message,
			'details' => [],
		];
	}

	public function override_single_template( string $template ): string {
		if ( ! is_singular() ) {
			return $template;
		}

		$post_type = get_post_type();

		$blueprint = factory_get_blueprint();

		if ( empty( $blueprint['single'][ $post_type ] ) ) {
			return $template;
		}

		$factory_template = __DIR__ . '/../templates/single-factory.php';

		return file_exists( $factory_template ) ? $factory_template : $template;
	}

	public function render_current(): string {
		if ( ! is_singular() ) {
			return '';
		}

		$post_type = get_post_type();
		$blueprint = factory_get_blueprint();
		$config    = $blueprint['single'][ $post_type ] ?? [];

		if ( empty( $config ) ) {
			return '';
		}

		if ( 'property' === $post_type ) {
			return $this->render_property_single( $config, $blueprint );
		}

		$layout = $config['layout'] ?? [];

			if ( empty( $layout ) && ! empty( $config['fields'] ) ) {
				$layout = array_map(
					function ( $field ) {
						return $field === 'content'
							? [ 'type' => 'content' ]
							: [
								'type'  => 'meta',
								'key'   => $field,
								'label' => ucfirst( $field ),
							];
					},
					$config['fields']
				);
			}

		ob_start();
		?>

		<main class="factory-single-wrap" style="max-width: 1120px; margin: 80px auto; padding: 0 24px;">
			<article <?php post_class( 'factory-single' ); ?>>

				<header style="margin-bottom: 48px;">
					<?php if ( has_post_thumbnail() ) : ?>
						<div style="margin-bottom: 32px; overflow: hidden; border-radius: 24px;">
							<?php
							echo get_the_post_thumbnail(
								get_the_ID(),
								'large',
								[
									'style' => 'display: block; width: 100%; height: min(56vw, 520px); object-fit: cover;',
								]
							);
							?>
						</div>
					<?php endif; ?>

					<?php
					$address = get_post_meta( get_the_ID(), 'address', true );

					if ( $address ) :
						?>
						<p style="margin-bottom: 12px; color: #666;">
							<?php echo esc_html( $address ); ?>
						</p>
					<?php endif; ?>

					<h1 style="font-size: clamp(44px, 7vw, 82px); line-height: 1.05; margin: 0;">
						<?php the_title(); ?>
					</h1>
				</header>

				<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-bottom: 48px;">

					<?php foreach ( $layout as $item ) : ?>
						<?php
						if ( ( $item['type'] ?? '' ) !== 'meta' ) {
							continue;
						}

						$key = $item['key'] ?? '';

						if ( ! $key ) {
							continue;
						}

						$value = get_post_meta( get_the_ID(), $key, true );

						if ( $value === '' ) {
							continue;
						}

						$label  = $item['label'] ?? ucfirst( $key );
						$format = $item['format'] ?? '';
						?>

						<div style="border: 1px solid #e5e5e5; border-radius: 18px; padding: 24px;">
							<div style="color: #777; margin-bottom: 8px;">
								<?php echo esc_html( $label ); ?>
							</div>

							<strong style="font-size: 28px;">
								<?php
								if ( $format === 'currency' ) {
									echo '$' . esc_html( number_format( (float) $value ) );
								} else {
									echo esc_html( $value );
								}
								?>
							</strong>
						</div>

					<?php endforeach; ?>

				</div>

				<div style="font-size: 20px; line-height: 1.7;">
					<?php the_content(); ?>
				</div>

			</article>
		</main>

		<?php
		return ob_get_clean();
	}

	private function render_property_single( array $config, array $blueprint ): string {
		$post_id       = get_the_ID();
		$style_tokens  = $this->get_site_style_tokens( $blueprint );
		$primary       = $style_tokens['primary'];
		$accent        = $style_tokens['accent'];
		$background    = $style_tokens['background'];
		$surface       = $style_tokens['surface'];
		$text          = $style_tokens['text'];
		$muted         = $style_tokens['muted'];
		$border        = $style_tokens['border'];
		$title         = get_the_title( $post_id );
		$price         = get_post_meta( $post_id, 'price', true );
		$address       = get_post_meta( $post_id, 'address', true );
		$bedrooms      = get_post_meta( $post_id, 'bedrooms', true );
		$bathrooms     = get_post_meta( $post_id, 'bathrooms', true );
		$property_size = get_post_meta( $post_id, 'property_size', true );
		$district      = get_post_meta( $post_id, 'district', true );
		$purpose       = $this->get_property_meta_or_term( $post_id, 'purpose' );
		$property_type = $this->get_property_meta_or_term( $post_id, 'property_type' );
		$content       = apply_filters( 'the_content', get_the_content() );
		$contact_url   = add_query_arg(
			[ 'factory_property' => sanitize_title( get_post_field( 'post_name', $post_id ) ) ],
			home_url( '/contact/' )
		);
		$gallery_images = $this->get_property_gallery_images( $post_id );
		$stats         = [];
		$details       = [];

		if ( is_numeric( $bedrooms ) && (float) $bedrooms > 0 ) {
			$stats[]   = number_format( (float) $bedrooms ) . ' bed';
			$details[] = [ 'Bedrooms', number_format( (float) $bedrooms ) ];
		}

		if ( '' !== $bathrooms && is_numeric( $bathrooms ) && (float) $bathrooms > 0 ) {
			$stats[]   = number_format( (float) $bathrooms ) . ' bath';
			$details[] = [ 'Bathrooms', number_format( (float) $bathrooms ) ];
		}

		if ( '' !== $property_size && is_numeric( $property_size ) ) {
			$stats[]   = number_format( (float) $property_size ) . ' sq m';
			$details[] = [ 'Size', number_format( (float) $property_size ) . ' sq m' ];
		}

			if ( '' !== $price ) {
			$details[] = [ 'Price', $this->format_property_price( $price ) ];
		}

			if ( '' !== $purpose ) {
				$details[] = [ 'Purpose', $purpose ];
			}

			if ( '' !== $property_type ) {
				$details[] = [ 'Property type', $property_type ];
			}

			if ( '' !== $district ) {
				$details[] = [ 'District', $district ];
			}

		ob_start();
		?>

		<main class="factory-single-wrap factory-property-single-wrap" style="max-width: 1180px; margin: 64px auto 80px; padding: 0 24px;">
			<article <?php post_class( 'factory-single factory-property-single', $post_id ); ?>>
				<header style="margin-bottom: 34px;">
					<?php if ( ! empty( $gallery_images ) ) : ?>
						<div class="factory-property-gallery" style="display: grid; grid-template-columns: <?php echo count( $gallery_images ) > 1 ? 'minmax(0, 1.35fr) minmax(min(100%, 220px), 0.65fr)' : '1fr'; ?>; gap: 12px; margin-bottom: 28px; border-radius: 26px; overflow: hidden; background: <?php echo esc_attr( $background ); ?>; box-shadow: 0 20px 48px rgba(15, 118, 110, 0.12);">
							<img src="<?php echo esc_url( $gallery_images[0]['url'] ); ?>" alt="<?php echo esc_attr( $gallery_images[0]['alt'] ); ?>" style="display: block; width: 100%; height: min(54vw, 500px); min-height: 320px; object-fit: cover;">

							<?php if ( count( $gallery_images ) > 1 ) : ?>
								<div style="display: grid; gap: 12px;">
									<?php foreach ( array_slice( $gallery_images, 1, 3 ) as $image ) : ?>
										<img src="<?php echo esc_url( $image['url'] ); ?>" alt="<?php echo esc_attr( $image['alt'] ); ?>" style="display: block; width: 100%; height: 100%; min-height: 154px; object-fit: cover;">
									<?php endforeach; ?>
								</div>
							<?php endif; ?>
						</div>
					<?php endif; ?>

					<div style="display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 18px;">
						<?php if ( '' !== $purpose ) : ?>
							<span style="display: inline-flex; align-items: center; border-radius: 999px; background: <?php echo esc_attr( $primary ); ?>; color: #fff; padding: 8px 12px; font-size: 13px; font-weight: 800; letter-spacing: 0;">
								<?php echo esc_html( $purpose ); ?>
							</span>
						<?php endif; ?>

						<?php if ( '' !== $property_type ) : ?>
							<span style="display: inline-flex; align-items: center; border-radius: 999px; background: <?php echo esc_attr( $background ); ?>; color: <?php echo esc_attr( $primary ); ?>; padding: 8px 12px; font-size: 13px; font-weight: 800; letter-spacing: 0;">
								<?php echo esc_html( $property_type ); ?>
							</span>
						<?php endif; ?>
					</div>

					<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr)); gap: 28px; align-items: start;">
						<div>
							<h1 style="font-size: clamp(32px, 3.5vw, 48px); line-height: 1.08; margin: 0 0 16px; color: <?php echo esc_attr( $style_tokens['heading'] ); ?>;">
								<?php echo esc_html( $title ); ?>
							</h1>

							<?php if ( '' !== $address ) : ?>
								<div style="color: <?php echo esc_attr( $muted ); ?>; font-size: 16px; line-height: 1.55; margin-bottom: 8px;">
									<?php echo esc_html( $address ); ?>
								</div>
							<?php endif; ?>

							<?php if ( '' !== $district ) : ?>
								<div style="color: <?php echo esc_attr( $primary ); ?>; font-size: 14px; line-height: 1.45; font-weight: 800;">
									<?php echo esc_html( $district ); ?>
								</div>
							<?php endif; ?>
						</div>

						<?php if ( '' !== $price ) : ?>
						<div style="border: 1px solid <?php echo esc_attr( $border ); ?>; border-radius: 18px; background: <?php echo esc_attr( $surface ); ?>; padding: 16px 18px; box-shadow: 0 12px 28px rgba(15, 118, 110, 0.08); max-width: 260px;">
								<div style="color: <?php echo esc_attr( $muted ); ?>; font-size: 13px; font-weight: 800; margin-bottom: 8px; text-transform: uppercase;">
									Price
								</div>
								<div style="color: <?php echo esc_attr( $primary ); ?>; font-size: 26px; line-height: 1.1; font-weight: 900;">
									<?php echo esc_html( $this->format_property_price( $price ) ); ?>
								</div>
							</div>
						<?php endif; ?>
					</div>
				</header>

				<?php if ( ! empty( $stats ) ) : ?>
					<div style="display: flex; flex-wrap: wrap; gap: 10px; margin-bottom: 34px;">
						<?php foreach ( $stats as $stat ) : ?>
							<span style="display: inline-flex; align-items: center; border-radius: 999px; background: <?php echo esc_attr( $background ); ?>; color: <?php echo esc_attr( $text ); ?>; padding: 10px 14px; font-size: 14px; font-weight: 800;">
								<?php echo esc_html( $stat ); ?>
							</span>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>

				<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 280px), 1fr)); gap: 32px; align-items: start;">
					<section style="border-top: 1px solid <?php echo esc_attr( $border ); ?>; padding-top: 28px;">
						<h2 style="font-size: 24px; line-height: 1.2; margin: 0 0 14px; color: <?php echo esc_attr( $style_tokens['heading'] ); ?>;">
							Property description
						</h2>

						<div style="color: <?php echo esc_attr( $text ); ?>; font-size: 18px; line-height: 1.75;">
							<?php echo wp_kses_post( $content ); ?>
						</div>
						<?php if ( ! empty( $details ) ) : ?>
					<section style="margin-top: 30px;">
						<h2 style="font-size: 22px; line-height: 1.2; margin: 0 0 14px; color: <?php echo esc_attr( $style_tokens['heading'] ); ?>;">
							Property details
						</h2>
						<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: 12px;">
							<?php foreach ( $details as $detail ) : ?>
								<div style="border: 1px solid <?php echo esc_attr( $border ); ?>; border-radius: 16px; background: <?php echo esc_attr( $surface ); ?>; padding: 14px 16px;">
									<div style="color: <?php echo esc_attr( $muted ); ?>; font-size: 12px; font-weight: 800; margin-bottom: 6px; text-transform: uppercase;">
										<?php echo esc_html( $detail[0] ); ?>
									</div>
									<div style="color: <?php echo esc_attr( $text ); ?>; font-size: 16px; font-weight: 800; line-height: 1.3;">
										<?php echo esc_html( $detail[1] ); ?>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
					</section>
				<?php endif; ?>

				<?php if ( '' !== $address || '' !== $district ) : ?>
					<section style="margin-top: 30px; border: 1px solid <?php echo esc_attr( $border ); ?>; border-radius: 18px; background: <?php echo esc_attr( $background ); ?>; padding: 18px;">
						<h2 style="font-size: 20px; line-height: 1.2; margin: 0 0 10px; color: <?php echo esc_attr( $style_tokens['heading'] ); ?>;">
							Location
						</h2>
						<?php if ( '' !== $address ) : ?>
							<div style="color: <?php echo esc_attr( $text ); ?>; font-size: 15px; line-height: 1.55; margin-bottom: 6px;">
								<?php echo esc_html( $address ); ?>
							</div>
						<?php endif; ?>
						<?php if ( '' !== $district ) : ?>
							<div style="color: <?php echo esc_attr( $primary ); ?>; font-size: 14px; line-height: 1.45; font-weight: 800;">
								<?php echo esc_html( $district ); ?>
							</div>
						<?php endif; ?>
					</section>
				<?php endif; ?>
					</section>

					<aside id="factory-property-contact" style="border: 1px solid <?php echo esc_attr( $border ); ?>; border-radius: 20px; background: <?php echo esc_attr( $surface ); ?>; padding: 24px; box-shadow: 0 16px 38px rgba(15, 118, 110, 0.1);">
						<h2 style="font-size: 22px; line-height: 1.25; margin: 0 0 10px; color: <?php echo esc_attr( $style_tokens['heading'] ); ?>;">
							Interested in this property?
						</h2>

						<p style="color: <?php echo esc_attr( $muted ); ?>; font-size: 15px; line-height: 1.6; margin: 0 0 18px;">
							Contact the agency to schedule a viewing or request more details.
						</p>

						<a href="<?php echo esc_url( $contact_url ); ?>" style="display: inline-flex; align-items: center; justify-content: center; border-radius: 999px; background: <?php echo esc_attr( $style_tokens['button'] ?: $accent ); ?>; color: <?php echo esc_attr( $style_tokens['button_text'] ); ?>; padding: 11px 16px; font-size: 14px; font-weight: 900; text-decoration: none;">
							Contact agency
						</a>
					</aside>
				</div>
			</article>
		</main>

		<?php
		return ob_get_clean();
	}

	private function get_site_style_tokens( array $blueprint ): array {
		$style = $blueprint['site']['style'] ?? [];

		return [
			'tone'           => sanitize_key( $style['tone'] ?? 'premium' ),
			'primary_preset' => sanitize_key( $style['primary_preset'] ?? 'turquoise' ),
			'primary'        => $this->sanitize_color_token( $style['primary'] ?? '', '#0f766e' ),
			'accent'         => $this->sanitize_color_token( $style['accent'] ?? '', '#14b8a6' ),
			'background'     => $this->sanitize_color_token( $style['background'] ?? '', '#ecfeff' ),
			'surface'        => $this->sanitize_color_token( $style['surface'] ?? '', '#ffffff' ),
			'text'           => $this->sanitize_color_token( $style['text'] ?? '', '#10201d' ),
			'muted'          => $this->sanitize_color_token( $style['muted'] ?? '', '#52635f' ),
			'border'         => $this->sanitize_color_token( $style['border'] ?? '', '#d7eee9' ),
			'button'         => $this->sanitize_color_token( $style['button'] ?? '', $style['accent'] ?? '#14b8a6' ),
			'button_text'    => $this->sanitize_color_token( $style['button_text'] ?? '', '#ffffff' ),
			'link'           => $this->sanitize_color_token( $style['link'] ?? '', $style['primary'] ?? '#0f766e' ),
			'link_hover'     => $this->sanitize_color_token( $style['link_hover'] ?? '', '#0d9488' ),
			'heading'        => $this->sanitize_color_token( $style['heading'] ?? '', '#10201d' ),
		];
	}

	private function sanitize_color_token( $value, string $fallback ): string {
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return $fallback;
		}

		if ( function_exists( 'sanitize_hex_color' ) ) {
			$sanitized = sanitize_hex_color( $value );

			return $sanitized ?: $fallback;
		}

		return preg_match( '/^#[0-9a-fA-F]{3}([0-9a-fA-F]{3})?$/', $value ) ? $value : $fallback;
	}

	private function get_property_meta_or_term( int $post_id, string $key ): string {
		$value = get_post_meta( $post_id, $key, true );

		if ( is_array( $value ) ) {
			$value = reset( $value );
		}

		if ( '' !== $value && null !== $value ) {
			return (string) $value;
		}

		$terms = wp_get_post_terms( $post_id, $key, [ 'fields' => 'names' ] );

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return '';
		}

		return (string) $terms[0];
	}

	private function get_property_gallery_images( int $post_id ): array {
		$images       = [];
		$featured_id  = get_post_thumbnail_id( $post_id );
		$featured_url = $featured_id ? wp_get_attachment_image_url( $featured_id, 'large' ) : '';

		if ( $featured_url ) {
			$images[] = [
				'id'  => (int) $featured_id,
				'url' => $featured_url,
				'alt' => get_post_meta( (int) $featured_id, '_wp_attachment_image_alt', true ) ?: get_the_title( $post_id ),
			];
		}

		$related_posts = get_posts( [
			'post_type'      => 'property',
			'post_status'    => 'publish',
			'posts_per_page' => 6,
			'post__not_in'   => [ $post_id ],
			'fields'         => 'ids',
			'orderby'        => 'date',
			'order'          => 'DESC',
		] );

		foreach ( $related_posts as $related_id ) {
			$thumbnail_id = get_post_thumbnail_id( (int) $related_id );

			if ( ! $thumbnail_id || (int) $thumbnail_id === (int) $featured_id ) {
				continue;
			}

			$url = wp_get_attachment_image_url( $thumbnail_id, 'medium_large' );

			if ( ! $url ) {
				continue;
			}

			$images[] = [
				'id'  => (int) $thumbnail_id,
				'url' => $url,
				'alt' => get_post_meta( (int) $thumbnail_id, '_wp_attachment_image_alt', true ) ?: get_the_title( (int) $related_id ),
			];

			if ( count( $images ) >= 4 ) {
				break;
			}
		}

		return $images;
	}

	private function format_property_price( $price ): string {
		if ( is_array( $price ) ) {
			$price = reset( $price );
		}

		if ( '' === $price || null === $price ) {
			return '';
		}

		if ( is_numeric( $price ) ) {
			return '$' . number_format( (float) $price );
		}

		return (string) $price;
	}
}

function factory_render_single_template(): void {
	$adapter = new Factory_Single_Adapter();

	echo $adapter->render_current();
}
