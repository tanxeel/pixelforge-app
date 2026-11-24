<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import { Head, useForm, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({ pricing: Object, balance: String, sizes: Array, qualities: Array });

const form = useForm({ prompt: '', size: '1024x1024', quality: 'medium', refs: '' });

const refImages = computed(() =>
    form.refs.split('\n').map((s) => s.trim()).filter(Boolean).slice(0, 4),
);

// Integer nanodollars as BigInt, mirroring the server's exact arithmetic. Rates come from /v1/pricing, never hardcoded.
const nanoPerToken = (rate) => {
    const [whole, frac = ''] = String(rate).split('.');
    return BigInt(whole + frac.padEnd(3, '0').slice(0, 3)); // USD per 1M tokens x 1000 = nano per token
};

const estimate = computed(() => {
    const p = props.pricing;
    if (!p) return null;
    const t = p.tokens;
    const text = Math.ceil([...form.prompt].length / t.characters_per_text_token);
    const lo = Math.floor(text * (1 - t.text_token_variance));
    const hi = Math.ceil(text * (1 + t.text_token_variance));
    const images = refImages.value.length * t.per_reference_image;
    const out = t.output[form.quality][form.size];
    const r = t.reasoning[form.quality];
    const cost = (txt, reasoning) =>
        BigInt(txt) * nanoPerToken(p.rates.input_text_tokens) +
        BigInt(images) * nanoPerToken(p.rates.input_image_tokens) +
        BigInt(out) * nanoPerToken(p.rates.output_tokens) +
        BigInt(reasoning) * nanoPerToken(p.rates.reasoning_tokens);
    const markup = (n) => (n * 125n + 99n) / 100n;
    return {
        min: markup(cost(lo, r.min)),
        expected: markup(cost(text, Math.floor((r.min + r.max) / 2))),
        max: markup(cost(hi, r.max)),
    };
});

const usd = (n) => {
    const s = n.toString().padStart(10, '0');
    return '$' + s.slice(0, -9) + '.' + s.slice(-9);
};

const submit = () => {
    form
        .transform((d) => ({ prompt: d.prompt, size: d.size, quality: d.quality, reference_images: refImages.value }))
        .post(route('generate.store'));
};

const buy = (dollars) => router.post(route('credits.buy'), { dollars }, { preserveScroll: true });
</script>

<template>
    <Head title="Generate" />

    <AuthenticatedLayout>
        <template #header>
            <h2 class="text-xl font-semibold leading-tight text-gray-800">Generate an image</h2>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-3xl space-y-6 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between rounded-lg bg-white p-4 shadow-sm">
                    <div>
                        <div class="text-sm text-gray-500">Credit balance</div>
                        <div class="text-lg font-semibold" data-test="balance">${{ balance }}</div>
                    </div>
                    <div class="flex gap-2">
                        <button v-for="d in [5, 10, 25]" :key="d" type="button" class="rounded border px-3 py-1 text-sm hover:bg-gray-50" @click="buy(d)">
                            Buy ${{ d }}
                        </button>
                    </div>
                </div>

                <form class="space-y-4 rounded-lg bg-white p-6 shadow-sm" @submit.prevent="submit">
                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="prompt">Prompt</label>
                        <textarea id="prompt" v-model="form.prompt" rows="4" maxlength="4000" class="mt-1 w-full rounded border-gray-300" />
                        <div v-if="form.errors.prompt" class="mt-1 text-sm text-red-600">{{ form.errors.prompt }}</div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium text-gray-700" for="size">Size</label>
                            <select id="size" v-model="form.size" class="mt-1 w-full rounded border-gray-300">
                                <option v-for="s in sizes" :key="s" :value="s">{{ s }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700" for="quality">Quality</label>
                            <select id="quality" v-model="form.quality" class="mt-1 w-full rounded border-gray-300">
                                <option v-for="q in qualities" :key="q" :value="q">{{ q }}</option>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-gray-700" for="refs">Reference image URLs (optional, one per line, max 4)</label>
                        <textarea id="refs" v-model="form.refs" rows="2" class="mt-1 w-full rounded border-gray-300" />
                        <div v-if="form.errors['reference_images'] || form.errors['reference_images.0']" class="mt-1 text-sm text-red-600">
                            Reference images must be valid http(s) URLs (max 4).
                        </div>
                    </div>

                    <div v-if="estimate" class="rounded bg-gray-50 p-3 text-sm text-gray-700" data-test="estimate">
                        <div>
                            Estimated cost: <strong>{{ usd(estimate.expected) }}</strong>
                            <span class="text-gray-500"> (likely between {{ usd(estimate.min) }} and {{ usd(estimate.max) }})</span>
                        </div>
                        <div class="mt-1 text-gray-500">
                            Includes a 25% markup. It is an estimate because the exact text and reasoning tokens are only known once the
                            image is done. We hold up to {{ usd(estimate.max) }} while it runs and refund the difference.
                        </div>
                    </div>
                    <div v-else class="rounded bg-yellow-50 p-3 text-sm text-yellow-800">Pricing is unavailable right now, try again shortly.</div>

                    <button type="submit" :disabled="form.processing || !form.prompt || !estimate" class="rounded bg-gray-800 px-4 py-2 text-white disabled:opacity-50">
                        {{ form.processing ? 'Submitting...' : 'Generate' }}
                    </button>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
