<?php
/**
 * Страница настроек: Настройки → Clean Org Map.
 */

defined( 'ABSPATH' ) || exit;

add_action( 'admin_menu', function () {
	add_options_page( 'Clean Org Map', 'Clean Org Map', 'manage_options', 'clean-org-map', 'clean_org_map_admin_page' );
} );

add_filter( 'plugin_action_links_' . plugin_basename( CLEAN_ORG_MAP_FILE ), function ( $links ) {
	array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=clean-org-map' ) ) . '">Настройки</a>' );
	return $links;
} );

add_action( 'admin_post_clean_org_map_save', 'clean_org_map_admin_save' );

function clean_org_map_admin_save() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( 'Недостаточно прав.' );
	}
	check_admin_referer( 'clean_org_map_save' );

	$input    = wp_unslash( $_POST['com'] ?? [] );
	$old      = clean_org_map_settings();
	$defaults = clean_org_map_defaults();
	$notices  = [];

	$tiles = trim( (string) ( $input['tiles'] ?? '' ) );
	$style = trim( (string) ( $input['style'] ?? '' ) );
	$new   = [
		'engine'      => 'raster' === ( $input['engine'] ?? '' ) ? 'raster' : 'vector',
		'style'       => preg_match( '~^https://~', $style ) ? esc_url_raw( $style ) : $defaults['style'],
		'one_font'    => ! empty( $input['one_font'] ),
		'lang'        => preg_match( '/^[a-z]{2}$/', (string) ( $input['lang'] ?? '' ) ) ? $input['lang'] : '',
		'tiles'       => ( preg_match( '~^https://~', $tiles ) && false !== strpos( $tiles, '{z}' ) ) ? $tiles : $defaults['tiles'],
		'attribution' => wp_kses( (string) ( $input['attribution'] ?? '' ), [ 'a' => [ 'href' => [], 'target' => [], 'rel' => [] ] ] ) ?: $defaults['attribution'],
		'zoom'        => min( 19, max( 3, (int) ( $input['zoom'] ?? 16 ) ) ),
		'height'      => min( 2000, max( 150, (int) ( $input['height'] ?? 400 ) ) ),
		'locations'   => [],
	];

	foreach ( (array) ( $input['locations'] ?? [] ) as $row ) {
		$url = trim( (string) ( $row['url'] ?? '' ) );
		if ( ! empty( $row['delete'] ) || ( '' === $url && '' === trim( (string) ( $row['manual']['lat'] ?? '' ) ) ) ) {
			continue;
		}

		$org_id   = clean_org_map_parse_org_id( $url );
		$previous = $old['locations'][ $row['old_key'] ?? '' ] ?? [];

		$key = sanitize_title( (string) ( $row['key'] ?? '' ) );
		if ( '' === $key ) {
			$key = $org_id ? 'org-' . $org_id : 'point-' . ( count( $new['locations'] ) + 1 );
		}
		if ( isset( $new['locations'][ $key ] ) ) {
			$key .= '-' . ( count( $new['locations'] ) + 1 );
		}

		$acf_map = [];
		foreach ( clean_org_map_parse_acf_map( (string) ( $row['acf_map'] ?? '' ) ) as $rule ) {
			$acf_map[] = $rule['name'] . ( $rule['scope'] ? '@' . $rule['scope'] : '' ) . ' = ' . $rule['field'];
		}

		$loc = [
			'url'     => $url,
			'org_id'  => $org_id,
			'zoom'    => (int) ( $row['zoom'] ?? 0 ) ? min( 19, max( 3, (int) $row['zoom'] ) ) : 0,
			'acf_map' => implode( "\n", $acf_map ),
			'manual'  => [],
			'yandex' => ( $previous['org_id'] ?? '' ) === $org_id ? ( $previous['yandex'] ?? [] ) : [],
			'error'  => '',
		];
		foreach ( CLEAN_ORG_MAP_FIELDS as $field ) {
			$value = sanitize_text_field( (string) ( $row['manual'][ $field ] ?? '' ) );
			if ( in_array( $field, [ 'lat', 'lon' ], true ) && '' !== $value ) {
				$value = is_numeric( str_replace( ',', '.', $value ) ) ? (string) (float) str_replace( ',', '.', $value ) : '';
			}
			$loc['manual'][ $field ] = $value;
		}

		if ( '' !== $url && '' === $org_id ) {
			$loc['error'] = 'Не удалось распознать ссылку. Нужна ссылка на организацию: yandex.ru/maps/org/… или yandex.ru/profile/…';
		} elseif ( $org_id && ( empty( $loc['yandex'] ) || ! empty( $row['refresh'] ) ) ) {
			$data = clean_org_map_fetch_org( $org_id );
			if ( is_wp_error( $data ) ) {
				$loc['error'] = $data->get_error_message();
			} else {
				$merged        = clean_org_map_merge_yandex( $loc['yandex'], $data );
				$loc['yandex'] = $merged['data'];
				$loc['error']  = $merged['notice'];
				$notices[]     = '«' . $data['title'] . '»: данные загружены из Яндекс Карт.';
			}
		}

		$new['locations'][ $key ] = $loc;
	}

	update_option( CLEAN_ORG_MAP_OPTION, $new, false );
	set_transient( 'clean_org_map_notice_' . get_current_user_id(), $notices, 60 );

	wp_safe_redirect( admin_url( 'options-general.php?page=clean-org-map&saved=1' ) );
	exit;
}

