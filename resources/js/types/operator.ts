export type OrderStatus =
    | 'new'
    | 'searching'
    | 'assigned'
    | 'arrived'
    | 'in_progress'
    | 'completed'
    | 'cancelled_by_client'
    | 'cancelled_by_operator'
    | 'cancelled_by_driver'
    | 'no_driver_found';

export type Place = {
    text: string | null;
    lat: number | null;
    lng: number | null;
};

export type Order = {
    id: number;
    branch_id: number;
    status: OrderStatus;
    client?: { id: number; phone: string; name: string | null };
    driver?: {
        id: number;
        callsign: string;
        name: string;
        phone: string | null;
    } | null;
    vehicle?: { model: string; color: string; plate_number: string } | null;
    tariff_class?: { id: number; name: string; is_delivery: boolean };
    pickup: Place;
    dropoff: Place | null;
    comment: string | null;
    price_estimate: number | null;
    price_final: number | null;
    search_radius_m: number;
    created_at: string;
    assigned_at: string | null;
    arrived_at: string | null;
};

export type MapDriver = {
    id: number;
    callsign: string;
    name?: string;
    status: 'offline' | 'free' | 'busy';
    lat: number | null;
    lng: number | null;
    vehicle?: string | null;
};

export type Branch = {
    id: number;
    name: string;
    center: { lat: number; lng: number } | null;
};

export type TariffClass = {
    id: number;
    branch_id: number;
    name: string;
    icon: string | null;
    is_delivery: boolean;
    is_default: boolean;
};

export type Landmark = {
    id: number;
    name: string;
    aliases: string[];
    category: string | null;
    lat: number;
    lng: number;
};

export type ClientCard = {
    client: {
        id: number;
        phone: string;
        name: string | null;
        note: string | null;
        is_blacklisted: boolean;
        orders_count: number;
    } | null;
    addresses: {
        id: number;
        label: string | null;
        text: string;
        landmark_id: number | null;
        lat: number;
        lng: number;
    }[];
    orders: {
        id: number;
        status: OrderStatus;
        pickup_text: string;
        dropoff_text: string | null;
        price_final: number | null;
        created_at: string;
    }[];
};
