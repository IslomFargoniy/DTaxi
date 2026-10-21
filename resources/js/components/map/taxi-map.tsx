import { layers, namedFlavor } from '@protomaps/basemaps';
import {
    addProtocol,
    Map as MapLibreMap,
    NavigationControl,
    setWorkerUrl,
} from 'maplibre-gl';
// MapLibre 6 worker'ni o'z chunk'iga nisbatan qidiradi — Vite bundle'ida u yo'q, shuning uchun aniq manzil
import maplibreWorkerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?url';
import type {
    ExpressionSpecification,
    MapMouseEvent,
    StyleSpecification,
} from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import { PMTiles, Protocol } from 'pmtiles';
import { useEffect, useRef, useState } from 'react';
import { renames as streetRenamesRoute } from '@/actions/App/Http/Controllers/MapStyleController';
import { useTrans } from '@/hooks/use-trans';
import { getJson } from '@/lib/http';
import { mapLang } from '@/lib/locales';

setWorkerUrl(maplibreWorkerUrl);

let protocolRegistered = false;

/** Qayta nomlangan ko'chalar: OSM'dagi eski nom → admin kiritgan yangi nom */
export type StreetRenames = Record<string, string>;

let streetRenames: Promise<StreetRenames> | null = null;

/** Sahifa ochilganda bir marta olinadi (server 5 daqiqa kesh qiladi) */
function loadStreetRenames(): Promise<StreetRenames> {
    streetRenames ??= getJson<StreetRenames>(streetRenamesRoute.url()).catch(
        () => ({}),
    );

    return streetRenames;
}

/**
 * Ko'cha yozuvi eski nomga teng bo'lsa — yangi nom (MapStyleController::renamed bilan bir xil).
 * Tildagi nom (name:uz) bo'lsa ham asosiy nom (name) tekshiriladi.
 */
function renamedLabel(
    textField: unknown,
    lang: string,
    renames: StreetRenames,
): ExpressionSpecification {
    const pairs = Object.entries(renames).flatMap(([old, name]) => [
        old,
        ['format', name, {}],
    ]);

    return [
        'match',
        ['coalesce', ['get', `name:${lang}`], ['get', 'name'], ''],
        ...pairs,
        ['match', ['coalesce', ['get', 'name'], ''], ...pairs, textField],
    ] as unknown as ExpressionSpecification;
}

/**
 * Xarita uslubi: tile'lar, shriftlar va belgilar o'z serverimizda (public/map, qaror #2).
 */
export function mapStyle(
    tilesUrl: string,
    dark: boolean,
    lang: string,
    renames: StreetRenames = {},
): StyleSpecification {
    const flavor = dark ? 'dark' : 'light';
    const renamed = Object.keys(renames).length > 0;

    return {
        version: 8,
        glyphs: '/map/fonts/{fontstack}/{range}.pbf',
        sprite: `${window.location.origin}/map/sprites/v4/${flavor}`,
        sources: {
            protomaps: {
                type: 'vector',
                url: `pmtiles://${new URL(tilesUrl, window.location.origin).href}`,
                // Mo'ljallar qidiruvi Overture ma'lumotidan ham foydalanadi (qaror #60)
                attribution:
                    '© OpenStreetMap · Overture Maps Foundation · Foursquare',
            },
            dtaxi: {
                type: 'vector',
                tiles: [
                    `${window.location.origin}/map/landmarks/{z}/{x}/{y}.pbf`,
                ],
                minzoom: 13,
                maxzoom: 16,
            },
        },
        // Uy raqamlari 18 o'rniga 17-zoom'dan (resources/map/generate-layers.mjs bilan bir xil).
        // Joy yozuvlari — bizning bazadan (MapStyleController bilan bir xil): tuzatilgan nomlar darhol ko'rinadi
        layers: layers('protomaps', namedFlavor(flavor), { lang }).map(
            (layer) => {
                if (layer.id === 'address_label') {
                    return { ...layer, minzoom: 17 };
                }
                if (
                    renamed &&
                    layer.type === 'symbol' &&
                    layer['source-layer'] === 'roads' &&
                    layer.id !== 'roads_shields' &&
                    layer.layout?.['text-field'] !== undefined
                ) {
                    return {
                        ...layer,
                        layout: {
                            ...layer.layout,
                            'text-field': renamedLabel(
                                layer.layout['text-field'],
                                lang,
                                renames,
                            ),
                        },
                    };
                }
                if (layer.id === 'pois' && layer.type === 'symbol') {
                    // Ikonkasi yo'q turlar (karaoke, dorixona...) uslubda fon rangida yozilardi — o'qib bo'lmasdi
                    const color = layer.paint?.['text-color'];
                    const paint = Array.isArray(color)
                        ? {
                              ...layer.paint,
                              'text-color': [
                                  ...color.slice(0, -1),
                                  dark ? '#c2bdb5' : '#5c5853',
                              ] as typeof color,
                          }
                        : layer.paint;

                    return {
                        ...layer,
                        paint,
                        source: 'dtaxi',
                        'source-layer': 'pois',
                        filter: ['>=', ['zoom'], ['get', 'min_zoom']],
                        layout: {
                            ...layer.layout,
                            'text-field': ['get', 'name'],
                        },
                    };
                }

                return layer;
            },
        ),
    };
}

