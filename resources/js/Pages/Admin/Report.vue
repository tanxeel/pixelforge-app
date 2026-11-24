<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps({ perDay: Array, perDayUser: Array, totals: Object, reconciliation: Array, reconciliationError: String });
</script>

<template>
    <Head title="Admin report" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Spend report (UTC days)</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-5xl space-y-8 sm:px-6 lg:px-8">
                <div class="grid grid-cols-3 gap-4">
                    <div class="rounded-lg bg-white p-4 shadow-sm"><div class="text-sm text-gray-500">Credits spent by users</div><div class="text-lg font-semibold">${{ totals.credits_spent }}</div></div>
                    <div class="rounded-lg bg-white p-4 shadow-sm"><div class="text-sm text-gray-500">Provider cost</div><div class="text-lg font-semibold">${{ totals.provider_cost }}</div></div>
                    <div class="rounded-lg bg-white p-4 shadow-sm"><div class="text-sm text-gray-500">Profit</div><div class="text-lg font-semibold">${{ totals.profit }}</div></div>
                </div>

                <section>
                    <h3 class="mb-2 font-semibold">Per day</h3>
                    <table class="w-full overflow-hidden rounded-lg bg-white text-sm shadow-sm">
                        <thead class="bg-gray-50 text-left"><tr><th class="p-2">Day</th><th class="p-2">Generations</th><th class="p-2">Credits spent</th><th class="p-2">Provider cost</th><th class="p-2">Profit</th></tr></thead>
                        <tbody>
                            <tr v-for="r in perDay" :key="r.day" class="border-t"><td class="p-2">{{ r.day }}</td><td class="p-2">{{ r.generations }}</td><td class="p-2">${{ r.credits_spent }}</td><td class="p-2">${{ r.provider_cost }}</td><td class="p-2">${{ r.profit }}</td></tr>
                        </tbody>
                    </table>
                </section>

                <section>
                    <h3 class="mb-2 font-semibold">Per day and user</h3>
                    <table class="w-full overflow-hidden rounded-lg bg-white text-sm shadow-sm">
                        <thead class="bg-gray-50 text-left"><tr><th class="p-2">Day</th><th class="p-2">User</th><th class="p-2">Generations</th><th class="p-2">Credits spent</th><th class="p-2">Provider cost</th><th class="p-2">Profit</th></tr></thead>
                        <tbody>
                            <tr v-for="r in perDayUser" :key="r.day + r.user" class="border-t"><td class="p-2">{{ r.day }}</td><td class="p-2">{{ r.user }}</td><td class="p-2">{{ r.generations }}</td><td class="p-2">${{ r.credits_spent }}</td><td class="p-2">${{ r.provider_cost }}</td><td class="p-2">${{ r.profit }}</td></tr>
                        </tbody>
                    </table>
                </section>

                <section>
                    <h3 class="mb-2 font-semibold">Provider cost check against Pixelforge GET /v1/usage</h3>
                    <div v-if="reconciliationError" class="rounded bg-yellow-50 p-3 text-sm text-yellow-800">{{ reconciliationError }}</div>
                    <table v-else-if="reconciliation && reconciliation.length" class="w-full overflow-hidden rounded-lg bg-white text-sm shadow-sm">
                        <thead class="bg-gray-50 text-left"><tr><th class="p-2">Day</th><th class="p-2">Our records</th><th class="p-2">Pixelforge</th><th class="p-2">Match</th></tr></thead>
                        <tbody>
                            <tr v-for="r in reconciliation" :key="r.day" class="border-t"><td class="p-2">{{ r.day }}</td><td class="p-2">${{ r.ours }}</td><td class="p-2">${{ r.pixelforge }}</td><td class="p-2">{{ r.matches ? 'yes' : 'NO' }}</td></tr>
                        </tbody>
                    </table>
                    <p v-else class="text-sm text-gray-500">No usage yet.</p>
                </section>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
