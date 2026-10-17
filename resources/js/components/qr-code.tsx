import QRCode from 'qrcode';
import { useEffect, useState } from 'react';

export function QrCode({
    value,
    size = 220,
}: {
    value: string;
    size?: number;
}) {
    const [src, setSrc] = useState<string | null>(null);

    useEffect(() => {
        QRCode.toDataURL(value, { margin: 1, width: size })
            .then(setSrc)
            .catch(() => setSrc(null));
    }, [value, size]);

    return src ? (
        <img
            src={src}
            alt={value}
            width={size}
            height={size}
            className="rounded-md border bg-white p-1"
        />
    ) : null;
}
