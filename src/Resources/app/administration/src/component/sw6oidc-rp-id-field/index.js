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
            if (window.location.hostname) {
                return this.$tc('sw6oidc.passkeySettings.rpIdPlaceholderWithHost', 0, { host: window.location.hostname });
            }

            return this.placeholder;
        },
    },

    methods: {
        onInput(newValue) {
            this.$emit('update:value', newValue);
        },
    },
});
