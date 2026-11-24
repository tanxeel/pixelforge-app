<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted } from 'vue';

const props = defineProps({ generations: Array, balance: String });

const inFlight = computed(() => props.generations.some((g) => g.status === 'queued' || g.status === 'processing'));

// Live status: webhooks update the database, the page re-fetches while anything is still running.
let timer;
onMounted(() => {
    timer = setInterval(() => {
        if (inFlight.value) router.reload({ only: ['generations', 'balance'], preserveScroll: true });
    }, 3000);
});
onBeforeUnmount(() => clearInterval(timer));

const badge = {
    queued: 'bg-slate-100 text-slate-700 ring-slate-200',
    processing: 'bg-blue-50 text-blue-700 ring-blue-200',
    succeeded: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    failed: 'bg-red-50 text-red-700 ring-red-200',
};
</script>

<template>
    <Head title="History" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-end justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-bold tracking-tight text-slate-900">History</h2>
                    <p class="mt-1 text-sm text-slate-500">Every generation with its live status and cost.</p>
                </div>
                <div class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-1.5 text-sm text-slate-600">Balance <strong class="text-slate-900">${{ balance }}</strong></div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-5xl space-y-4 px-4 sm:px-6 lg:px-8">
                <div v-if="!generations.length" class="rounded-2xl border border-dashed border-slate-300 bg-white p-12 text-center shadow-card">
                    <div class="text-lg font-semibold text-slate-800">Nothing here yet</div>
                    <p class="mt-1 text-sm text-slate-500">Generate your first image and it will show up here.</p>
                    <Link :href="route('generate')" class="mt-5 inline-flex rounded-lg bg-brand-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-brand-700">Generate an image</Link>
                </div>

                <div v-for="g in generations" :key="g.id" class="flex gap-5 rounded-2xl border border-slate-200 bg-white p-4 shadow-card">
                    <div class="relative flex h-32 w-32 shrink-0 items-center justify-center overflow-hidden rounded-xl bg-slate-100 text-xs text-slate-400">
                        <img v-if="g.image_url" :src="g.image_url" :alt="g.prompt" class="h-full w-full object-cover" />
                        <span v-else-if="g.image_expired">Image expired</span>
                        <span v-else-if="g.status === 'failed'">No image</span>
                        <span v-else class="flex flex-col items-center gap-2">
                            <span class="h-5 w-5 animate-spin rounded-full border-2 border-brand-200 border-t-brand-600"></span>
                            Working...
                        </span>
                    </div>
                    <div class="min-w-0 flex-1 py-1">
                        <div class="flex items-center gap-2">
                            <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold capitalize ring-1 ring-inset" :class="badge[g.status]" data-test="status">{{ g.status }}</span>
                            <span class="text-xs text-slate-500">{{ g.size }} &middot; {{ g.quality }}</span>
                        </div>
                        <p class="mt-2 line-clamp-2 font-medium text-slate-900" :title="g.prompt">{{ g.prompt }}</p>
                        <div class="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-sm text-slate-500">
                            <span>Estimated <span class="font-medium text-slate-700">${{ g.estimate }}</span></span>
                            <span v-if="g.actual">Actual <span class="font-semibold text-slate-900">${{ g.actual }}</span></span>
                            <span v-else-if="g.status === 'failed'">Charged <span class="font-medium text-slate-700">$0.000000000</span></span>
                        </div>
                        <p v-if="g.error" class="mt-2 text-sm text-red-600">{{ g.error }}</p>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