export type MapPoint = { lat: number; lng: number };

type Props = {
    tilesUrl: string;
    /** null — markaz noma'lum (filialga kiritilmagan): xarita fayli qamragan hudud ko'rsatiladi */
    center: MapPoint | null;
    zoom?: number;
    className?: string;
    onReady?: (map: MapLibreMap) => void;
    onClick?: (point: MapPoint) => void;
};

/**
 * MapLibre xaritasi. Markerlar va qatlamlarni ota komponent `onReady` orqali boshqaradi.
 */
export function TaxiMap({
    tilesUrl,
    center,
    zoom = 13,
    className,
    onReady,
    onClick,
}: Props) {
    const container = useRef<HTMLDivElement>(null);
    const mapRef = useRef<MapLibreMap | null>(null);
    const clickRef = useRef(onClick);
    const { locale } = useTrans();
    const lang = mapLang(locale);
    const [dark, setDark] = useState(() =>
        document.documentElement.classList.contains('dark'),
    );
    const [renames, setRenames] = useState<StreetRenames>({});
    const renamed = Object.keys(renames).length > 0;
    // Hozir xaritada turgan uslub — mavzu, til yoki ko'cha nomlari o'zgarsa qayta qo'yiladi
    const applied = useRef(`${dark}:${lang}:${renamed}`);

    useEffect(() => {
        let active = true;
        void loadStreetRenames().then((value) => active && setRenames(value));

        return () => {
            active = false;
        };
    }, []);

    // Yorug'/qorong'i rejim <html class="dark"> orqali almashadi
    useEffect(() => {
        const root = document.documentElement;
        const observer = new MutationObserver(() =>
            setDark(root.classList.contains('dark')),
        );
        observer.observe(root, {
            attributes: true,
            attributeFilter: ['class'],
        });

        return () => observer.disconnect();
    }, []);

    useEffect(() => {
        const map = mapRef.current;
        const key = `${dark}:${lang}:${renamed}`;
        if (!map || applied.current === key) {
            return;
        }
        applied.current = key;
        // Ota komponent qo'shgan manba va qatlamlar (haydovchilar, bekatlar) saqlanadi
        map.setStyle(mapStyle(tilesUrl, dark, lang, renames), {
            transformStyle: (previous, next) => {
                if (!previous) {
                    return next;
                }
                const ids = new Set(next.layers.map((layer) => layer.id));

                return {
                    ...next,
                    sources: { ...previous.sources, ...next.sources },
                    layers: [
                        ...next.layers,
                        ...previous.layers.filter(
                            (layer) => !ids.has(layer.id),
                        ),
                    ],
                };
            },
        });
    }, [dark, lang, tilesUrl, renames, renamed]);

    useEffect(() => {
        clickRef.current = onClick;
    }, [onClick]);

    useEffect(() => {
        if (!container.current) {
            return;
        }

        if (!protocolRegistered) {
            addProtocol('pmtiles', new Protocol().tile);
            protocolRegistered = true;
        }

        const map = new MapLibreMap({
            container: container.current,
            style: mapStyle(tilesUrl, dark, lang, renames),
            center: center ? [center.lng, center.lat] : [0, 0],
            zoom: center ? zoom : 1,
            attributionControl: { compact: true },
        });

        map.addControl(
            new NavigationControl({ showCompass: false }),
            'top-right',
        );
        map.on('click', (e: MapMouseEvent) =>
            clickRef.current?.({ lat: e.lngLat.lat, lng: e.lngLat.lng }),
        );
        map.on('load', () => onReady?.(map));
        if (!center) {
            // Koddagi biror shahar emas (mahsulot har firmaga sotiladi) — tile fayli qaysi hudud uchun yasalgan bo'lsa o'sha
            void new PMTiles(new URL(tilesUrl, window.location.origin).href)
                .getHeader()
                .then((h) => {
                    const lng = h.centerLon || (h.minLon + h.maxLon) / 2;
                    const lat = h.centerLat || (h.minLat + h.maxLat) / 2;
                    map.jumpTo({
                        center: [lng, lat],
                        zoom: Math.min(Math.max(h.centerZoom || 9, 9), 12),
                    });
                })
                .catch(() => undefined);
        }
        mapRef.current = map;

        return () => {
            mapRef.current = null;
            map.remove();
        };
        // Xarita bir marta yaratiladi; markazni ota komponent map.flyTo bilan o'zgartiradi
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // MapLibre CSS konteynerga position: relative beradi va tashqi "absolute inset-0" ni bekor qiladi —
    // shuning uchun joylashuv tashqi o'ramda, xarita ichida to'liq o'lchamda
    return (
        <div className={className}>
            <div ref={container} className="h-full w-full" />
        </div>
    );
}
