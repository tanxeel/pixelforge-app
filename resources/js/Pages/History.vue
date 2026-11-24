<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, router } from '@inertiajs/vue3';
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
    queued: 'bg-gray-100 text-gray-700',
    processing: 'bg-blue-100 text-blue-700',
    succeeded: 'bg-green-100 text-green-700',
    failed: 'bg-red-100 text-red-700',
};
</script>

<template>
    <Head title="History" />

    <AuthenticatedLayout>
        <template #header>
            <div class="flex items-center justify-between">
                <h2 class="text-xl font-semibold leading-tight text-gray-800">History</h2>
                <div class="text-sm text-gray-600">Balance: <strong>${{ balance }}</strong></div>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-5xl space-y-4 sm:px-6 lg:px-8">
                <div v-if="!generations.length" class="rounded-lg bg-white p-6 text-gray-500 shadow-sm">Nothing yet. Generate your first image.</div>

                <div v-for="g in generations" :key="g.id" class="flex gap-4 rounded-lg bg-white p-4 shadow-sm">
                    <div class="flex h-32 w-32 shrink-0 items-center justify-center overflow-hidden rounded bg-gray-100 text-xs text-gray-400">
                        <img v-if="g.image_url" :src="g.image_url" :alt="g.prompt" class="h-full w-full object-cover" />
                        <span v-else-if="g.image_expired">Image expired</span>
                        <span v-else-if="g.status === 'failed'">No image</span>
                        <span v-else>Working...</span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="rounded px-2 py-0.5 text-xs font-medium" :class="badge[g.status]" data-test="status">{{ g.status }}</span>
                            <span class="text-xs text-gray-500">{{ g.size }} / {{ g.quality }}</span>
                        </div>
                        <p class="mt-1 truncate text-gray-800" :title="g.prompt">{{ g.prompt }}</p>
                        <div class="mt-2 text-sm text-gray-600">
                            <span>Estimated: ${{ g.estimate }}</span>
                            <span v-if="g.actual" class="ml-4">Actual: <strong>${{ g.actual }}</strong></span>
                            <span v-else-if="g.status === 'failed'" class="ml-4">Charged: $0.000000000</span>
                        </div>
                        <p v-if="g.error" class="mt-1 text-sm text-red-600">{{ g.error }}</p>
                    </div>
                </div>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
