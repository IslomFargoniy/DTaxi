import { layers, namedFlavor } from '@protomaps/basemaps';
import {
    addProtocol,
    Map as MapLibreMap,
    NavigationControl,
} from 'maplibre-gl';
import type { MapMouseEvent, StyleSpecification } from 'maplibre-gl';
import 'maplibre-gl/dist/maplibre-gl.css';
import { Protocol } from 'pmtiles';
import { useEffect, useRef } from 'react';
import { useTrans } from '@/hooks/use-trans';

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
        layers: layers('protomaps', namedFlavor(flavor), { lang }),
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
            style: mapStyle(tilesUrl, dark, locale === 'ru' ? 'ru' : 'uz'),
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

    return <div ref={container} className={className} />;
}
