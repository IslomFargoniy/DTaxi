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
import { useEffect, useRef } from 'react';
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
    const clickRef = useRef(onClick);
    const { locale } = useTrans();

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

        const dark = document.documentElement.classList.contains('dark');
        const map = new MapLibreMap({
            container: container.current,
            style: mapStyle(tilesUrl, dark, mapLang(locale)),
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

        return () => map.remove();
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
