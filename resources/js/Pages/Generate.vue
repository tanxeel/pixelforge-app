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
            <div>
                <h2 class="text-2xl font-bold tracking-tight text-slate-900">Generate an image</h2>
                <p class="mt-1 text-sm text-slate-500">Describe what you want. You see the cost before anything is sent.</p>
            </div>
        </template>

        <div class="py-8">
            <div class="mx-auto max-w-3xl space-y-6 px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between rounded-2xl border border-slate-200 bg-white p-5 shadow-card">
                    <div>
                        <div class="text-xs font-medium uppercase tracking-wider text-slate-500">Credit balance</div>
                        <div class="mt-1 text-2xl font-bold tracking-tight text-slate-900" data-test="balance">${{ balance }}</div>
                    </div>
                    <div class="flex gap-2">
                        <button v-for="d in [5, 10, 25]" :key="d" type="button" class="rounded-lg border border-slate-200 bg-white px-3.5 py-2 text-sm font-semibold text-slate-700 shadow-sm transition hover:border-brand-300 hover:bg-brand-50 hover:text-brand-700" @click="buy(d)">
                            + ${{ d }}
                        </button>
                    </div>
                </div>

                <form class="space-y-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-card sm:p-8" @submit.prevent="submit">
                    <div>
                        <label class="block text-sm font-semibold text-slate-800" for="prompt">Prompt</label>
                        <textarea id="prompt" v-model="form.prompt" rows="4" maxlength="4000" placeholder="A lighthouse on a cliff at sunrise, oil painting" class="mt-2 w-full rounded-xl border-slate-300 px-4 py-3 text-sm shadow-sm placeholder:text-slate-400 focus:border-brand-500 focus:ring-brand-500" />
                        <div class="mt-1 flex justify-between text-xs">
                            <span v-if="form.errors.prompt" class="text-red-600">{{ form.errors.prompt }}</span>
                            <span v-else />
                            <span class="text-slate-400">{{ [...form.prompt].length }} / 4000</span>
                        </div>
                    </div>

                    <div class="grid gap-6 sm:grid-cols-2">
                        <div>
                            <label class="block text-sm font-semibold text-slate-800">Size</label>
                            <div class="mt-2 grid grid-cols-3 gap-2">
                                <button v-for="s in sizes" :key="s" type="button" @click="form.size = s" :class="form.size === s ? 'border-brand-500 bg-brand-50 text-brand-700 ring-1 ring-brand-500' : 'border-slate-200 text-slate-600 hover:border-slate-300'" class="rounded-lg border px-2 py-2 text-center text-xs font-semibold transition">
                                    {{ s }}
                                </button>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-slate-800">Quality</label>
                            <div class="mt-2 grid grid-cols-3 gap-2">
                                <button v-for="q in qualities" :key="q" type="button" @click="form.quality = q" :class="form.quality === q ? 'border-brand-500 bg-brand-50 text-brand-700 ring-1 ring-brand-500' : 'border-slate-200 text-slate-600 hover:border-slate-300'" class="rounded-lg border px-2 py-2 text-center text-xs font-semibold capitalize transition">
                                    {{ q }}
                                </button>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-sm font-semibold text-slate-800" for="refs">Reference images <span class="font-normal text-slate-400">(optional, one URL per line, max 4)</span></label>
                        <textarea id="refs" v-model="form.refs" rows="2" placeholder="https://example.com/style.png" class="mt-2 w-full rounded-xl border-slate-300 px-4 py-3 text-sm shadow-sm placeholder:text-slate-400 focus:border-brand-500 focus:ring-brand-500" />
                        <div v-if="form.errors['reference_images'] || form.errors['reference_images.0']" class="mt-1 text-sm text-red-600">
                            Reference images must be valid http(s) URLs (max 4).
                        </div>
                    </div>

                    <div v-if="estimate" class="rounded-xl border border-brand-100 bg-brand-50/60 p-4 text-sm" data-test="estimate">
                        <div class="flex items-baseline justify-between gap-4">
                            <div class="text-slate-700">Estimated cost: <strong class="text-lg text-slate-900">{{ usd(estimate.expected) }}</strong></div>
                            <div class="text-xs text-slate-500">likely {{ usd(estimate.min) }} to {{ usd(estimate.max) }}</div>
                        </div>
                        <div class="mt-2 text-xs leading-relaxed text-slate-500">
                            Includes a 25% markup. It is an estimate because the exact text and reasoning tokens are only known once the
                            image is done. We hold up to {{ usd(estimate.max) }} while it runs and refund the difference.
                        </div>
                    </div>
                    <div v-else class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">Pricing is unavailable right now, try again shortly.</div>

                    <button type="submit" :disabled="form.processing || !form.prompt || !estimate" class="w-full rounded-xl bg-gradient-to-r from-brand-600 to-brand-700 px-5 py-3 text-sm font-semibold text-white shadow-sm transition hover:from-brand-700 hover:to-brand-800 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50">
                        {{ form.processing ? 'Submitting...' : 'Generate image' }}
                    </button>
                </form>
            </div>
        </div>
    </AuthenticatedLayout>
</template>
