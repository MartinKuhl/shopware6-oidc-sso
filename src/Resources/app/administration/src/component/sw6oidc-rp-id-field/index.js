import template from './sw6oidc-rp-id-field.html.twig';

const { Component } = Shopware;

/**
 * Custom config.xml component backing the Passkey "Relying Party ID (domain)"
 * field: shows this shop's actual current host as the field's placeholder
 * instead of only generic descriptive text — registered via
 * <component name="sw6oidc-rp-id-field"> in config.xml.
 *
 * The host is just `window.location.hostname` — the Administration itself is
 * already being loaded from this shop's real domain, so no backend round
 * trip (or guessing at APP_URL vs. the actual Storefront domain, which can
 * differ in multi-sales-channel/reverse-proxy setups) is needed to show it.
 *
 * VERIFICATION NEEDED: the exact prop contract `sw-system-config` passes to a
 * custom config.xml <component> isn't fully documented — this accepts the
 * commonly-used value/label/helpText/placeholder prop names and emits
 * `update:value`, matching the standard Shopware form-field convention.
 * Adjust prop names here if the real render call passes something different.
 *
 * effectivePlaceholder() resolves the translated template with $tc() but
 * does NOT rely on $tc's own interpolation for the {host} token - passing
 * it as $tc's third (values) argument silently produced an empty string in
 * testing instead of the host. Substituting it into the resolved string
 * manually with String.replace sidesteps that.
 */
Component.register('sw6oidc-rp-id-field', {
    template,

    props: {
        value: {
            type: String,
            required: false,
            default: null,
        },
        label: {
            type: String,
            required: false,
            default: null,
        },
        helpText: {
            type: String,
            required: false,
            default: null,
        },
        placeholder: {
            type: String,
            required: false,
            default: null,
        },
    },

    computed: {
        effectivePlaceholder() {
            const host = window.location.hostname;

            if (!host) {
                return this.placeholder;
            }

            const translationKey = 'sw6oidc.passkeySettings.rpIdPlaceholderWithHost';
            const translated = this.$tc(translationKey);

            // Defensive fallback: if the admin snippet bundle is stale/hasn't
            // been rebuilt, $tc() returns the raw key instead of throwing —
            // never show that to the user, fall back to a plain hardcoded
            // string instead.
            if (!translated || translated === translationKey || !translated.includes('{host}')) {
                return `Defaults to: ${host}`;
            }

            return translated.replace('{host}', host);
        },
    },

    methods: {
        onInput(newValue) {
            this.$emit('update:value', newValue);
        },
    },
});
