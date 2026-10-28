/* Clean Org Map: библиотека карты грузится, только когда карта подъезжает к экрану. */
(function () {
	'use strict';

	var loading = {};

	function loadLibrary(base, name, globalName) {
		if (window[globalName]) {
			return Promise.resolve(window[globalName]);
		}
		if (loading[name]) {
			return loading[name];
		}
		loading[name] = new Promise(function (resolve, reject) {
			var css = document.createElement('link');
			css.rel = 'stylesheet';
			css.href = base + name + '.css';
			document.head.appendChild(css);

			var js = document.createElement('script');
			js.src = base + name + '.js';
			js.onload = function () { resolve(window[globalName]); };
			js.onerror = reject;
			document.head.appendChild(js);
		});
		return loading[name];
	}

	function popupContent(cfg) {
		var box = document.createElement('div');
		box.className = 'clean-org-map__popup';

		if (cfg.image) {
			var image = document.createElement('img');
			image.className = 'clean-org-map__popup-image';
			image.src = cfg.image;
			image.alt = cfg.title || '';
			image.loading = 'lazy';
			image.decoding = 'async';
			box.appendChild(image);
		}

		var title = document.createElement('strong');
		title.textContent = cfg.title;
		box.appendChild(title);

		// Описание — простой текст, перенос строки = новая строка в подсказке.
		[cfg.address, cfg.description].forEach(function (text) {
			if (!text) {
				return;
			}
			var line = document.createElement('div');
			String(text).split('\n').forEach(function (part, i) {
				if (i) {
					line.appendChild(document.createElement('br'));
				}
				line.appendChild(document.createTextNode(part));
			});
			box.appendChild(line);
		});

		[[cfg.orgUrl, 'Открыть в Яндекс Картах'], [cfg.routeUrl, 'Построить маршрут'], [cfg.url, 'Подробнее']].forEach(function (link) {
			if (!link[0]) {
				return;
			}
			var a = document.createElement('a');
			a.href = link[0];
			if (link[0] !== cfg.url) {
				a.target = '_blank';
				a.rel = 'noopener';
			}
			a.textContent = link[1];
			box.appendChild(a);
		});
		return box;
	}

	// Своя иконка метки (cfg.marker): острие — в середине нижнего края картинки.
	function markerElement(cfg) {
		var img = document.createElement('img');
		img.className = 'clean-org-map__marker';
		img.src = cfg.marker;
		img.alt = '';
		img.width = cfg.markerSize;
		img.height = cfg.markerSize;
		// Стили темы для img (width: 100% и т. п.) атрибуты перебивают — размер ставим в style.
		img.style.cssText = 'width:' + cfg.markerSize + 'px;height:' + cfg.markerSize + 'px;max-width:none;object-fit:contain';
		return img;
	}

	function removeFallback(el) {
		var fallback = el.querySelector('.clean-org-map__fallback');
		if (fallback) {
			el.removeChild(fallback);
		}
		el.classList.add('clean-org-map__canvas_ready');
	}

	// Подпись источников обязательна по лицензии, но вес ссылок отдавать не нужно.
	function nofollow(html) {
		return String(html || '')
			.replace(/\s(rel|target)="[^"]*"/g, '')
			.replace(/<a\s/g, '<a rel="nofollow noopener" target="_blank" ');
	}

	// Правим стиль до отрисовки, чтобы карта не качала лишнего и не перерисовывала подписи.
	function prepareStyle(style, cfg) {
		Object.keys(style.sources || {}).forEach(function (id) {
			if (style.sources[id].attribution) {
				style.sources[id].attribution = nofollow(style.sources[id].attribution);
			}
		});

		var regular = null;
		if (cfg.oneFont) {
			style.layers.forEach(function (layer) {
				var font = layer.layout && layer.layout['text-font'];
				if (!regular && Array.isArray(font) && /Regular/.test(font.join(' '))) {
					regular = font;
				}
			});
		}
		// Стили OpenFreeMap подписывают объекты «латиница + местное название».
		// Оставляем одно название на нужном языке, без него — местное.
		var name = ['coalesce', ['get', 'name:' + cfg.lang], ['get', 'name']];

		style.layers.forEach(function (layer) {
			var layout = layer.layout;
			if (!layout || !layout['text-field']) {
				return;
			}
			// Курсив и жирный — это отдельные файлы шрифтов на каждый алфавит
			// (~40–120 КБ каждый). Одно начертание вместо трёх заметно легче.
			if (regular && Array.isArray(layout['text-font'])) {
				layout['text-font'] = regular;
			}
			if (cfg.lang && JSON.stringify(layout['text-field']).indexOf('name') !== -1) {
				layout['text-field'] = name;
			}
		});
		return style;
	}

	function initVector(el, cfg) {
		var style = fetch(cfg.style).then(function (r) {
			if (!r.ok) {
				throw new Error('style ' + r.status);
			}
			return r.json();
		});

		return Promise.all([loadLibrary(cfg.maplibre, 'maplibre-gl', 'maplibregl'), style]).then(function (res) {
			var gl = res[0];
			removeFallback(el);

			// Колесо мыши не перехватываем, на телефоне карта двигается двумя
			// пальцами — иначе страница перестаёт прокручиваться над картой.
			var points = cfg.points;
			var padding = visiblePadding(el, cfg);
			var map = new gl.Map({
				container: el,
				style: prepareStyle(res[1], cfg),
				center: [points[0].lon, points[0].lat],
				zoom: cfg.zoom,
				scrollZoom: false,
				cooperativeGestures: window.matchMedia('(pointer: coarse)').matches,
				attributionControl: { compact: false }
			});
			map.addControl(new gl.NavigationControl({ showCompass: false }), 'top-right');

			// Конструктор Map отступы не принимает — задаём камерой.
			// Несколько точек — показываем все сразу, но не ближе заданного масштаба.
			if (points.length > 1) {
				var bounds = new gl.LngLatBounds();
				points.forEach(function (p) { bounds.extend([p.lon, p.lat]); });
				map.fitBounds(bounds, { padding: widen(padding, 60), maxZoom: cfg.zoom, duration: 0 });
			} else {
				map.jumpTo({ center: [points[0].lon, points[0].lat], padding: padding });
			}

			map.once('idle', function () { el.classList.add('clean-org-map__canvas_loaded'); });

			// MapLibre выбрасывает rel из подписи источников и перерисовывает её
			// при догрузке данных — проставляем nofollow на каждое изменение.
			var attrib = el.querySelector('.maplibregl-ctrl-attrib');
			if (attrib && window.MutationObserver) {
				var mark = function () {
					Array.prototype.forEach.call(attrib.querySelectorAll('a:not([rel~="nofollow"])'), function (a) {
						a.rel = 'nofollow noopener';
					});
				};
				new MutationObserver(mark).observe(attrib, { childList: true, subtree: true });
				mark();
			}

			// Подсказка открывается над меткой — при центровке опускаем метку на её высоту.
			function popupPadding(p) {
				var pad = visiblePadding(el, cfg);
				pad.top += p && p.image ? 280 : 120;
				return pad;
			}

			var markers = points.map(function (p) {
				var popup = new gl.Popup({ offset: cfg.marker ? cfg.markerSize : 30 }).setDOMContent(popupContent(p));
				// Открытая подсказка залезла под плашку или за край карты — сдвигаем карту.
				popup.on('open', function () {
					requestAnimationFrame(function () {
						var pad = visiblePadding(el, cfg);
						var m = el.getBoundingClientRect();
						var r = popup.getElement().getBoundingClientRect();
						if (r.top < m.top + pad.top || r.bottom > m.bottom - pad.bottom ||
							r.left < m.left + pad.left || r.right > m.right - pad.right) {
							map.easeTo({ center: [p.lon, p.lat], padding: popupPadding(p), duration: 500 });
						}
					});
				});
				return new gl.Marker(cfg.marker ? { element: markerElement(cfg), anchor: 'bottom' } : { color: '#e5322d' })
					.setLngLat([p.lon, p.lat])
					.setPopup(popup)
					.addTo(map);
			});

			ready(el, map, function (i, zoom) {
				map.flyTo({ center: [points[i].lon, points[i].lat], zoom: zoom || cfg.zoom, padding: popupPadding(points[i]) });
				map.once('moveend', function () {
					if (!markers[i].getPopup().isOpen()) {
						markers[i].togglePopup();
					}
				});
			});
		});
	}

	// Сколько карты закрыто вёрсткой: заданные отступы плюс плашка cfg.avoid
	// (CSS-селектор), если она лежит поверх карты. Плашку меряем каждый раз —
	// на телефоне она обычно сверху, на компьютере сбоку.
	function visiblePadding(el, cfg) {
		var base = (window.matchMedia('(max-width: 767.98px)').matches ? cfg.paddingMob : cfg.padding) || {};
		var pad = { top: base.top || 0, right: base.right || 0, bottom: base.bottom || 0, left: base.left || 0 };
		var overlay = cfg.avoid && document.querySelector(cfg.avoid);
		if (!overlay) {
			return pad;
		}
		var m = el.getBoundingClientRect();
		var o = overlay.getBoundingClientRect();
		if (!o.width || o.right <= m.left || o.left >= m.right || o.bottom <= m.top || o.top >= m.bottom) {
			return pad;
		}
		var gap = 16;
		if (o.width > m.width * 0.55) {
			// Плашка во всю ширину — уводим точки выше или ниже неё.
			if (o.top + o.height / 2 < m.top + m.height / 2) {
				pad.top = Math.max(pad.top, o.bottom - m.top + gap);
			} else {
				pad.bottom = Math.max(pad.bottom, m.bottom - o.top + gap);
			}
		} else if (o.left + o.width / 2 < m.left + m.width / 2) {
			pad.left = Math.max(pad.left, o.right - m.left + gap);
		} else {
			pad.right = Math.max(pad.right, m.right - o.left + gap);
		}
		return pad;
	}

	function widen(padding, extra) {
		return {
			top: (padding.top || 0) + extra,
			right: (padding.right || 0) + extra,
			bottom: (padding.bottom || 0) + extra,
			left: (padding.left || 0) + extra
		};
	}

	// Для темы: el.cleanOrgMap.show(i) — перелететь к i-й точке и открыть её подсказку.
	// Событие clean-org-map:ready приходит, когда карта создана.
	function ready(el, map, show) {
		el.cleanOrgMap = { map: map, show: show };
		el.dispatchEvent(new CustomEvent('clean-org-map:ready', { bubbles: true, detail: el.cleanOrgMap }));
	}

	function initRaster(el, cfg) {
		return loadLibrary(cfg.leaflet, 'leaflet', 'L').then(function (L) {
			removeFallback(el);
			L.Icon.Default.imagePath = cfg.leaflet + 'images/';

			var points = cfg.points;
			var map = L.map(el, {
				scrollWheelZoom: false,
				dragging: !L.Browser.mobile,
				tap: false
			});
			if (points.length > 1) {
				map.fitBounds(points.map(function (p) { return [p.lat, p.lon]; }), { padding: [60, 60], maxZoom: cfg.zoom });
			} else {
				map.setView([points[0].lat, points[0].lon], cfg.zoom);
			}

			map.attributionControl.setPrefix(nofollow('<a href="https://leafletjs.com">Leaflet</a>'));
			L.tileLayer(cfg.tiles, { maxZoom: 19, attribution: nofollow(cfg.attribution) }).addTo(map);
			var s = cfg.markerSize;
			var icon = cfg.marker ? L.icon({ iconUrl: cfg.marker, iconSize: [s, s], iconAnchor: [s / 2, s], popupAnchor: [0, -s] }) : null;
			var markers = points.map(function (p) {
				var opts = { title: p.title };
				if (icon) {
					opts.icon = icon;
				}
				return L.marker([p.lat, p.lon], opts).addTo(map).bindPopup(popupContent(p));
			});
			el.classList.add('clean-org-map__canvas_loaded');

			ready(el, map, function (i, zoom) {
				map.flyTo([points[i].lat, points[i].lon], zoom || cfg.zoom);
				markers[i].openPopup();
			});
		});
	}

	function init(el) {
		var cfg;
		try {
			cfg = JSON.parse(el.getAttribute('data-clean-org-map'));
		} catch (e) {
			return;
		}
		// Без WebGL векторная карта не нарисуется — уходим на растровую.
		var webgl = !!document.createElement('canvas').getContext('webgl');
		if (cfg.engine === 'vector' && webgl) {
			initVector(el, cfg).catch(function () { initRaster(el, cfg); });
		} else {
			initRaster(el, cfg);
		}
	}

	function start() {
		var maps = document.querySelectorAll('[data-clean-org-map]');
		if (!maps.length) {
			return;
		}
		if (!('IntersectionObserver' in window)) {
			Array.prototype.forEach.call(maps, init);
			return;
		}
		var io = new IntersectionObserver(function (entries) {
			entries.forEach(function (entry) {
				if (entry.isIntersecting) {
					io.unobserve(entry.target);
					init(entry.target);
				}
			});
		}, { rootMargin: '1500px 0px' });
		Array.prototype.forEach.call(maps, function (el) { io.observe(el); });
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start);
	} else {
		start();
	}
})();
