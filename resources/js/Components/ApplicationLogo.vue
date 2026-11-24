<template>
    <svg viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg" aria-label="Pixelgen">
        <defs>
            <linearGradient id="pg" x1="0" y1="0" x2="1" y2="1">
                <stop offset="0" stop-color="#8a7dff" />
                <stop offset="1" stop-color="#4c35c9" />
            </linearGradient>
        </defs>
        <rect width="32" height="32" rx="9" fill="url(#pg)" />
        <rect x="7" y="7" width="8" height="8" rx="2" fill="#fff" />
        <rect x="17" y="7" width="8" height="8" rx="2" fill="#fff" fill-opacity=".55" />
        <rect x="7" y="17" width="8" height="8" rx="2" fill="#fff" fill-opacity=".55" />
        <rect x="17" y="17" width="8" height="8" rx="2" fill="#fff" fill-opacity=".85" />
    </svg>
</template>
