import type { SVGAttributes } from 'react';

export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            {...props}
            viewBox="0 0 24 24"
            fill="none"
            xmlns="http://www.w3.org/2000/svg"
        >
            <path
                d="m3 8 9-4 9 4-9 4-9-4Z"
                stroke="currentColor"
                strokeWidth="1.8"
                strokeLinejoin="round"
            />
            <path
                d="M7 10.2v4.3c0 1.4 2.2 2.8 5 2.8s5-1.4 5-2.8v-4.3M21 8v5"
                stroke="currentColor"
                strokeWidth="1.8"
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    );
}
