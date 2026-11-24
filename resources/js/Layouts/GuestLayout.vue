<script setup>
import ApplicationLogo from '@/Components/ApplicationLogo.vue';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <div class="grid min-h-screen lg:grid-cols-2">
        <div class="relative hidden flex-col justify-between overflow-hidden bg-gradient-to-br from-brand-600 via-brand-700 to-brand-900 p-12 text-white lg:flex">
            <div class="pointer-events-none absolute -right-24 -top-24 h-96 w-96 rounded-full bg-white/10 blur-3xl"></div>
            <div class="pointer-events-none absolute -bottom-32 -left-16 h-96 w-96 rounded-full bg-brand-300/20 blur-3xl"></div>
            <Link href="/" class="relative flex items-center gap-3">
                <ApplicationLogo class="h-10 w-10" />
                <span class="text-xl font-bold tracking-tight">Pixelgen</span>
            </Link>
            <div class="relative max-w-md">
                <h1 class="text-4xl font-bold leading-tight tracking-tight">Turn a sentence into an image.</h1>
                <p class="mt-4 text-lg text-brand-100">Prompt, size and quality in. A priced, tracked generation out, with the cost shown before you spend a cent.</p>
                <ul class="mt-8 space-y-3 text-sm text-brand-100">
                    <li class="flex items-center gap-3"><span class="h-1.5 w-1.5 rounded-full bg-white"></span>Exact cost estimate before you generate</li>
                    <li class="flex items-center gap-3"><span class="h-1.5 w-1.5 rounded-full bg-white"></span>Live status from webhooks, with polling fallback</li>
                    <li class="flex items-center gap-3"><span class="h-1.5 w-1.5 rounded-full bg-white"></span>Credits held while running, settled to the real cost</li>
                </ul>
            </div>
            <p class="relative text-xs text-brand-200">Built on the Pixelforge API (simulated provider).</p>
        </div>

        <div class="flex flex-col items-center justify-center bg-slate-50 px-6 py-12">
            <Link href="/" class="mb-8 flex items-center gap-2.5 lg:hidden">
                <ApplicationLogo class="h-9 w-9" />
                <span class="text-xl font-bold tracking-tight">Pixelgen</span>
            </Link>
            <div class="w-full max-w-sm rounded-2xl border border-slate-200 bg-white p-8 shadow-card">
                <slot />
            </div>
        </div>
    </div>
</template>
