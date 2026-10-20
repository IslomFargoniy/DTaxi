import type { SVGAttributes } from 'react';

/**
 * D taxi belgisi: "D" harfi, ichida lokatsiya belgisi (teshik). Bir rangli — rangni `fill-current` beradi.
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg {...props} viewBox="0 0 80 88" xmlns="http://www.w3.org/2000/svg">
            <path
                fillRule="evenodd"
                clipRule="evenodd"
                d="M8 4h28a40 40 0 0 1 0 80H8z M36 22c-9 0-16 7-16 16 0 12 16 28 16 28s16-16 16-28c0-9-7-16-16-16z"
            />
            <circle cx="36" cy="38" r="6" />
        </svg>
    );
}