function clean_org_map_admin_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	$settings = clean_org_map_settings();
	$notices  = get_transient( 'clean_org_map_notice_' . get_current_user_id() );
	delete_transient( 'clean_org_map_notice_' . get_current_user_id() );

	$labels = [
		'title'   => 'Название',
		'address' => 'Адрес',
		'phone'   => 'Телефон',
		'hours'   => 'Часы работы',
		'lat'     => 'Широта',
		'lon'     => 'Долгота',
	];

	$rows   = $settings['locations'];
	$rows[] = []; // пустая строка для новой точки
	?>
	<div class="wrap">
		<h1>Clean Org Map</h1>
		<p>Карта организации без рекламы. Вставьте ссылку на организацию в Яндекс Картах и сохраните — название, адрес, телефон, часы и координаты подтянутся сами и будут обновляться раз в неделю.
			Любое поле можно переписать вручную: ручное значение важнее яндексового, пустое поле — берётся из Яндекса.</p>

		<?php if ( isset( $_GET['saved'] ) ) : ?>
			<div class="notice notice-success is-dismissible"><p>Сохранено.</p></div>
		<?php endif; ?>
		<?php foreach ( (array) $notices as $notice ) : ?>
			<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
		<?php endforeach; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="clean_org_map_save">
			<?php wp_nonce_field( 'clean_org_map_save' ); ?>

			<h2>Точки</h2>
			<?php
			$i = 0;
			foreach ( $rows as $key => $row ) :
				$is_new = empty( $row );
				$y      = $row['yandex'] ?? [];
				$name   = 'com[locations][' . $i . ']';
				?>
				<div class="card" style="max-width:none;margin-bottom:16px">
					<h3 style="margin-top:0"><?php echo $is_new ? 'Добавить точку' : esc_html( ( $y['title'] ?? '' ) ?: $key ); ?></h3>
					<input type="hidden" name="<?php echo esc_attr( $name ); ?>[old_key]" value="<?php echo esc_attr( $is_new ? '' : $key ); ?>">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row">Ссылка на организацию</th>
							<td>
								<input type="url" class="large-text" name="<?php echo esc_attr( $name ); ?>[url]" value="<?php echo esc_attr( $row['url'] ?? '' ); ?>" placeholder="https://yandex.ru/maps/org/…/1234567890/">
								<?php if ( ! empty( $row['error'] ) ) : ?>
									<p style="color:#b32d2e"><?php echo esc_html( $row['error'] ); ?></p>
								<?php elseif ( ! empty( $y['fetched'] ) ) : ?>
									<p class="description">
										ID <?php echo esc_html( $row['org_id'] ); ?> · обновлено <?php echo esc_html( wp_date( 'd.m.Y H:i', $y['fetched'] ) ); ?>
										<?php if ( '' !== ( $y['rating'] ?? '' ) ) : ?>
											· рейтинг <?php echo esc_html( $y['rating'] ); ?>, отзывов <?php echo esc_html( $y['reviews'] ); ?>
										<?php endif; ?>
										· <a href="<?php echo esc_url( $y['url'] ); ?>" target="_blank" rel="noopener">карточка</a>
									</p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row">Ключ точки</th>
							<td>
								<input type="text" name="<?php echo esc_attr( $name ); ?>[key]" value="<?php echo esc_attr( $is_new ? '' : $key ); ?>" placeholder="например, moskva">
								<?php if ( ! $is_new ) : ?>
									<p class="description">Шорткод: <code>[clean_org_map location="<?php echo esc_html( $key ); ?>"]</code></p>
								<?php endif; ?>
							</td>
						</tr>
						<?php foreach ( $labels as $field => $label ) : ?>
							<tr>
								<th scope="row"><?php echo esc_html( $label ); ?></th>
								<td>
									<input type="text" class="regular-text" name="<?php echo esc_attr( $name . '[manual][' . $field . ']' ); ?>"
										value="<?php echo esc_attr( $row['manual'][ $field ] ?? '' ); ?>"
										placeholder="<?php echo esc_attr( ( $y[ $field ] ?? '' ) ?: ( $is_new ? 'из Яндекса' : '' ) ); ?>">
								</td>
							</tr>
						<?php endforeach; ?>
						<tr>
							<th scope="row">Подставлять в поля ACF</th>
							<td>
								<textarea class="large-text code" rows="3" name="<?php echo esc_attr( $name ); ?>[acf_map]" placeholder="contacts_address@options = address"><?php echo esc_textarea( $row['acf_map'] ?? '' ); ?></textarea>
								<p class="description">
									По строке на поле: <code>имя_поля = address</code>. Где тема выводит это поле ACF, будет значение из карточки — тему править не нужно.
									Уточнить, чьё поле: <code>@options</code> — страница настроек, <code>@тип_записи</code> — поле записей этого типа.
									Что подставлять: <?php echo esc_html( implode( ', ', array_keys( clean_org_map_output_fields() ) ) ); ?> (по умолчанию address).
								</p>
								<?php if ( ! $is_new ) : ?>
									<p class="description">В тексте: <code>[clean_org_map_field field="address" location="<?php echo esc_html( $key ); ?>"]</code></p>
								<?php endif; ?>
							</td>
						</tr>
						<tr>
							<th scope="row">Масштаб</th>
							<td>
								<input type="number" min="3" max="19" name="<?php echo esc_attr( $name ); ?>[zoom]" value="<?php echo esc_attr( ! empty( $row['zoom'] ) ? $row['zoom'] : '' ); ?>" placeholder="<?php echo esc_attr( $settings['zoom'] ); ?>">
							</td>
						</tr>
						<?php if ( ! $is_new ) : ?>
							<tr>
								<th scope="row">Действия</th>
								<td>
									<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[refresh]" value="1"> Обновить данные из Яндекса сейчас</label><br>
									<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[delete]" value="1"> Удалить точку</label>
								</td>
							</tr>
						<?php endif; ?>
					</table>
				</div>
				<?php
				$i++;
			endforeach;
			?>

			<h2>Общие настройки</h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row">Вид карты</th>
					<td>
						<label><input type="radio" name="com[engine]" value="vector" <?php checked( $settings['engine'], 'vector' ); ?>> Векторная (MapLibre GL, OpenFreeMap) — красивее, плавный масштаб</label><br>
						<label><input type="radio" name="com[engine]" value="raster" <?php checked( $settings['engine'], 'raster' ); ?>> Растровая (Leaflet) — легче, работает на старых браузерах</label>
					</td>
				</tr>
				<tr>
					<th scope="row">Стиль векторной карты</th>
					<td>
						<input type="url" class="large-text code" name="com[style]" value="<?php echo esc_attr( $settings['style'] ); ?>" list="clean-org-map-styles">
						<datalist id="clean-org-map-styles">
							<option value="https://tiles.openfreemap.org/styles/liberty">
							<option value="https://tiles.openfreemap.org/styles/bright">
							<option value="https://tiles.openfreemap.org/styles/positron">
						</datalist>
						<p class="description">OpenFreeMap: liberty — цветная, bright — яркая, positron — светлая минималистичная. Бесплатно, без ключа и без рекламы.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Язык подписей</th>
					<td>
						<input type="text" maxlength="2" style="width:4em" name="com[lang]" value="<?php echo esc_attr( $settings['lang'] ); ?>">
						<p class="description">Код языка: ru, en… Пусто — как в стиле (латиница + местное название).</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Шрифт подписей</th>
					<td>
						<label><input type="checkbox" name="com[one_font]" value="1" <?php checked( $settings['one_font'] ); ?>> Одно начертание для всех подписей</label>
						<p class="description">Без курсива и жирного: карта качает примерно втрое меньше файлов шрифтов и открывается быстрее.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Подложка растровой карты</th>
					<td>
						<input type="text" class="large-text code" name="com[tiles]" value="<?php echo esc_attr( $settings['tiles'] ); ?>">
						<p class="description">Адрес тайлов с {z}, {x}, {y}. По умолчанию OpenStreetMap — бесплатно, без ключа и без рекламы.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Подпись источника</th>
					<td>
						<input type="text" class="large-text code" name="com[attribution]" value="<?php echo esc_attr( $settings['attribution'] ); ?>">
						<p class="description">Лицензия OpenStreetMap требует эту подпись на карте — не удаляйте её.</p>
					</td>
				</tr>
				<tr>
					<th scope="row">Масштаб по умолчанию</th>
					<td><input type="number" min="3" max="19" name="com[zoom]" value="<?php echo esc_attr( $settings['zoom'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row">Высота карты, px</th>
					<td><input type="number" min="150" max="2000" name="com[height]" value="<?php echo esc_attr( $settings['height'] ); ?>"></td>
				</tr>
			</table>

			<?php submit_button( 'Сохранить' ); ?>
		</form>

		<h2>Как вывести</h2>
		<p>В тексте страницы: <code>[clean_org_map]</code> — первая точка, <code>[clean_org_map location="ключ" height="500" buttons="0"]</code>.</p>
		<p>В теме: <code>&lt;?php echo clean_org_map_render( [ 'location' =&gt; 'ключ' ] ); ?&gt;</code>,
			данные точки — <code>clean_org_map_get_location( 'ключ' )</code>,
			выбор точки по умолчанию — фильтр <code>clean_org_map_default_location</code>.</p>
		<p>Адрес, телефон, часы из карточки — текстом прямо в HTML страницы:
			<code>[clean_org_map_field field="address" location="ключ"]</code> (поля: <?php echo esc_html( implode( ', ', array_keys( clean_org_map_output_fields() ) ) ); ?>;
			<code>link="org"</code> или <code>link="route"</code> — ссылкой на карточку или маршрут),
			в теме — <code>&lt;?php clean_org_map_the_field( 'address', 'ключ' ); ?&gt;</code>.</p>
	</div>
	<?php
}
