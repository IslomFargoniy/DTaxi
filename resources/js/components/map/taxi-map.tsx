import { layers, namedFlavor } from '@protomaps/basemaps';
import {
    addProtocol,
    Map as MapLibreMap,
    NavigationControl,
    setWorkerUrl,
} from 'maplibre-gl';
// MapLibre 6 worker'ni o'z chunk'iga nisbatan qidiradi — Vite bundle'ida u yo'q, shuning uchun aniq manzil
import maplibreWorkerUrl from 'maplibre-gl/dist/maplibre-gl-worker.mjs?url';
import type { MapMouseEvent, StyleSpecification } from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import { Protocol } from 'pmtiles';
import { useEffect, useRef, useState } from 'react';
import { useTrans } from '@/hooks/use-trans';
import { mapLang } from '@/lib/locales';

setWorkerUrl(maplibreWorkerUrl);

let protocolRegistered = false;

/**
 * Xarita uslubi: tile'lar, shriftlar va belgilar o'z serverimizda (public/map, qaror #2).
 */
export function mapStyle(
    tilesUrl: string,
    dark: boolean,
    lang: string,
): StyleSpecification {
    const flavor = dark ? 'dark' : 'light';

    return {
        version: 8,
        glyphs: '/map/fonts/{fontstack}/{range}.pbf',
        sprite: `${window.location.origin}/map/sprites/v4/${flavor}`,
        sources: {
            protomaps: {
                type: 'vector',
                url: `pmtiles://${new URL(tilesUrl, window.location.origin).href}`,
                attribution: '© OpenStreetMap',
            },
        },
        // Uy raqamlari 18 o'rniga 17-zoom'dan (resources/map/generate-layers.mjs bilan bir xil)
        layers: layers('protomaps', namedFlavor(flavor), { lang }).map(
            (layer) =>
                layer.id === 'address_label'
                    ? { ...layer, minzoom: 17 }
                    : layer,
        ),
    };
}

export type MapPoint = { lat: number; lng: number };

type Props = {
    tilesUrl: string;
    center: MapPoint;
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
    // Hozir xaritada turgan uslub — mavzu yoki til o'zgarsa qayta qo'yiladi
    const applied = useRef(`${dark}:${lang}`);

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
        const key = `${dark}:${lang}`;
        if (!map || applied.current === key) {
            return;
        }
        applied.current = key;
        // Ota komponent qo'shgan manba va qatlamlar (haydovchilar, bekatlar) saqlanadi
        map.setStyle(mapStyle(tilesUrl, dark, lang), {
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
    }, [dark, lang, tilesUrl]);

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
            style: mapStyle(tilesUrl, dark, lang),
            center: [center.lng, center.lat],
            zoom,
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
