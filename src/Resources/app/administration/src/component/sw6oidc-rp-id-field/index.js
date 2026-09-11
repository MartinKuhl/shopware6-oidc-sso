import template from './sw6oidc-rp-id-field.html.twig';

const { Component } = Shopware;

/**
 * Custom config.xml component backing the Passkey "Relying Party ID (domain)"
 * field: fetches and shows this shop's actual current host as the field's
 * placeholder, instead of only generic descriptive text — registered via
 * <component name="sw6oidc-rp-id-field"> in config.xml, backed by
 * Controller/Api/PasskeyDefaultHostController.php.
 *
 * VERIFICATION NEEDED: the exact prop contract `sw-system-config` passes to a
 * custom config.xml <component> isn't fully documented — this accepts the
 * commonly-used value/label/helpText/placeholder prop names and emits
 * `update:value`, matching the standard Shopware form-field convention.
 * Adjust prop names here if the real render call passes something different.
 */
Component.register('sw6oidc-rp-id-field', {
    template,

    inject: ['httpClient'],

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

    data() {
        return {
            resolvedHost: null,
        };
    },

    computed: {
        effectivePlaceholder() {
            if (this.resolvedHost) {
                return this.$tc('sw6oidc.passkeySettings.rpIdPlaceholderWithHost', 0, { host: this.resolvedHost });
            }

            return this.placeholder;
        },
    },

    created() {
        this.fetchDefaultHost();
    },

    methods: {
        async fetchDefaultHost() {
            try {
                const response = await this.httpClient.get('/_action/sw6oidc/passkey/default-rp-host');
                this.resolvedHost = response.data?.host || null;
            } catch (error) {
                // Non-fatal: the field just falls back to the generic
                // placeholder text authored in config.xml.
                this.resolvedHost = null;
            }
        },

        onInput(newValue) {
            this.$emit('update:value', newValue);
        },
    },
});
